{{--
    The compliance library, in the style of docs/design/Documents Management.jpg (there is no mockup of its own):
    category chips, search and a table; each row opens the document's details page. The search words are posted
    and kept in the session (App\Http\Controllers\Member\LibraryController), never in a URL.
--}}
<x-member.layout :page="$page" :title="$title">
    <div class="ppx-docs">
        <div class="ppx-docs__intro">
            <p class="ppx-docs__lead">Compliance briefings, training and forms for your practice.</p>
            <form class="ppx-docs__tools" method="POST" action="{{ route('member.library.search') }}" role="search">
                @csrf
                @if ($category !== 'all')
                    <input type="hidden" name="category" value="{{ $category }}">
                @endif
                <label class="ppx-docs__search">
                    <x-member.icon name="search" class="ppx-docs__search-icon" />
                    <span class="ppx-visually-hidden">Search the library</span>
                    <input class="ppx-docs__search-input" type="search" name="q" value="{{ $search }}" placeholder="Search the library" maxlength="{{ \App\Member\MemberSearch::MAX_LENGTH }}" autocomplete="off">
                </label>
                <x-ppx.button type="submit" variant="secondary" class="ppx-library__go">Search</x-ppx.button>
            </form>
        </div>

        <x-ppx.chips class="ppx-docs__chips" :options="$categories" :current="$category" :action="false" :href="fn ($key) => route('member.library', $key === 'all' ? [] : ['category' => $key])" label="Category" />

        @if ($search !== '')
            <form class="ppx-library__searching" method="POST" action="{{ route('member.library.search') }}">
                @csrf
                @if ($category !== 'all')
                    <input type="hidden" name="category" value="{{ $category }}">
                @endif
                <input type="hidden" name="q" value="">
                <span role="status">{{ $documents->total() }} {{ \Illuminate\Support\Str::plural('result', $documents->total()) }} for “{{ $search }}”</span>
                <button type="submit" class="ppx-link ppx-library__clear">Clear search</button>
            </form>
        @endif

        <div class="ppx-card">
            @if ($documents->isEmpty())
                @if ($filtered)
                    <x-ppx.empty-state icon="search" title="No matching documents" text="Try another word or category." />
                @else
                    <x-ppx.empty-state icon="library" title="No documents yet" text="ProPerX publishes compliance and training material here." />
                @endif
            @else
                <div class="ppx-doc-table__wrap">
                    <table class="ppx-doc-table">
                        <caption class="ppx-visually-hidden">Compliance library documents</caption>
                        <thead>
                            <tr>
                                <th scope="col">Document</th>
                                <th scope="col">Category</th>
                                <th scope="col">Published</th>
                                <th scope="col"><span class="ppx-visually-hidden">Details</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($documents as $document)
                                <tr class="ppx-docs__row">
                                    <td data-label="Document">
                                        <span class="ppx-doc-table__doc">
                                            <span class="ppx-doc-table__icon"><x-member.icon name="library" class="ppx-doc-table__svg" /></span>
                                            <span class="ppx-docs__text">
                                                <a class="ppx-doc-table__name ppx-docs__link" href="{{ route('member.library.show', $document) }}">{{ $document->title }}</a>
                                                @if ($document->description)
                                                    <span class="ppx-doc-table__detail ppx-library__summary">{{ \Illuminate\Support\Str::limit($document->description, 110) }}</span>
                                                @endif
                                            </span>
                                        </span>
                                    </td>
                                    <td data-label="Category">{{ $document->category }}</td>
                                    <td data-label="Published">{{ $document->uploaded_at?->format('j M Y') ?? '—' }}</td>
                                    <td class="ppx-doc-table__action"><x-member.icon name="chevron-right" class="ppx-docs__chevron" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        @if ($documents->hasPages())
            <nav class="ppx-library__pages" aria-label="Pages">
                @if ($documents->previousPageUrl())
                    <a class="ppx-link" href="{{ $documents->previousPageUrl() }}" rel="prev">Previous</a>
                @endif
                <span>Page {{ $documents->currentPage() }} of {{ $documents->lastPage() }}</span>
                @if ($documents->nextPageUrl())
                    <a class="ppx-link" href="{{ $documents->nextPageUrl() }}" rel="next">Next</a>
                @endif
            </nav>
        @endif
    </div>
</x-member.layout>
