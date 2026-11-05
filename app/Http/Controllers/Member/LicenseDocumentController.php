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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
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
 * bar in public/portal/upload.js) store() answers JSON.
 */
class LicenseDocumentController extends Controller
{
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

        $profile = $request->user()->memberProfile;
        $fromPage = $request->input('from') === 'page';
        $isLicense = in_array($request->input('document_type'), LicenseDocument::LICENSE_TYPES, true);
        $needed = $isLicense ? 'required' : 'nullable';

        $validated = $request->validate([
            'file' => ['required', ...DocumentVault::uploadRules()],
            'document_type' => ['required', Rule::in(array_keys(config('portal.member_area.license_document_types')))],
            // The dashboard's short form asks for the type only; see $details below.
            ...($fromPage ? [
                'license_number' => ['nullable', 'string', 'max:50'],
                'license_state' => [$needed, Rule::in(array_keys(UsStates::ALL))],
                'license_expires_at' => [$needed, 'date_format:Y-m-d'],
            ] : []),
        ], [
            'license_state.required' => 'Choose the state of this license.',
            'license_state.in' => 'Choose the state from the list.',
            'license_expires_at.required' => 'Enter the date this license expires.',
            'license_expires_at.date_format' => 'Enter the expiration date as a full date, e.g. 09/30/2027.',
            'license_number.max' => 'The license number may not be longer than 50 characters.',
            'file.required' => 'Choose a file to upload.',
            'file.uploaded' => 'The file could not be uploaded. It may not be larger than 10 MB.',
            'file.file' => 'Choose a file to upload.',
            'file.max' => 'The file may not be larger than 10 MB.',
            'file.mimes' => 'Upload a PDF, JPG or PNG file.',
            'file.mimetypes' => 'Upload a PDF, JPG or PNG file.',
            'document_type.required' => 'Choose the document type.',
            'document_type.in' => 'Choose the document type from the list.',
        ]);

        // A malware scan belongs here, before storing, once portal.member_area.malware_scan is on.
        try {
            $storage->ensureAvailable('license');
            $stored = $vault->store($validated['file'], LicenseDocument::DIRECTORY, encrypt: true);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['file' => 'Upload a PDF, JPG or PNG file.']);
        } catch (Throwable $e) {
            Log::warning('License document upload failed', ['user_id' => $request->user()->getKey(), 'error' => $e::class]);

            return $request->expectsJson()
                ? response()->json(['message' => self::STORAGE_DOWN], Response::HTTP_SERVICE_UNAVAILABLE)
                : back()->withInput($request->only('document_type'))->withErrors(['file' => self::STORAGE_DOWN]);
        }

        // Kept with the document; the profile is not overwritten. A form without these fields
        // takes them from the profile, and a supporting file has none unless they were given.
        $details = $fromPage
            ? ['license_number' => $validated['license_number'] ?? null, 'license_state' => $validated['license_state'] ?? null, 'license_expires_at' => $validated['license_expires_at'] ?? null]
            : ($isLicense ? ['license_number' => $profile?->license_number, 'license_state' => $profile?->license_state, 'license_expires_at' => $profile?->license_expires_at] : []);

        $document = (new LicenseDocument)->forceFill([
            ...$details,
            'user_id' => $request->user()->getKey(),
            'stored_filename' => $stored->path,
            'original_filename' => $stored->originalName,
            'mime_type' => $stored->mimeType,
            'size' => $stored->size,
            'document_type' => $validated['document_type'],
            'uploaded_at' => now(),
            'verification_status' => VerificationStatus::Pending,
        ]);
        $document->save();

        // A new license waits for staff, and so does the member's license status (MemberProfileObserver
        // audits it). A supporting file ("other") leaves that status alone.
        if ($isLicense) {
            $profile?->update(['license_verification' => LicenseVerification::PendingReview]);
        }

        $target = $request->input('from') === 'page'
            ? route('member.license-documents', ['document' => $document->getKey()])
            : route('member.dashboard');
        $message = 'Document uploaded. ProPerX will review it shortly.';

        // The progress bar's script follows "redirect" itself; the page there shows the same notice.
        $request->session()->flash('status', $message);

        return $request->expectsJson()
            ? response()->json(['message' => $message, 'redirect' => $target], Response::HTTP_CREATED)
            : redirect($target);
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
