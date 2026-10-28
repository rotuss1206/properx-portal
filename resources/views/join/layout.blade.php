<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? 'Enroll your agency' }} · ProPerx Alliance</title>
    {{-- Same look as the other public pages (certificates/layout), wider for the form. --}}
    <style>
        :root { --navy: #1e3a5f; --navy-600: #21579d; --gold: #c8a24a; --muted: #6b7280; --line: #e5e7eb;
                --error: #b91c1c; --ok-bg: #ecfdf5; --ok: #065f46; --focus: #2563eb; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; padding: 24px 16px 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
               background: #f3f4f6; color: #1f2937; line-height: 1.5; }
        .page { max-width: 1100px; margin: 0 auto; }
        .brand { font-size: 13px; letter-spacing: 4px; text-transform: uppercase; color: var(--navy); font-weight: 600; }
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

        .footer { margin: 24px 0; font-size: 12px; color: var(--muted); text-align: center; }
    </style>
    @livewireStyles
</head>
<body>
<div class="page">
    <div class="brand">ProPerx Alliance</div>
    <h1>Enroll your agency</h1>

    {{ $slot }}

    <div class="footer">Questions? Contact ProPerx Alliance. Your answers are saved after each step.</div>
</div>
@livewireScripts
</body>
</html>
