{{-- Header search results (App\Http\Controllers\Member\SearchController): own documents and the library. --}}
<x-member.layout :page="$page" :title="$title">
    <section class="ppx-card" aria-labelledby="ppx-search-results-title">
        <div class="ppx-card__header">
            <h2 class="ppx-card__title" id="ppx-search-results-title">
                @if ($tooShort)
                    Search
                @else
                    {{ $results->count() }} {{ Str::plural('result', $results->count()) }} for “{{ $query }}”
                @endif
            </h2>
        </div>

        @if ($tooShort)
            <x-ppx.empty-state icon="search" title="Search your documents and the library" text="Type at least {{ \App\Member\MemberSearch::MIN_LENGTH }} characters in the search field above." />
        @elseif ($results->isEmpty())
            <x-ppx.empty-state icon="search" title="No matching documents" text="Try another word, such as license, tax, 1099 or a library topic." />
        @else
            <div class="ppx-doc-table__wrap">
                <table class="ppx-doc-table">
                    <caption class="ppx-visually-hidden">Search results</caption>
                    <thead>
                        <tr>
                            <th scope="col">Document</th>
                            <th scope="col">Type</th>
                            <th scope="col">Date</th>
                            <th scope="col"><span class="ppx-visually-hidden">Open</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($results as $result)
                            <tr>
                                <td data-label="Document">
                                    <span class="ppx-doc-table__doc">
                                        <span class="ppx-doc-table__icon"><x-member.icon :name="$result['icon']" class="ppx-doc-table__svg" /></span>
                                        <span>
                                            <span class="ppx-doc-table__name">{{ $result['name'] }}</span>
                                            @if ($result['detail'])
                                                <span class="ppx-doc-table__detail">{{ $result['detail'] }}</span>
                                            @endif
                                        </span>
                                    </span>
                                </td>
                                <td data-label="Type">{{ $result['type'] }}</td>
                                <td data-label="Date">{{ \App\Http\Controllers\Member\SearchController::date($result['date']) ?? '—' }}</td>
                                <td class="ppx-doc-table__action">
                                    <x-ppx.button variant="icon" icon="download" :href="$result['url']" :label="'Open '.$result['name']" />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-member.layout>
