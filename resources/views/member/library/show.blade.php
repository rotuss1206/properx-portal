{{--
    A library document's details: the description on the left, the panel of docs/design/Documents Management.jpg on
    the right. Preview through the server only (LibraryController::preview): an image in the panel, a PDF in a new tab.
--}}
<x-member.layout :page="$page" :title="$title" :parent="['label' => 'Compliance library', 'url' => route('member.library')]">
    <div class="ppx-docs">
        <div class="ppx-docs__columns">
            <x-ppx.card title="About this document" heading="h2" class="ppx-docs__list">
                <p class="ppx-library__description">{{ $document->description ?: 'No description was added to this document.' }}</p>
            </x-ppx.card>

            <aside class="ppx-card ppx-docs__panel" id="ppx-document" aria-labelledby="ppx-document-title">
                <div class="ppx-docs__panel-head">
                    <span class="ppx-docs__panel-icon"><x-member.icon name="library" class="ppx-docs__panel-svg" /></span>
                    <div class="ppx-docs__panel-text">
                        <h2 class="ppx-docs__panel-title" id="ppx-document-title">{{ $document->title }}</h2>
                        <p class="ppx-docs__panel-name">{{ $document->category }}</p>
                    </div>
                </div>

                <div class="ppx-docs__panel-body">
                    @if ($preview === 'image')
                        <a class="ppx-docs__preview ppx-docs__preview--image" href="{{ $previewUrl }}" target="_blank" rel="noopener" data-ppx-doc-preview>
                            <img class="ppx-docs__preview-image" src="{{ $previewUrl }}" alt="Preview of {{ $document->title }}" loading="lazy">
                            <span class="ppx-visually-hidden">Open the full size in a new tab</span>
                        </a>
                        <div class="ppx-docs__preview" data-ppx-doc-preview-failed hidden>
                            <x-member.icon name="license" class="ppx-docs__preview-svg" />
                            <span>The preview is not available right now. Use Download.</span>
                        </div>
                    @elseif ($preview === 'pdf')
                        <div class="ppx-docs__preview">
                            <x-member.icon name="license" class="ppx-docs__preview-svg" />
                            <a class="ppx-link" href="{{ $previewUrl }}" target="_blank" rel="noopener">Open preview<span class="ppx-visually-hidden"> of {{ $document->title }} in a new tab</span></a>
                        </div>
                    @else
                        <div class="ppx-docs__preview">
                            <x-member.icon name="license" class="ppx-docs__preview-svg" />
                            <span>No preview for this file. Use Download.</span>
                        </div>
                    @endif

                    <dl class="ppx-docs__facts">
                        <div class="ppx-docs__fact"><dt>Category</dt><dd>{{ $document->category }}</dd></div>
                        <div class="ppx-docs__fact"><dt>Published</dt><dd>{{ $document->uploaded_at?->format('j M Y') ?? '—' }}</dd></div>
                        <div class="ppx-docs__fact"><dt>Format</dt><dd>{{ $format }}</dd></div>
                    </dl>

                    <div class="ppx-docs__actions">
                        <x-ppx.button variant="navy" icon="download" :href="$downloadUrl" class="ppx-docs__download">Download</x-ppx.button>
                    </div>
                </div>
            </aside>
        </div>
    </div>

    <script src="{{ \App\Support\PortalAsset::url('documents.js') }}" defer nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
</x-member.layout>
