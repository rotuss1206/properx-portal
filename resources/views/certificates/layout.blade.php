<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · ProPerx Alliance</title>
    <style>
        :root { --navy: #1e3a5f; --gold: #c8a24a; --muted: #6b7280; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px;
               font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: #f3f4f6; color: #1f2937; }
        .card { width: 100%; max-width: 520px; background: #fff; border-radius: 12px; border-top: 6px solid var(--navy);
                box-shadow: 0 10px 30px rgba(15, 23, 42, .08); padding: 32px; }
        .brand { font-size: 13px; letter-spacing: 4px; text-transform: uppercase; color: var(--navy); font-weight: 600; }
        h1 { font-size: 22px; margin: 8px 0 24px; }
        .status { display: flex; align-items: center; gap: 12px; padding: 14px 16px; border-radius: 8px; font-weight: 600; margin-bottom: 20px; }
        .status .dot { width: 12px; height: 12px; border-radius: 50%; flex: none; }
        .status.valid { background: #ecfdf5; color: #065f46; } .status.valid .dot { background: #10b981; }
        .status.expired { background: #fffbeb; color: #92400e; } .status.expired .dot { background: #f59e0b; }
        .status.invalid { background: #f3f4f6; color: #374151; } .status.invalid .dot { background: #9ca3af; }
        dl { display: grid; grid-template-columns: max-content 1fr; gap: 8px 20px; margin: 0; }
        dt { color: var(--muted); } dd { margin: 0; font-weight: 600; }
        p { line-height: 1.5; color: #374151; }
        .footer { margin-top: 28px; font-size: 12px; color: var(--muted); border-top: 1px solid #e5e7eb; padding-top: 14px; }
        code { font-size: 13px; background: #f3f4f6; padding: 2px 6px; border-radius: 4px; word-break: break-all; }
    </style>
</head>
<body>
<main class="card">
    <div class="brand">ProPerx Alliance</div>
    @yield('content')
</main>
</body>
</html>
