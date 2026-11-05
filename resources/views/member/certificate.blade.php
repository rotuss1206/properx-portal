{{--
    The membership certificate (docs/design/Certificate Generate.jpg): details and Download on the left, the
    preview on the right. The preview is the PDF's own markup and styles (pdf.certificate-body, -styles), scaled
    to the column by public/portal/certificate.js; without JavaScript member.css sets a scale per screen width.
--}}
<x-member.layout :page="$page" :title="$title">
    <div class="ppx-cert">
        @if ($document)
            <p class="ppx-docs__lead">Your official certificate of active membership.</p>
        @endif

        <div class="ppx-cert__columns">
            <div class="ppx-cert__side">
                <x-ppx.card title="Certificate details" heading="h2">
                    <dl class="ppx-docs__facts ppx-cert__facts">
                        <div class="ppx-docs__fact">
                            <dt>Membership status</dt>
                            <dd><x-ppx.badge :variant="$membership['variant']">{{ $membership['label'] }}</x-ppx.badge></dd>
                        </div>
                        @if ($document)
                            <div class="ppx-docs__fact"><dt>Name</dt><dd>{{ $document['memberName'] }}</dd></div>
                            <div class="ppx-docs__fact"><dt>Member ID</dt><dd>{{ $document['memberId'] }}</dd></div>
                            <div class="ppx-docs__fact"><dt>Member since</dt><dd>{{ \Illuminate\Support\Carbon::parse($document['memberSince'])->format('F Y') }}</dd></div>
                            @if ($document['validThrough'])
                                <div class="ppx-docs__fact"><dt>Valid through</dt><dd>{{ \Illuminate\Support\Carbon::parse($document['validThrough'])->format('M j, Y') }}</dd></div>
                            @endif
                            <div class="ppx-docs__fact">
                                <dt>Verification number</dt>
                                <dd><a class="ppx-link" href="{{ $document['verificationUrl'] }}" target="_blank" rel="noopener">{{ $document['certificateCode'] }}<span class="ppx-visually-hidden"> (opens the public check in a new tab)</span></a></dd>
                            </div>
                        @endif
                    </dl>
                </x-ppx.card>

                @if ($document)
                    <x-ppx.card title="Something not right?" heading="h2">
                        <p class="ppx-cert__help">The name comes from your profile. <a class="ppx-link" href="{{ route('member.profile') }}#contact">Update your profile</a> and the certificate follows. For your member ID or dates, contact ProPerX.</p>
                    </x-ppx.card>

                    <x-ppx.button variant="primary" icon="download" :href="$certificate['url']" class="ppx-cert__download">Download PDF</x-ppx.button>
                @endif
            </div>

            <div class="ppx-cert__main">
                @if ($document)
                    <section class="ppx-cert__stage" aria-labelledby="ppx-cert-preview-title">
                        <h2 class="ppx-cert__stage-title" id="ppx-cert-preview-title"><x-member.icon name="eye" class="ppx-cert__stage-icon" /> Preview</h2>
                        <div class="ppx-cert__paper" data-ppx-cert-paper>
                            <div class="ppx-cert__page" data-ppx-cert-page>
                                @include('pdf.certificate-body', $document)
                            </div>
                        </div>
                    </section>
                    <script src="{{ \App\Support\PortalAsset::url('certificate.js') }}" defer nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
                @else
                    <div class="ppx-card">
                        <x-ppx.empty-state icon="certificate" title="Your certificate is not available" :text="$certificate['reason']">
                            <p class="ppx-cert__actions">
                                @if ($renewUrl)
                                    <x-ppx.button variant="primary" :href="$renewUrl">Renew membership</x-ppx.button>
                                @endif
                                <x-ppx.button variant="secondary" :href="route('member.profile')">Review your profile</x-ppx.button>
                                @if ($canUploadLicense)
                                    <x-ppx.button variant="secondary" :href="route('member.license-documents')">License documents</x-ppx.button>
                                @endif
                            </p>
                        </x-ppx.empty-state>
                    </div>
                @endif
            </div>
        </div>
    </div>

    @if ($document)
        <style>
            @font-face { font-family: 'PPX Lora'; font-style: italic; font-weight: normal; src: url('{{ asset('portal/fonts/lora-400-italic.ttf') }}') format('truetype'); }
            @font-face { font-family: 'PPX Open Sans'; font-style: normal; font-weight: normal; src: url('{{ asset('portal/fonts/open-sans-latin-400-normal.woff2') }}') format('woff2'); }
            @font-face { font-family: 'PPX Poppins'; font-style: normal; font-weight: normal; src: url('{{ asset('portal/fonts/poppins-latin-600-normal.woff2') }}') format('woff2'); }
            @include('pdf.certificate-styles')
        </style>
    @endif
</x-member.layout>
