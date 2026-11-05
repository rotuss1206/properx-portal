{{--
    The membership certificate (docs/design/Certificate Generate.jpg): options and format on the left, the live
    preview on the right. The preview is the PDF's own markup and styles (pdf.certificate-body, -styles); public/portal/
    certificate.js scales it to the column and shows the choices at once. Without JavaScript the form still works and
    the preview shows the defaults. Who cannot have a certificate reads why instead of the options.
--}}
@php($u = \App\Services\Certificates\CertificateGeneratorService::points(...))
<x-member.layout :page="$page" :title="$title">
    <div class="ppx-cert">
        @if ($document)
            <p class="ppx-docs__lead">Generate an official PDF certificate of your active membership, on demand.</p>

            <div class="ppx-cert__columns">
                <form class="ppx-cert__side" method="GET" action="{{ $certificate['url'] }}" data-ppx-cert-form>
                    <x-ppx.card title="Certificate options" heading="h2">
                        <div class="ppx-cert__options">
                            <label class="ppx-field ppx-cert__term">
                                <span class="ppx-field__label">Membership term</span>
                                <select class="ppx-field__input" name="term" data-ppx-cert-term>
                                    @forelse ($terms as $term)
                                        <option value="{{ $term['value'] }}" data-date="{{ $term['date'] }}" data-term="{{ $term['term'] }}">{{ $term['label'] }}</option>
                                    @empty
                                        <option value="">Current</option>
                                    @endforelse
                                </select>
                            </label>

                            <ul class="ppx-switches">
                                @foreach ([
                                    ['member_id', 'member-id', 'Include member ID', 'Show '.$document['memberId'].' on the certificate', true],
                                    ['join_date', 'join-date', 'Include join date', 'Display membership start date', true],
                                    ['qr', 'qr', 'Include verification QR', 'Add a scannable verification code', false],
                                ] as [$name, $part, $label, $hint, $on])
                                    <li class="ppx-switch">
                                        <span class="ppx-switch__text">
                                            <label class="ppx-switch__label" for="ppx-cert-{{ $part }}">{{ $label }}</label>
                                            <span class="ppx-switch__hint" id="ppx-cert-{{ $part }}-hint">{{ $hint }}</span>
                                        </span>
                                        <input type="hidden" name="{{ $name }}" value="0">
                                        <input class="ppx-switch__input" id="ppx-cert-{{ $part }}" type="checkbox" role="switch" name="{{ $name }}" value="1" aria-describedby="ppx-cert-{{ $part }}-hint" data-ppx-cert-toggle="{{ $part }}" @checked($on)>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </x-ppx.card>

                    <x-ppx.card title="Format" heading="h2">
                        <div class="ppx-cert__formats" role="radiogroup" aria-label="Format">
                            <label class="ppx-cert__format">
                                <input type="radio" name="format" value="pdf" checked>
                                <x-member.icon name="license" class="ppx-cert__format-icon" />
                                <span>
                                    <span class="ppx-cert__format-name">PDF — Letter, {{ $landscape ? 'landscape' : 'portrait' }}</span>
                                    <span class="ppx-cert__format-hint">Print-ready, {{ $landscape ? '11 × 8.5' : '8.5 × 11' }} in</span>
                                </span>
                            </label>
                            <label class="ppx-cert__format is-disabled">
                                <input type="radio" name="format" value="png" disabled>
                                <x-member.icon name="image" class="ppx-cert__format-icon" />
                                <span>
                                    <span class="ppx-cert__format-name">PNG image</span>
                                    <span class="ppx-cert__format-hint">For sharing online · coming soon</span>
                                </span>
                            </label>
                        </div>
                    </x-ppx.card>

                    <x-ppx.button type="submit" variant="primary" icon="download" class="ppx-cert__download">Generate &amp; download</x-ppx.button>
                </form>

                <div class="ppx-cert__main">
                    <section class="ppx-cert__stage" aria-labelledby="ppx-cert-preview-title">
                        <h2 class="ppx-cert__stage-title" id="ppx-cert-preview-title"><x-member.icon name="eye" class="ppx-cert__stage-icon" /> Live preview</h2>
                        <div @class(['ppx-cert__paper', 'ppx-cert__paper--landscape' => $landscape]) data-ppx-cert-paper>
                            <div @class(['ppx-cert__page', 'ppx-cert__page--landscape' => $landscape]) data-ppx-cert-page>
                                @include('pdf.certificate-body', $document)
                            </div>
                        </div>
                    </section>
                </div>
            </div>

            <script src="{{ \App\Support\PortalAsset::url('certificate.js') }}" defer nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
            <style>
                @font-face { font-family: 'PPX Lora'; font-style: italic; font-weight: normal; src: url('{{ asset('portal/fonts/lora-400-italic.ttf') }}') format('truetype'); }
                @font-face { font-family: 'PPX Open Sans'; font-style: normal; font-weight: normal; src: url('{{ asset('portal/fonts/open-sans-latin-400-normal.woff2') }}') format('woff2'); }
                @font-face { font-family: 'PPX Poppins'; font-style: normal; font-weight: normal; src: url('{{ asset('portal/fonts/poppins-latin-600-normal.woff2') }}') format('woff2'); }
                @include('pdf.certificate-styles')
                {{-- Real CSS here; in the PDF these are the images pdf/images/certificate-background*.png. --}}
                .ppx-cert__page .cert-frame { background: #fff; box-shadow: 0 {{ $u(12) }} {{ $u(40) }} 0 rgba(16, 40, 70, .14); }
                .ppx-cert__page .cert-frame-gold { background: linear-gradient(180deg, #fff 0%, #fcfbf6 100%); }
            </style>
        @else
            <div class="ppx-cert__side ppx-cert__side--notice">
                <x-ppx.card title="Certificate unavailable" heading="h2">
                    <div class="ppx-cert__notice">
                        <p class="ppx-cert__status"><span>Membership status</span> <x-ppx.badge :variant="$membership['variant']">{{ $membership['label'] }}</x-ppx.badge></p>
                        <p class="ppx-cert__reason">{{ $certificate['reason'] }}</p>
                        <p class="ppx-cert__actions">
                            @if ($renewUrl)
                                <x-ppx.button variant="primary" :href="$renewUrl" class="ppx-cert__renew">Renew now</x-ppx.button>
                            @endif
                            <x-ppx.button variant="secondary" :href="route('member.profile')">Review your profile</x-ppx.button>
                            @if ($canUploadLicense)
                                <x-ppx.button variant="secondary" :href="route('member.license-documents')">License documents</x-ppx.button>
                            @endif
                        </p>
                    </div>
                </x-ppx.card>
            </div>
        @endif
    </div>
</x-member.layout>
