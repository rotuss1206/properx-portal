<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? 'Enroll your agency' }} · ProPerX Alliance</title>
    {{-- Same look as the other public pages (certificates/layout), wider for the form. --}}
    <style>
        :root { --navy: #1e3a5f; --navy-600: #21579d; --gold: #c8a24a; --muted: #6b7280; --line: #e5e7eb;
                --error: #b91c1c; --ok-bg: #ecfdf5; --ok: #065f46; --focus: #2563eb; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; padding: 24px 16px 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
               background: #f3f4f6; color: #1f2937; line-height: 1.5; }
        .page { max-width: 1100px; margin: 0 auto; }
        .brand { display: block; width: auto; height: 44px; margin-bottom: 12px; }
        h1 { font-size: 26px; margin: 4px 0 20px; color: #111827; }
        h2 { font-size: 20px; margin: 0 0 4px; color: #111827; }
        .lead { margin: 0 0 20px; color: #374151; }
        .card { background: #fff; border-radius: 12px; border-top: 6px solid var(--navy); box-shadow: 0 10px 30px rgba(15, 23, 42, .08); padding: 28px; }
        a { color: var(--navy-600); }
        :focus-visible { outline: 3px solid var(--focus); outline-offset: 2px; }
        .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); border: 0; }

        /* Progress indicator */
        .progress { list-style: none; display: flex; gap: 4px; padding: 0; margin: 0 0 20px; counter-reset: step; }
        .progress li { flex: 1; min-width: 0; }
        .progress .step { display: flex; flex-direction: column; gap: 6px; width: 100%; padding: 0; border: 0; background: none; font: inherit;
                          font-size: 13px; color: var(--muted); text-align: left; }
        .progress .step::before { content: ""; height: 6px; border-radius: 3px; background: var(--line); }
        .progress .done .step::before { background: var(--navy-600); }
        .progress .current .step { color: var(--navy); font-weight: 600; }
        .progress .current .step::before { background: var(--navy); }
        .progress button.step { cursor: pointer; }
        .progress button.step:hover { color: var(--navy); text-decoration: underline; }
        .progress-count { display: none; font-size: 14px; color: var(--muted); margin: -12px 0 16px; }

        /* Layout: form left, price block right (desktop) or pinned to the bottom (mobile) */
        .wizard { display: grid; gap: 24px; align-items: start; }
        .price { position: sticky; bottom: 0; z-index: 5; background: #fff; border-radius: 12px 12px 0 0; padding: 16px 20px;
                 box-shadow: 0 -6px 20px rgba(15, 23, 42, .12); border-top: 4px solid var(--gold); }
        @media (min-width: 960px) {
            .wizard { grid-template-columns: minmax(0, 1fr) 320px; }
            .price { top: 24px; bottom: auto; border-radius: 12px; box-shadow: 0 10px 30px rgba(15, 23, 42, .08); padding: 22px; }
        }
        @media (max-width: 640px) {
            .card { padding: 20px 16px; }
            .progress .step span { display: none; }
            .progress-count { display: block; }
        }

        /* Price block */
        .price h2 { font-size: 16px; }
        .price dl { display: grid; grid-template-columns: 1fr auto; gap: 4px 12px; margin: 10px 0; font-size: 14px; }
        .price dt { color: var(--muted); } .price dd { margin: 0; font-weight: 600; text-align: right; }
        .price .total { font-size: 22px; font-weight: 700; color: var(--navy); }
        .price .summary { font-size: 13px; color: #374151; margin: 8px 0 0; }
        .price .estimate { font-size: 12px; color: var(--muted); margin: 6px 0 0; }
        .badge { display: inline-block; font-size: 12px; font-weight: 600; padding: 2px 8px; border-radius: 999px; background: #fdf6e3; color: #7a5c12; }
        .notice-ok { background: var(--ok-bg); color: var(--ok); border-radius: 8px; padding: 8px 10px; font-size: 13px; margin: 10px 0 0; }

        /* Form controls */
        fieldset { border: 0; padding: 0; margin: 0 0 20px; min-width: 0; }
        legend { padding: 0; margin-bottom: 8px; }
        .section-title { font-weight: 600; font-size: 16px; color: #111827; }
        .grid { display: grid; gap: 16px; }
        @media (min-width: 640px) { .grid-2 { grid-template-columns: 1fr 1fr; } .grid-3 { grid-template-columns: 2fr 1fr 1fr; } }
        .field { display: flex; flex-direction: column; gap: 4px; margin-bottom: 16px; }
        .grid .field { margin-bottom: 0; }
        .field label, .label { font-weight: 600; font-size: 14px; }
        .optional { font-weight: 400; color: var(--muted); }
        .hint { font-size: 13px; color: var(--muted); margin: 0; }
        input[type=text], input[type=email], input[type=tel], input[type=url], select {
            width: 100%; font: inherit; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; color: inherit; }
        input[aria-invalid=true], select[aria-invalid=true] { border-color: var(--error); }
        .error { color: var(--error); font-size: 13px; margin: 0; }
        .check { display: flex; gap: 10px; align-items: flex-start; margin-bottom: 12px; }
        .check input { width: 18px; height: 18px; margin-top: 3px; flex: none; }

        /* Choice and tier cards */
        .choices, .tiers { display: grid; gap: 12px; }
        @media (min-width: 640px) { .choices { grid-template-columns: 1fr 1fr; } .tiers { grid-template-columns: repeat(3, 1fr); } }
        .choice, .tier { position: relative; display: flex; flex-direction: column; gap: 4px; padding: 16px; border: 2px solid var(--line);
                         border-radius: 10px; background: #fff; color: inherit; text-decoration: none; cursor: pointer; }
        .choice input, .tier input { position: absolute; opacity: 0; }
        .choice:hover, .tier:hover { border-color: #94a3b8; }
        .choice:has(input:checked), .tier:has(input:checked) { border-color: var(--navy); box-shadow: 0 0 0 3px rgba(30, 58, 95, .15); }
        .choice:has(input:focus-visible), .tier:has(input:focus-visible) { outline: 3px solid var(--focus); outline-offset: 2px; }
        .choice strong, .tier-name { font-size: 16px; color: #111827; }
        .choice span, .tier-size { font-size: 14px; color: #374151; }
        .tier-rate { font-size: 24px; font-weight: 700; color: var(--navy); margin-top: 6px; }
        .tier-rate small { font-size: 13px; font-weight: 400; color: var(--muted); }
        .note { font-size: 14px; color: #374151; margin: 12px 0 0; }

        /* Actions */
        .actions { display: flex; justify-content: space-between; gap: 12px; margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--line); }
        .btn { font: inherit; font-weight: 600; padding: 11px 22px; border-radius: 8px; border: 2px solid var(--navy); cursor: pointer; }
        .btn-primary { background: var(--navy); color: #fff; margin-left: auto; }
        .btn-primary:hover { background: var(--navy-600); }
        .btn-secondary { background: #fff; color: var(--navy); }
        .btn[disabled] { opacity: .6; cursor: wait; }
        .placeholder { padding: 20px; border: 2px dashed var(--line); border-radius: 10px; color: #374151; }
        .btn.is-blocked { cursor: not-allowed; }
        .link { padding: 0; border: 0; background: none; font: inherit; color: var(--navy-600); text-decoration: underline; cursor: pointer; }
        .notice-info { background: #eff6ff; color: #1e3a8a; border-radius: 8px; padding: 10px 12px; font-size: 14px; margin: 0 0 16px; }
        .notice-ok.tier-notice { font-size: 14px; padding: 10px 12px; margin: 0 0 16px; }

        /* Screen 5 · People */
        .people-title { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; margin: 0 0 10px; }
        .people-title .count { font-size: 14px; font-weight: 400; color: var(--muted); }
        .people { list-style: none; margin: 0 0 16px; padding: 0; display: grid; gap: 8px; }
        .person { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px 16px; padding: 12px 14px;
                  border: 1px solid var(--line); border-radius: 10px; background: #fff; }
        .person.has-error { border-color: var(--error); background: #fef2f2; }
        .person.is-editing { border-color: var(--navy-600); box-shadow: 0 0 0 3px rgba(33, 87, 157, .12); }
        .person-text { min-width: 0; flex: 1 1 260px; }
        .person-name { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 8px; margin: 0; overflow-wrap: anywhere; }
        .person-meta { display: flex; flex-wrap: wrap; gap: 0 14px; margin: 2px 0 0; font-size: 13px; color: var(--muted); overflow-wrap: anywhere; }
        .person-fixed { margin: 0; font-size: 13px; color: var(--muted); }
        .person-errors { list-style: none; margin: 6px 0 0; padding: 0; }
        .person-actions { display: flex; gap: 8px; }
        .tag { display: inline-block; font-size: 12px; padding: 1px 8px; border-radius: 999px; background: #eef2f7; color: #334155; }
        .tag-principal { background: var(--navy); color: #fff; }
        .tag-editing { background: #dbeafe; color: #1e3a8a; }
        .btn-small { font: inherit; font-size: 14px; font-weight: 600; padding: 6px 12px; border-radius: 8px; border: 1px solid #cbd5e1; background: #fff;
                     color: var(--navy); cursor: pointer; }
        .btn-small:hover { border-color: var(--navy); }
        .btn-remove { color: var(--error); }
        .btn-remove:hover { border-color: var(--error); }
        .minimum { margin-top: 4px; }
        .person-form { margin-top: 20px; padding: 18px; border: 1px solid var(--line); border-radius: 10px; background: #f9fafb; }
        .person-form h3 { margin: 0 0 12px; }
        .person-form h3:focus { outline: none; }
        .person-form h3:focus-visible { outline: 3px solid var(--focus); outline-offset: 2px; }
        .person-form .grid { margin-bottom: 16px; }
        a.btn-small, label.btn-small { display: inline-flex; align-items: center; text-decoration: none; }
        .csv { margin-top: 20px; padding: 16px 18px; border: 1px dashed #cbd5e1; border-radius: 10px; }
        .csv h3 { margin: 0 0 4px; }
        .csv-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
        .csv-upload:focus-within { outline: 3px solid var(--focus); outline-offset: 2px; }
        .csv #csv-client-status { margin-top: 10px; }
        .csv-status { margin: 12px 0 0; }
        .csv-status:focus { outline: none; }
        .problems { margin-top: 20px; }
        .problems-head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; margin-bottom: 10px; }
        .problems-head h3 { margin: 0; }
        .problems-head h3:focus { outline: none; }
        .problems .count { font-size: 14px; font-weight: 400; color: var(--muted); }
        .tag-row { background: #fee2e2; color: var(--error); font-weight: 600; }

        /* Screen 6 · Review & payment */
        .review { display: grid; gap: 12px; margin-bottom: 24px; }
        .review-block { padding: 14px 16px; border: 1px solid var(--line); border-radius: 10px; }
        .review-head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; margin-bottom: 8px; }
        .review-head h3 { margin: 0; }
        .review-list { display: grid; grid-template-columns: minmax(120px, 180px) minmax(0, 1fr); gap: 6px 16px; margin: 0; font-size: 14px; }
        .review-list dt { color: var(--muted); }
        .review-list dd { margin: 0; overflow-wrap: anywhere; }
        .review-note { display: block; font-size: 13px; color: var(--muted); }
        .review-people li .review-note, .review-list dd .review-note { display: inline; margin-left: 6px; }
        .review-list dd .review-note { display: block; margin-left: 0; }
        .review-counts { margin: 0 0 8px; font-size: 14px; }
        .review-people summary { cursor: pointer; color: var(--navy-600); font-size: 14px; }
        .review-people ol { margin: 8px 0 0; padding-left: 22px; font-size: 14px; max-height: 320px; overflow: auto; }
        .review-total { display: grid; grid-template-columns: 1fr auto; align-items: baseline; gap: 4px 12px; padding: 14px 16px;
                        border-radius: 10px; background: #f8fafc; border: 1px solid var(--line); }
        .review-total strong { font-size: 24px; color: var(--navy); }
        .review-total .review-note { grid-column: 1 / -1; }
        .consents { margin-bottom: 20px; }
        .consents .check { margin-bottom: 10px; }
        .consents .check + .error { margin: -6px 0 10px 28px; }
        .payment h3 { margin: 0 0 10px; }
        .payment .placeholder { margin-bottom: 14px; }
        .btn.pay { margin-left: 0; }
        .payment .hint { margin-top: 8px; }
        .payment-message { margin: 12px 0 0; }
        .payment-message:focus { outline: none; }
        @media (max-width: 640px) {
            .review-list { grid-template-columns: 1fr; gap: 0; }
            .review-list dd { margin-bottom: 8px; }
            .btn.pay { width: 100%; }
        }

        /* Save and continue later */
        .save-later { margin-top: 16px; }
        .save-later-panel { margin-top: 12px; padding: 16px 18px; border: 1px solid var(--line); border-radius: 10px; background: #f9fafb; }
        .save-later-panel h3 { margin: 0 0 6px; }
        .save-later-panel h3:focus { outline: none; }
        .save-later-panel .field { margin: 12px 0 0; }
        .save-later-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 12px 20px; margin-top: 14px; }
        .save-later-status { margin: 12px 0 0; }
        .save-later-status:focus { outline: none; }
        .link-unavailable-list { margin: 0 0 8px; padding-left: 20px; color: #374151; }
        a.btn { display: inline-block; text-decoration: none; }

        /* Payment (screen 6) */
        .payment-intro { margin: 0 0 12px; }
        .payment-element { min-height: 120px; margin-bottom: 12px; }
        .payment-client-error { margin: 0 0 12px; }
        .payment-summary { display: grid; grid-template-columns: 1fr auto; gap: 6px 16px; margin: 4px 0 16px; padding: 14px 16px;
                           border: 1px solid var(--line); border-radius: 10px; background: #f8fafc; font-size: 14px; }
        .payment-summary:focus { outline: none; }
        .payment-summary dt { color: var(--muted); }
        .payment-summary dd { margin: 0; text-align: right; font-weight: 600; overflow-wrap: anywhere; }
        .payment-summary .payment-total { padding-top: 6px; border-top: 1px solid var(--line); color: #111827; font-size: 16px; }
        .payment-summary dd.payment-total { color: var(--navy); font-size: 20px; }
        .payment-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 12px 20px; }
        .payment-busy { margin-top: 10px; }
        @media (max-width: 640px) { .payment-actions .btn.pay { width: 100%; } }

        /* Processing and enrolled */
        .status-card h2 { margin-top: 10px; }
        .status-badge { background: #fef3c7; color: #92400e; }
        .status-badge-ok { background: var(--ok-bg); color: var(--ok); }
        .status-card .section-title { display: block; margin: 22px 0 10px; }
        .status-note { margin-top: 14px; }
        .next-steps { margin: 0; padding-left: 20px; color: #374151; }
        .next-steps li { margin-bottom: 6px; }
        .choices-compact .choice { padding: 12px 14px; }
        .choices-compact .choice span { font-size: 13px; }
        .person-form-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 12px 20px; }
        @media (max-width: 640px) {
            .person-form { padding: 14px; }
            .person-actions { width: 100%; }
            .person-actions .btn-small { flex: 1; }
        }

        .footer { margin: 24px 0; font-size: 12px; color: var(--muted); text-align: center; }
    </style>
    @livewireStyles
</head>
<body>
<div class="page">
    <img class="brand" src="{{ asset('portal/img/logo-color.svg') }}" alt="ProPerX Alliance">
    <h1>Enroll your agency</h1>

    {{ $slot }}

    <div class="footer">Questions? Contact ProPerX Alliance. Your answers are saved after each step.</div>
</div>
@livewireScripts
<script src="{{ \App\Support\PortalAsset::url('join-csv.js') }}" defer nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
@if (\App\Services\Agencies\AgencyCheckout::isConfigured() && request()->routeIs('join.agency'))
    {{-- Stripe.js from Stripe only (its terms; the CSP allows js.stripe.com on this page). --}}
    <script src="https://js.stripe.com/v3/" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
    <script src="{{ \App\Support\PortalAsset::url('join-payment.js') }}" defer nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
@endif
{{-- Focus requested by the wizard (Wizard::focus), once Livewire has updated the page. --}}
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
    window.addEventListener('join-focus', function (event) {
        var id = event.detail && event.detail.id;

        requestAnimationFrame(function () {
            var target = id && document.getElementById(id);

            if (target) {
                target.focus();
            }
        });
    });
</script>
</body>
</html>
