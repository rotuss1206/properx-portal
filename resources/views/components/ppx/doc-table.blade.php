@props(['rows', 'caption' => 'Documents', 'emptyTitle' => 'No documents yet', 'emptyText' => null])
{{--
    Document table (public/portal/components.css): Document (icon, name, detail), Type, Status,
    Updated, download. Rows: App\Member\DocumentList. Stacks into cards on phones.
--}}
@if ($rows->isEmpty())
    <x-ppx.empty-state icon="license" :title="$emptyTitle" :text="$emptyText" />
@else
    <div class="ppx-doc-table__wrap">
        <table {{ $attributes->class('ppx-doc-table') }}>
            <caption class="ppx-visually-hidden">{{ $caption }}</caption>
            <thead>
                <tr>
                    <th scope="col">Document</th>
                    <th scope="col">Type</th>
                    <th scope="col">Status</th>
                    <th scope="col">Updated</th>
                    <th scope="col"><span class="ppx-visually-hidden">Download</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td data-label="Document">
                            <span class="ppx-doc-table__doc">
                                <span class="ppx-doc-table__icon"><x-member.icon :name="$row['icon']" class="ppx-doc-table__svg" /></span>
                                <span>
                                    <span class="ppx-doc-table__name">{{ $row['name'] }}</span>
                                    @if ($row['detail'])
                                        <span class="ppx-doc-table__detail">{{ $row['detail'] }}</span>
                                    @endif
                                </span>
                            </span>
                        </td>
                        <td data-label="Type">{{ $row['type'] }}</td>
                        <td data-label="Status"><x-ppx.badge :variant="$row['variant']">{{ $row['status'] }}</x-ppx.badge></td>
                        <td data-label="Updated">
                            @if ($row['updated'])
                                <time datetime="{{ $row['updated']->toIso8601String() }}" title="{{ $row['updated']->toFormattedDateString() }}">{{ $row['updated']->diffForHumans() }}</time>
                            @else
                                —
                            @endif
                        </td>
                        <td class="ppx-doc-table__action">
                            @if ($row['url'])
                                <x-ppx.button variant="icon" icon="download" :href="$row['url']" :label="'Download '.$row['name']" />
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
