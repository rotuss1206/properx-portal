<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Member\MemberMenu;
use App\Member\MemberSearch;
use App\Models\LibraryDocument;
use App\Services\DocumentDownloader;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The compliance library in the member area: published documents with category chips and search,
 * a details page with Download and a preview. Who may: MemberMenu::visible, so closed while the
 * membership is restricted, open to agency people; drafts answer 404 here. As in the header search,
 * the search words go by POST into the session, never into a URL or a log.
 */
class LibraryController extends Controller
{
    private const SEARCH_KEY = 'member.library.search';

    private const PER_PAGE = 20;

    public function index(Request $request): View
    {
        $this->authorizePage($request);

        $search = (string) $request->session()->get(self::SEARCH_KEY, '');
        $words = MemberSearch::words($search);
        $category = in_array($request->query('category'), LibraryDocument::CATEGORIES, true) ? (string) $request->query('category') : 'all';

        $documents = LibraryDocument::query()
            ->published()
            ->when($category !== 'all', fn ($query) => $query->where('category', $category))
            ->matching($words)
            ->latest('uploaded_at')
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('member.library.index', [
            'page' => 'library',
            'title' => MemberMenu::label('library'),
            'documents' => $documents,
            'category' => $category,
            'categories' => ['all' => 'All documents'] + array_combine(LibraryDocument::CATEGORIES, LibraryDocument::CATEGORIES),
            'search' => $search,
            'filtered' => $category !== 'all' || $words !== [],
        ]);
    }

    public function search(Request $request): RedirectResponse
    {
        $this->authorizePage($request);

        $search = trim((string) $request->input('q'));

        if ($search === '') {
            $request->session()->forget(self::SEARCH_KEY);
        } else {
            $request->session()->put(self::SEARCH_KEY, mb_substr($search, 0, MemberSearch::MAX_LENGTH));
        }

        return redirect()->route('member.library', array_filter(['category' => $request->input('category')]));
    }

    public function show(Request $request, int $document): View
    {
        $this->authorizePage($request);

        $model = $this->published($document);
        $mime = $model->downloadMimeType();

        return view('member.library.show', [
            'page' => 'library',
            'title' => $model->title,
            'document' => $model,
            'format' => ['application/pdf' => 'PDF', 'image/jpeg' => 'JPG', 'image/png' => 'PNG'][$mime] ?? 'File',
            'preview' => $this->previewKind($mime),
            'previewUrl' => route('member.library.preview', $model),
            'downloadUrl' => route('member.documents.download', ['type' => 'library', 'document' => $model->getKey()]),
        ]);
    }

    /**
     * Shows the file in the browser through the server: the same policy as a download, and audited.
     * Library files are streamed, not decrypted in memory, so there is no size limit as for license documents.
     */
    public function preview(Request $request, int $document, DocumentDownloader $downloader): StreamedResponse
    {
        $this->authorizePage($request);

        $model = $this->published($document);
        abort_if($this->previewKind($model->downloadMimeType()) === null, Response::HTTP_NOT_FOUND);

        return $downloader->stream($model, inline: true);
    }

    private function authorizePage(Request $request): void
    {
        abort_unless(MemberMenu::visible($request->user(), 'library'), Response::HTTP_NOT_FOUND);
    }

    private function published(int $id): LibraryDocument
    {
        $document = LibraryDocument::query()->published()->findOrFail($id);
        Gate::authorize('view', $document);

        return $document;
    }

    /**
     * @return 'image'|'pdf'|null
     */
    private function previewKind(string $mime): ?string
    {
        return match ($mime) {
            'image/jpeg', 'image/png' => 'image',
            'application/pdf' => 'pdf',
            default => null,
        };
    }
}
