<?php

namespace App\Http\Controllers;

use App\Contracts\DownloadableDocument;
use App\Models\LibraryDocument;
use App\Models\LicenseDocument;
use App\Models\TaxDocument;
use App\Services\DocumentDownloader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Target of the short-lived signed download URLs. The signature alone is not enough:
 * the caller must be signed in and allowed to view the document.
 */
class DocumentDownloadController extends Controller
{
    /**
     * @var array<string, class-string<DownloadableDocument>>
     */
    private const TYPES = [
        'license' => LicenseDocument::class,
        'tax' => TaxDocument::class,
        'library' => LibraryDocument::class,
    ];

    public function __invoke(Request $request, string $type, int $document, DocumentDownloader $downloader): StreamedResponse
    {
        $model = (self::TYPES[$type] ?? abort(404))::query()->findOrFail($document);

        Gate::authorize('view', $model);

        $response = $downloader->stream($model);

        // Track when members retrieve their own tax documents (Spec 3.1).
        if ($model instanceof TaxDocument && $request->user()->is($model->user)) {
            $model->forceFill(['downloaded_at' => now()])->save();
        }

        return $response;
    }
}
