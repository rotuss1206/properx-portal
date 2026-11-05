{{--
    License documents: the member's own documents on the left,
    the chosen one's details on the right (?document=id). Rows: App\Member\LicenseDocumentList.
--}}
<x-member.layout :page="$page" :title="$title">
    <div class="ppx-docs">
        <div class="ppx-docs__intro">
            <p class="ppx-docs__lead">Manage your licenses and supporting files.</p>
            <div class="ppx-docs__tools">
                @if ($hasDocuments)
                    <label class="ppx-docs__search" data-ppx-doc-search hidden>
                        <x-member.icon name="search" class="ppx-docs__search-icon" />
                        <span class="ppx-visually-hidden">Search documents</span>
                        <input class="ppx-docs__search-input" type="search" placeholder="Search documents" autocomplete="off">
                    </label>
                @endif
                <x-ppx.button variant="primary" icon="upload" :href="route('member.license-documents.upload')" class="ppx-docs__upload">Upload</x-ppx.button>
            </div>
        </div>

        @if (session('status'))
            <p class="ppx-notice ppx-notice--success" role="status">{{ session('status') }}</p>
        @endif
        @if (session('document_error'))
            <p class="ppx-notice ppx-notice--danger" role="alert">{{ session('document_error') }}</p>
        @endif

        @if (! $hasDocuments)
            <div class="ppx-card">
                <x-ppx.empty-state icon="license" title="No documents yet" text="Upload your license so ProPerX can review it. PDF, JPG or PNG, up to 10 MB.">
                    <x-ppx.button variant="primary" icon="upload" :href="route('member.license-documents.upload')" class="ppx-empty__action">Upload a document</x-ppx.button>
                </x-ppx.empty-state>
            </div>
        @else
            <x-ppx.chips class="ppx-docs__chips" :options="\App\Member\LicenseDocumentList::FILTERS" :current="$filter" :action="false" :href="fn ($key) => route('member.license-documents', $key === 'all' ? [] : ['status' => $key])" label="Document status" />

            <div class="ppx-docs__columns">
                <div class="ppx-card ppx-docs__list">
                    @if ($rows->isEmpty())
                        <x-ppx.empty-state icon="license" title="No documents with this status" />
                    @else
                        <div class="ppx-doc-table__wrap">
                            <table class="ppx-doc-table">
                                <caption class="ppx-visually-hidden">Your license documents</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">Document</th>
                                        <th scope="col">Type</th>
                                        <th scope="col">Status</th>
                                        <th scope="col">Uploaded</th>
                                        <th scope="col"><span class="ppx-visually-hidden">Details</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($rows as $row)
                                        @php($isSelected = $selected && $selected['id'] === $row['id'])
                                        <tr @class(['ppx-docs__row', 'is-selected' => $isSelected]) data-ppx-doc-row data-search="{{ mb_strtolower($row['name'].' '.$row['type'].' '.$row['status']) }}">
                                            <td data-label="Document">
                                                <span class="ppx-doc-table__doc">
                                                    <span class="ppx-doc-table__icon"><x-member.icon name="license" class="ppx-doc-table__svg" /></span>
                                                    <span class="ppx-docs__text">
                                                        <a class="ppx-doc-table__name ppx-docs__link" href="{{ route('member.license-documents', array_filter(['document' => $row['id'], 'status' => $filter === 'all' ? null : $filter])) }}#ppx-document" @if ($isSelected) aria-current="true" @endif>{{ $row['name'] }}</a>
                                                        <span class="ppx-doc-table__detail">{{ $row['size'] }} · {{ $row['format'] }}</span>
                                                        @if ($row['reason'])
                                                            <span class="ppx-docs__reason">{{ $row['reason'] }}</span>
                                                        @endif
                                                    </span>
                                                </span>
                                            </td>
                                            <td data-label="Type">{{ $row['type'] }}</td>
                                            <td data-label="Status"><x-ppx.badge :variant="$row['variant']">{{ $row['status'] }}</x-ppx.badge></td>
                                            <td data-label="Uploaded">{{ $row['uploadedAt']?->format('j M Y') ?? '—' }}</td>
                                            <td class="ppx-doc-table__action"><x-member.icon name="chevron-right" class="ppx-docs__chevron" /></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <p class="ppx-docs__none" data-ppx-doc-none hidden>No documents match your search.</p>
                    @endif
                </div>

                @if ($selected)
                    @include('member.license-documents.details', ['document' => $selected])
                @endif
            </div>

            <script src="{{ \App\Support\PortalAsset::url('documents.js') }}" defer nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
        @endif
    </div>
</x-member.layout>
