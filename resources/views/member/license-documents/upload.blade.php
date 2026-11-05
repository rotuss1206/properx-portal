{{--
    Upload a license document (docs/design/Container.jpg). One plain multipart form around both
    columns, no Livewire temporary files; public/portal/upload.js adds drag & drop and progress.
    App\Http\Controllers\Member\LicenseDocumentController::store checks size and type by content.
--}}
<x-member.layout :page="$page" :title="$title" :parent="['label' => 'License documents', 'url' => route('member.license-documents')]">
    <h2 class="ppx-page-heading ppx-upload__heading">Upload license document</h2>
    <p class="ppx-upload__lead">Add a new license or supporting document to your member record.</p>

    @php($fileError = $tooLarge ? 'The file may not be larger than 10 MB.' : $errors->first('file'))

    <form class="ppx-upload" method="POST" action="{{ route('member.license-documents.store') }}" enctype="multipart/form-data" data-ppx-upload novalidate>
        @csrf
        <input type="hidden" name="from" value="page">

        <div class="ppx-upload__main">
            <x-ppx.card title="Select file" heading="h3">
                <div class="ppx-upload__zone">
                    <span class="ppx-upload__zone-icon"><x-member.icon name="upload-cloud" class="ppx-upload__zone-svg" /></span>
                    <p class="ppx-upload__zone-title">Drag &amp; drop your document here</p>
                    <p class="ppx-upload__zone-text">or browse from your device</p>
                    <label class="ppx-btn ppx-btn--primary ppx-upload__browse">
                        Browse files
                        <input class="ppx-visually-hidden" type="file" name="file" accept="{{ \App\Services\Documents\DocumentVault::acceptedExtensions() }}" aria-describedby="ppx-upload-hint" required autofocus data-ppx-upload-input>
                    </label>
                    <p class="ppx-upload__zone-hint" id="ppx-upload-hint">Accepted: PDF, JPG, PNG · Max 10 MB per file</p>
                </div>
            </x-ppx.card>

            <x-ppx.card title="Selected file" heading="h3">
                <div class="ppx-upload__files">
                    <div class="ppx-upload-file" data-ppx-upload-row data-state="{{ $fileError ? 'error' : 'empty' }}">
                        <p class="ppx-upload-file__none">No file selected yet.</p>
                        <span class="ppx-doc-table__icon ppx-upload-file__icon"><x-member.icon name="license" class="ppx-doc-table__svg" /></span>
                        <div class="ppx-upload-file__text">
                            <p class="ppx-upload-file__line">
                                <span class="ppx-upload-file__name" data-ppx-upload-file aria-live="polite">{{ $fileError ? 'File not saved' : '' }}</span>
                                <x-ppx.badge variant="danger" :dot="false" class="ppx-upload-file__rejected">Rejected</x-ppx.badge>
                            </p>
                            <p class="ppx-upload-file__meta" data-ppx-upload-meta></p>
                            <p class="ppx-upload-file__percent" data-ppx-upload-percent aria-live="polite">Uploading · 0%</p>
                            <progress class="ppx-upload-file__progress" max="100" value="0" hidden data-ppx-upload-progress>0%</progress>
                            <p class="ppx-upload-file__error" role="alert" data-ppx-upload-error @unless ($fileError) hidden @endunless>{{ $fileError }}</p>
                        </div>
                        <x-ppx.button variant="icon" icon="close" label="Remove this file" class="ppx-upload-file__clear" data-ppx-upload-clear />
                    </div>
                </div>
            </x-ppx.card>
        </div>

        <div class="ppx-upload__side">
            <x-ppx.card title="Document details" heading="h3">
                <div class="ppx-upload__details">
                    <label class="ppx-field">
                        <span class="ppx-field__label">Document type</span>
                        <select class="ppx-field__input" name="document_type" required @error('document_type') aria-invalid="true" aria-describedby="ppx-upload-type-error" @enderror>
                            @foreach ($documentTypes as $value => $label)
                                <option value="{{ $value }}" @selected(old('document_type', 'resident') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    @error('document_type')
                        <p class="ppx-field__error" id="ppx-upload-type-error">{{ $message }}</p>
                    @enderror
                </div>
            </x-ppx.card>

            <section class="ppx-upload__secure" aria-labelledby="ppx-upload-secure">
                <p class="ppx-upload__secure-title" id="ppx-upload-secure">
                    <span class="ppx-upload__secure-icon"><x-member.icon name="shield" class="ppx-upload__secure-svg" /></span>
                    Your documents are secure
                </p>
                <ul class="ppx-upload__secure-list">
                    <li>Encrypted in transit and at rest (AES-256)</li>
                    @if (config('portal.member_area.malware_scan'))
                        <li>Automatically scanned for malware on upload</li>
                    @endif
                    <li>Only you and ProPerX staff can access them</li>
                </ul>
            </section>

            <div class="ppx-upload__actions">
                <x-ppx.button variant="secondary" :href="route('member.license-documents')">Cancel</x-ppx.button>
                <x-ppx.button type="submit" variant="primary">Save document</x-ppx.button>
            </div>
        </div>
    </form>

    <script src="{{ \App\Support\PortalAsset::url('upload.js') }}" defer nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
</x-member.layout>
