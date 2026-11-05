<?php

namespace App\Http\Controllers\Member;

use App\Enums\LicenseVerification;
use App\Enums\VerificationStatus;
use App\Http\Controllers\Controller;
use App\Member\LicenseDocumentList;
use App\Member\MemberMenu;
use App\Member\Overview;
use App\Models\LicenseDocument;
use App\Services\DocumentDownloader;
use App\Services\Documents\DocumentVault;
use App\Services\Documents\StorageHealth;
use App\Support\UsStates;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * License documents in the member area: the list with one document's details, the upload page and
 * withdrawing a pending document. Plain forms (no Livewire temporary files); the files go through
 * DocumentVault like Nova uploads, encrypted, and leave only through the signed download route.
 * DocumentObserver audits uploads and deletions (ids only). Who sees the page: MemberMenu::visible
 * (no agency people, closed while the membership is restricted). With an XHR request (the progress
 * bars in public/portal/upload-page.js and upload.js) store() answers JSON.
 */
class LicenseDocumentController extends Controller
{
    public const WRONG_FORMAT = 'Unsupported format — upload a PDF, JPG or PNG.';

    public const TOO_LARGE = 'Too large — the file may not be larger than 10 MB.';

    private const STORAGE_DOWN = 'Document storage is temporarily unavailable, so nothing was saved. Please try again in a few minutes.';

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless(MemberMenu::visible($user, 'license-documents'), Response::HTTP_NOT_FOUND);

        $all = LicenseDocumentList::for($user);
        $filter = array_key_exists((string) $request->query('status'), LicenseDocumentList::FILTERS) ? (string) $request->query('status') : 'all';
        $rows = $filter === 'all' ? $all : $all->where('statusKey', $filter)->values();

        // Only the person's own documents can be opened: any other id is simply not there.
        $selected = $request->query('document') === null ? $rows->first() : $all->firstWhere('id', $request->integer('document'));
        abort_if($request->query('document') !== null && $selected === null, Response::HTTP_NOT_FOUND);

