<x-member.layout :page="$page" :title="$title">
    <h2 class="ppx-page-heading">
        <span data-ppx-greeting>{{ $greeting }}</span>, {{ $firstName }}
    </h2>

    @if (session('status'))
        <p class="ppx-notice ppx-notice--success" role="status">{{ session('status') }}</p>
    @endif

    <div class="ppx-dash">
        <div class="ppx-dash__main">
            @if ($agency)
                <p class="ppx-agency-line">
                    <x-member.icon name="profile" class="ppx-agency-line__icon" />
                    <span>Agency: <strong>{{ $agency['name'] }}</strong>@if ($agency['principal']), principal: <strong>{{ $agency['principal'] }}</strong>@endif</span>
                </p>
            @endif

            <livewire:member.dashboard />
        </div>

        <aside class="ppx-dash__aside" aria-label="Notifications and uploads">
            <section class="ppx-panel" id="ppx-notifications" aria-labelledby="ppx-notifications-title">
                <div class="ppx-panel__header">
                    <h2 class="ppx-panel__title" id="ppx-notifications-title">Notifications</h2>
                    @if ($unread = $notifications->where('unread', true)->count())
                        <span class="ppx-panel__count" aria-label="{{ $unread }} new">{{ $unread }}</span>
                    @endif
                </div>
                @if ($notifications->isEmpty())
                    <p class="ppx-panel__empty">You are all caught up.</p>
                @else
                    <ul class="ppx-notifications">
                        @foreach ($notifications as $notification)
                            <li @class(['ppx-notifications__item', 'is-unread' => $notification['unread']])>
                                @if ($notification['url'])
                                    <a class="ppx-notifications__title" href="{{ $notification['url'] }}">{{ $notification['title'] }}</a>
                                @else
                                    <span class="ppx-notifications__title">{{ $notification['title'] }}</span>
                                @endif
                                <time class="ppx-notifications__time" datetime="{{ $notification['at']->toIso8601String() }}">{{ $notification['at']->diffForHumans() }}</time>
                                @if ($notification['unread'])
                                    <span class="ppx-notifications__dot"><span class="ppx-visually-hidden">New</span></span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            @if ($canUpload)
                <section class="ppx-panel" aria-labelledby="ppx-upload-title">
                    <div class="ppx-panel__header">
                        <h2 class="ppx-panel__title" id="ppx-upload-title">Upload a document</h2>
                    </div>
                    <form class="ppx-dropzone" method="POST" action="{{ route('member.license-documents.store') }}" enctype="multipart/form-data" data-ppx-upload novalidate>
                        @csrf
                        <span class="ppx-dropzone__icon"><x-member.icon name="upload-cloud" class="ppx-dropzone__svg" /></span>
                        <p class="ppx-dropzone__title">Drag &amp; drop your file</p>
                        <p class="ppx-dropzone__hint" id="ppx-upload-hint">PDF, JPG or PNG · up to 10 MB</p>

                        <label class="ppx-field">
                            <span class="ppx-field__label">Document type</span>
                            <select class="ppx-field__input" name="document_type" required>
                                @foreach ($documentTypes as $value => $label)
                                    <option value="{{ $value }}" @selected(old('document_type', 'resident') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="ppx-btn ppx-btn--outline ppx-dropzone__browse">
                            Browse files
                            <input class="ppx-visually-hidden" type="file" name="file" accept="{{ \App\Services\Documents\DocumentVault::acceptedExtensions() }}" aria-describedby="ppx-upload-hint" required data-ppx-upload-input>
                        </label>
                        <p class="ppx-dropzone__file" data-ppx-upload-file aria-live="polite"></p>

                        <progress class="ppx-dropzone__progress" max="100" value="0" hidden data-ppx-upload-progress>0%</progress>

                        @if ($errors->any())
                            <p class="ppx-dropzone__error" role="alert" data-ppx-upload-error>{{ $errors->first() }}</p>
                        @else
                            <p class="ppx-dropzone__error" role="alert" data-ppx-upload-error hidden></p>
                        @endif

                        <x-ppx.button type="submit" variant="primary" class="ppx-dropzone__submit">Upload</x-ppx.button>
                    </form>
                </section>
            @endif
        </aside>
    </div>

    <script src="{{ \App\Support\PortalAsset::url('upload.js') }}" defer nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
</x-member.layout>
