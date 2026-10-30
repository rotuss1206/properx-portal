<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Member\Overview;
use App\Models\LicenseDocument;
use App\Services\Documents\DocumentVault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "Upload a document" in the member area: a plain multipart form (no Livewire temporary files),
 * stored and encrypted by DocumentVault like Nova uploads; staff review it afterwards.
 * Individual members only (agency people hold NPN and state only). DocumentObserver audits the
 * upload. With an XHR request (the progress bar in public/portal/upload.js) it answers JSON.
 */
class LicenseDocumentUploadController extends Controller
{
    public function store(Request $request, DocumentVault $vault): RedirectResponse|JsonResponse
    {
        abort_unless(Overview::for($request->user())->canUpload(), 403);

        $validated = $request->validate([
            'file' => ['required', ...DocumentVault::uploadRules()],
            'document_type' => ['required', Rule::in(array_keys(config('portal.member_area.license_document_types')))],
        ], [
            'file.max' => 'The file may not be larger than 10 MB.',
            'file.mimes' => 'Upload a PDF, JPG or PNG file.',
            'file.mimetypes' => 'Upload a PDF, JPG or PNG file.',
        ]);

        $stored = $vault->store($validated['file'], LicenseDocument::DIRECTORY, encrypt: true);

        (new LicenseDocument)->forceFill([
            'user_id' => $request->user()->getKey(),
            'stored_filename' => $stored->path,
            'original_filename' => $stored->originalName,
            'mime_type' => $stored->mimeType,
            'size' => $stored->size,
            'document_type' => $validated['document_type'],
            'uploaded_at' => now(),
        ])->save();

        $message = 'Document uploaded. ProPerx will review it shortly.';

        return $request->expectsJson()
            ? response()->json(['message' => $message, 'redirect' => route('member.dashboard')], 201)
            : redirect()->route('member.dashboard')->with('status', $message);
    }
}