        return response()->view('member.license-documents.index', [
            'page' => 'license-documents',
            'title' => MemberMenu::label('license-documents'),
            'hasDocuments' => $all->isNotEmpty(),
            'rows' => $rows,
            'filter' => $filter,
            'selected' => $selected,
            'confirmWithdraw' => $selected !== null && $selected['canWithdraw'] && $request->boolean('withdraw'),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function create(Request $request): View
    {
        abort_unless(Overview::for($request->user())->canUpload(), Response::HTTP_NOT_FOUND);

        return view('member.license-documents.upload', [
            'page' => 'license-documents',
            'title' => 'Upload document',
            'documentTypes' => config('portal.member_area.license_document_types'),
            'licenseTypes' => LicenseDocument::LICENSE_TYPES,
            'states' => UsStates::ALL,
            'member' => $request->user()->memberProfile,
            // Set when the request was too large to reach a session (bootstrap/app.php).
            'tooLarge' => $request->boolean('too_large'),
        ]);
    }

    public function store(Request $request, DocumentVault $vault, StorageHealth $storage): RedirectResponse|JsonResponse
    {
        abort_unless(Overview::for($request->user())->canUpload(), Response::HTTP_FORBIDDEN);

        $type = $request->input('document_type');
        $details = $this->validatedDetails($request);

        // Each file becomes its own document with the same details. public/portal/upload-page.js sends
        // one at a time, for a progress bar per file; without JavaScript they all arrive here at once.
        $files = array_values(array_filter(Arr::wrap($request->file('file'))));

        if ($files === []) {
            throw ValidationException::withMessages(['file' => 'Choose a file to upload.']);
        }

        $saved = null;
        $rejected = [];

        foreach ($files as $file) {
            $problem = $this->fileProblem($file);

            if ($problem !== null) {
                $rejected[] = count($files) > 1 ? $file->getClientOriginalName().': '.$problem : $problem;

                continue;
            }

            // A malware scan belongs here, before storing, once portal.member_area.malware_scan is on.
            try {
                $storage->ensureAvailable('license');
                $stored = $vault->store($file, LicenseDocument::DIRECTORY, encrypt: true);
            } catch (InvalidArgumentException) {
                $rejected[] = count($files) > 1 ? $file->getClientOriginalName().': '.self::WRONG_FORMAT : self::WRONG_FORMAT;

                continue;
            } catch (Throwable $e) {
                Log::warning('License document upload failed', ['user_id' => $request->user()->getKey(), 'error' => $e::class]);

                return $request->expectsJson()
                    ? response()->json(['message' => self::STORAGE_DOWN], Response::HTTP_SERVICE_UNAVAILABLE)
                    : back()->withInput($request->except('file'))->withErrors(['file' => self::STORAGE_DOWN]);
            }

            $saved = (new LicenseDocument)->forceFill([
                ...$details,
                'user_id' => $request->user()->getKey(),
                'stored_filename' => $stored->path,
                'original_filename' => $stored->originalName,
                'mime_type' => $stored->mimeType,
                'size' => $stored->size,
                'document_type' => $type,
                'uploaded_at' => now(),
                'verification_status' => VerificationStatus::Pending,
            ]);
            $saved->save();
        }

        if ($saved === null) {
            throw ValidationException::withMessages(['file' => $rejected]);
        }

        // A new license waits for staff, and so does the member's license status (MemberProfileObserver
        // audits it). A supporting file ("other") leaves that status alone.
        if (in_array($type, LicenseDocument::LICENSE_TYPES, true)) {
            $request->user()->memberProfile?->update(['license_verification' => LicenseVerification::PendingReview]);
        }

        $target = $request->input('from') === 'page'
            ? route('member.license-documents', count($files) === 1 ? ['document' => $saved->getKey()] : [])
            : route('member.dashboard');
        $message = 'Upload complete. ProPerX will review your documents shortly.';

        // The script follows "redirect" itself; the page there shows the same notice.
        $request->session()->flash('status', $message);

        if ($rejected !== []) {
            $request->session()->flash('document_error', 'Not saved: '.implode(' ', $rejected));
        }

        return $request->expectsJson()
            ? response()->json(['message' => $message, 'redirect' => $target], Response::HTTP_CREATED)
            : redirect($target);
    }

    /**
     * What is kept with the documents; the profile is not overwritten. The upload page asks for it (a
     * resident license's state is the profile's, another state's license asks for its own); the
     * dashboard's short form takes it from the profile; a supporting file has none.
     *
     * @return array<string, mixed>
     */
    private function validatedDetails(Request $request): array
    {
        $profile = $request->user()->memberProfile;
        $type = $request->input('document_type');
        $isLicense = in_array($type, LicenseDocument::LICENSE_TYPES, true);
        $fromPage = $request->input('from') === 'page';

        $validated = $request->validate([
            'document_type' => ['required', Rule::in(array_keys(config('portal.member_area.license_document_types')))],
            ...($fromPage && $isLicense ? [
                'license_number' => ['required', 'string', 'max:50'],
                'license_issued_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
                'license_expires_at' => ['required', 'date_format:Y-m-d', 'after_or_equal:license_issued_at'],
            ] : []),
            ...($fromPage && $type === 'non_resident' ? [
                'license_state' => ['required', Rule::in(array_keys(UsStates::ALL))],
            ] : []),
        ], [
            'document_type.required' => 'Choose the document type.',
            'document_type.in' => 'Choose the document type from the list.',
            'license_number.required' => 'Enter the license number.',
            'license_number.max' => 'The license number may not be longer than 50 characters.',
            'license_issued_at.required' => 'Enter the date this license was issued.',
            'license_issued_at.date_format' => 'Enter the issue date as a full date, e.g. 10/01/2024.',
            'license_issued_at.before_or_equal' => 'The issue date cannot be in the future.',
            'license_expires_at.required' => 'Enter the date this license expires.',
            'license_expires_at.date_format' => 'Enter the expiration date as a full date, e.g. 09/30/2027.',
            'license_expires_at.after_or_equal' => 'The expiration date cannot be before the issue date.',
            'license_state.required' => 'Choose the state of this license.',
            'license_state.in' => 'Choose the state from the list.',
        ]);

        if (! $isLicense) {
            return [];
        }

        return $fromPage
            ? [
                'license_number' => $validated['license_number'],
                'license_state' => $validated['license_state'] ?? $profile?->license_state,
                'license_issued_at' => $validated['license_issued_at'],
                'license_expires_at' => $validated['license_expires_at'],
            ]
            : ['license_number' => $profile?->license_number, 'license_state' => $profile?->license_state, 'license_expires_at' => $profile?->license_expires_at];
    }

    /**
     * Why this file cannot be kept, in words for people; type by content, whatever it is called.
     */
    private function fileProblem(mixed $file): ?string
    {
        $validator = Validator::make(['file' => $file], ['file' => ['required', ...DocumentVault::uploadRules()]], [
            'file.uploaded' => 'The file could not be uploaded. It may not be larger than 10 MB.',
            'file.file' => 'Choose a file to upload.',
            'file.max' => self::TOO_LARGE,
            'file.mimes' => self::WRONG_FORMAT,
            'file.mimetypes' => self::WRONG_FORMAT,
        ]);

        return $validator->fails() ? $validator->errors()->first('file') : null;
    }

    /**
     * Shows the file in the browser (Content-Disposition inline) through the server: the same
     * policy as a download, and audited. Too large or another type: not found; Download remains.
     */
    public function preview(Request $request, LicenseDocument $document, DocumentDownloader $downloader): StreamedResponse
    {
        abort_unless(MemberMenu::visible($request->user(), 'license-documents'), Response::HTTP_NOT_FOUND);
        Gate::authorize('view', $document);
        abort_if(LicenseDocumentList::previewKind($document) === null, Response::HTTP_NOT_FOUND);

        return $downloader->stream($document, inline: true);
    }

    /**
     * Withdraws a document still pending review; the policy refuses anyone else's and any reviewed one.
     */
    public function destroy(Request $request, LicenseDocument $document, DocumentVault $vault, StorageHealth $storage): RedirectResponse
    {
        abort_unless(MemberMenu::visible($request->user(), 'license-documents'), Response::HTTP_NOT_FOUND);
        Gate::authorize('delete', $document);

        try {
            $storage->ensureAvailable('license', $document->getKey());
            $vault->delete($document->stored_filename);
        } catch (Throwable $e) {
            Log::warning('License document withdrawal failed', ['id' => $document->getKey(), 'error' => $e::class]);

            return redirect()->route('member.license-documents', ['document' => $document->getKey()])
                ->with('document_error', 'Document storage is temporarily unavailable, so the document was not withdrawn. Please try again in a few minutes.');
        }

        $document->delete();

        return redirect()->route('member.license-documents')->with('status', 'Document withdrawn.');
    }
}
