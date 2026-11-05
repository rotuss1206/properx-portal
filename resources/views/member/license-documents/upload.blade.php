{{--
    Upload license documents. One plain multipart form around both columns, no
    Livewire temporary files. public/portal/upload-page.js adds the list of files, drag & drop and a
    progress bar per file, and shows the fields each type needs. App\Http\Controllers\Member\LicenseDocumentController::store
    makes one document per file and checks size and type by content.
--}}
<x-member.layout :page="$page" :title="$title" :parent="['label' => 'License documents', 'url' => route('member.license-documents')]">
    <h2 class="ppx-page-heading ppx-upload__heading">Upload license document</h2>
    <p class="ppx-upload__lead">Add a new license or supporting document to your member record.</p>

    @php($fileErrors = $tooLarge ? [\App\Http\Controllers\Member\LicenseDocumentController::TOO_LARGE] : $errors->get('file'))

    <form class="ppx-upload" method="POST" action="{{ route('member.license-documents.store') }}" enctype="multipart/form-data" data-ppx-upload-page data-done="{{ route('member.license-documents') }}" data-icons="{{ \App\Support\PortalAsset::url('img/icons.svg') }}" novalidate>
        @csrf
        <input type="hidden" name="from" value="page">

        <div class="ppx-upload__main">
            <x-ppx.card title="Select file" heading="h3">
                <div class="ppx-upload__zone" data-ppx-upload-zone>
                    <span class="ppx-upload__zone-icon"><x-member.icon name="upload-cloud" class="ppx-upload__zone-svg" /></span>
                    <p class="ppx-upload__zone-title">Drag &amp; drop your document here</p>
                    <p class="ppx-upload__zone-text">or click to browse from your device</p>
                    <label class="ppx-btn ppx-btn--primary ppx-upload__browse">
                        Browse files
                        <input class="ppx-visually-hidden" type="file" name="file[]" multiple accept="{{ \App\Services\Documents\DocumentVault::acceptedExtensions() }}" aria-describedby="ppx-upload-hint" data-ppx-upload-input>
                    </label>
                    <p class="ppx-upload__zone-hint" id="ppx-upload-hint">Accepted: PDF, JPG, PNG · Max 10 MB per file</p>
                </div>
            </x-ppx.card>

            <x-ppx.card title="Uploaded files" heading="h3">
                <div class="ppx-upload__files">
                    @if ($fileErrors)
                        <ul class="ppx-upload__server-errors" role="alert" data-ppx-upload-server-errors>
                            @foreach ($fileErrors as $error)
                                <li class="ppx-upload-file is-rejected">
                                    <span class="ppx-upload-file__icon"><x-member.icon name="file-x" class="ppx-upload-file__svg" /></span>
                                    <div class="ppx-upload-file__text">
                                        <div class="ppx-upload-file__line"><span class="ppx-upload-file__name">File not saved</span> <span class="ppx-badge ppx-badge--danger ppx-upload-file__badge">Rejected</span></div>
                                        <p class="ppx-upload-file__error">{{ $error }}</p>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <p class="ppx-upload__none" data-ppx-upload-none @if ($fileErrors) hidden @endif>No files selected yet.</p>
                    <ul class="ppx-upload__list" data-ppx-upload-list aria-live="polite"></ul>
                    <p class="ppx-upload__message" role="status" data-ppx-upload-message hidden></p>
                </div>
            </x-ppx.card>
        </div>

        <div class="ppx-upload__side">
            <x-ppx.card title="Document details" heading="h3">
                <div class="ppx-upload__details">
                    <label class="ppx-field">
                        <span class="ppx-field__label">Document type</span>
                        <select class="ppx-field__input" name="document_type" required aria-describedby="ppx-upload-document_type-error" data-ppx-upload-type data-license-types="{{ implode(',', $licenseTypes) }}" @error('document_type') aria-invalid="true" @enderror>
                            @foreach ($documentTypes as $value => $label)
                                <option value="{{ $value }}" @selected(old('document_type', 'resident') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <p class="ppx-field__error" id="ppx-upload-document_type-error" data-ppx-field-error="document_type" @unless ($errors->has('document_type')) hidden @endunless>{{ $errors->first('document_type') }}</p>

                    {{-- Kept with the document; the profile is not overwritten. Number and expiry start from the profile. --}}
                    <div class="ppx-upload__license" data-ppx-upload-license>
                        <div>
                            <label class="ppx-field">
                                <span class="ppx-field__label">License number</span>
                                <input class="ppx-field__input" type="text" name="license_number" maxlength="50" value="{{ old('license_number', $member?->license_number) }}" data-profile-value="{{ $member?->license_number }}" aria-describedby="ppx-upload-license_number-error" @error('license_number') aria-invalid="true" @enderror>
                            </label>
                            <p class="ppx-field__error" id="ppx-upload-license_number-error" data-ppx-field-error="license_number" @unless ($errors->has('license_number')) hidden @endunless>{{ $errors->first('license_number') }}</p>
                        </div>

                        <div class="ppx-upload__pair">
                            <div>
                                <label class="ppx-field">
                                    <span class="ppx-field__label">Issue date</span>
                                    <input class="ppx-field__input" type="date" name="license_issued_at" value="{{ old('license_issued_at') }}" aria-describedby="ppx-upload-license_issued_at-error" @error('license_issued_at') aria-invalid="true" @enderror>
                                </label>
                                <p class="ppx-field__error" id="ppx-upload-license_issued_at-error" data-ppx-field-error="license_issued_at" @unless ($errors->has('license_issued_at')) hidden @endunless>{{ $errors->first('license_issued_at') }}</p>
                            </div>
                            <div>
                                <label class="ppx-field">
                                    <span class="ppx-field__label">Expiration date</span>
                                    <input class="ppx-field__input" type="date" name="license_expires_at" value="{{ old('license_expires_at', $member?->license_expires_at?->format('Y-m-d')) }}" data-profile-value="{{ $member?->license_expires_at?->format('Y-m-d') }}" aria-describedby="ppx-upload-license_expires_at-error" @error('license_expires_at') aria-invalid="true" @enderror>
                                </label>
                                <p class="ppx-field__error" id="ppx-upload-license_expires_at-error" data-ppx-field-error="license_expires_at" @unless ($errors->has('license_expires_at')) hidden @endunless>{{ $errors->first('license_expires_at') }}</p>
                            </div>
                        </div>

                        {{-- Another state's license; a resident license is in the profile's state. --}}
                        <div data-ppx-upload-state>
                            <label class="ppx-field">
                                <span class="ppx-field__label">State</span>
                                <select class="ppx-field__input" name="license_state" aria-describedby="ppx-upload-license_state-error" @error('license_state') aria-invalid="true" @enderror>
                                    <option value="">Choose…</option>
                                    @foreach ($states as $code => $state)
                                        <option value="{{ $code }}" @selected(old('license_state') === $code)>{{ $state }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <p class="ppx-field__error" id="ppx-upload-license_state-error" data-ppx-field-error="license_state" @unless ($errors->has('license_state')) hidden @endunless>{{ $errors->first('license_state') }}</p>
                        </div>
                    </div>
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
                <x-ppx.button variant="secondary" :href="route('member.license-documents')" data-ppx-upload-cancel>Cancel</x-ppx.button>
                <x-ppx.button type="submit" variant="primary">Save document</x-ppx.button>
            </div>
        </div>
    </form>

    <script src="{{ \App\Support\PortalAsset::url('upload-page.js') }}" defer nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
</x-member.layout>
