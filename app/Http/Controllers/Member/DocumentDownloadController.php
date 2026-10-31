<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\LibraryDocument;
use App\Models\LicenseDocument;
use App\Models\TaxDocument;
use App\Services\DocumentDownloader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Download buttons of the member area: checks the policy (own documents only; license documents
 * and the library closed while the membership is restricted; published library documents only), then hands over the short-lived signed route,
 * which checks again, streams and audits the download.
 */
class DocumentDownloadController extends Controller
{
    private const TYPES = [
        'license' => LicenseDocument::class,
        'tax' => TaxDocument::class,
        'library' => LibraryDocument::class,
    ];

    public function __invoke(string $type, int $document, DocumentDownloader $downloader): RedirectResponse
    {
        $model = self::TYPES[$type]::query()->findOrFail($document);

        Gate::authorize('view', $model);

        return $downloader->link($model);
    }
}
