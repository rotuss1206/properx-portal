# Opens the local development services, each in its own titled PowerShell window that stays open.
#
#   powershell -ExecutionPolicy Bypass -File scripts\start-dev.ps1
#   powershell -ExecutionPolicy Bypass -File scripts\start-dev.ps1 -SyncSecretsOnly
#
# Each command travels as -EncodedCommand, so its quotes and ">" need no escaping.
# The Stripe listeners use the portal's test key from .env (STRIPE_SECRET), so no "stripe login" is needed.
# Their signing secret is then written where the events are checked, when it differs: STRIPE_WEBHOOK_SECRET in
# the portal's .env (then config:clear and queue:restart) and the Test Signing Secret of Gravity Forms Stripe
# on the local copy of properx.org. The secret is never printed.

param([switch] $SyncSecretsOnly)

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot

function Test-PortListening([int] $Port) {
    return [Net.NetworkInformation.IPGlobalProperties]::GetIPGlobalProperties().GetActiveTcpListeners().Port -contains $Port
}

function Test-StripeListening([string] $ForwardTo) {
    $listeners = Get-CimInstance Win32_Process -Filter "Name = 'stripe.exe'" | Where-Object {
        $_.CommandLine -and $_.CommandLine -match '\blisten\b' -and $_.CommandLine.Contains("--forward-to $ForwardTo")
    }

    return @($listeners).Count -gt 0
}

# The working directory of another 64-bit process, or $null when it cannot be read. Windows has no
# cmdlet for it, and "php artisan queue:work" names no folder on its command line.
function Get-ProcessDirectory([int] $ProcessId) {
    if (-not ('StartDev.ProcessDirectory' -as [type])) {
        Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;

namespace StartDev {
    public static class ProcessDirectory {
        [DllImport("ntdll.dll")] static extern int NtQueryInformationProcess(IntPtr process, int infoClass, byte[] info, int length, out int returned);
        [DllImport("kernel32.dll")] static extern IntPtr OpenProcess(int access, bool inherit, int id);
        [DllImport("kernel32.dll")] static extern bool ReadProcessMemory(IntPtr process, IntPtr address, byte[] buffer, int size, out IntPtr read);
        [DllImport("kernel32.dll")] static extern bool CloseHandle(IntPtr handle);

        static byte[] Read(IntPtr process, long address, int size) {
            var buffer = new byte[size];
            IntPtr read;
            if (!ReadProcessMemory(process, new IntPtr(address), buffer, size, out read)) {
                throw new InvalidOperationException();
            }
            return buffer;
        }

        public static string Get(int id) {
            // PROCESS_QUERY_INFORMATION | PROCESS_VM_READ
            IntPtr process = OpenProcess(0x0410, false, id);
            if (process == IntPtr.Zero) { return null; }
            try {
                var info = new byte[48];
                int returned;
                if (NtQueryInformationProcess(process, 0, info, info.Length, out returned) != 0) { return null; }
                long peb = BitConverter.ToInt64(info, 8);
                long parameters = BitConverter.ToInt64(Read(process, peb + 0x20, 8), 0);
                int length = BitConverter.ToUInt16(Read(process, parameters + 0x38, 2), 0);
                long buffer = BitConverter.ToInt64(Read(process, parameters + 0x40, 8), 0);
                return System.Text.Encoding.Unicode.GetString(Read(process, buffer, length));
            } catch (InvalidOperationException) {
                return null;
            } finally {
                CloseHandle(process);
            }
        }
    }
}
'@
    }

    return [StartDev.ProcessDirectory]::Get($ProcessId)
}

function Test-QueueWorkerRunning {
    $workers = Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" | Where-Object {
        $_.CommandLine -and $_.CommandLine -match '\bqueue:work\b'
    }

    foreach ($worker in @($workers)) {
        $directory = Get-ProcessDirectory ([int] $worker.ProcessId)
        if ($directory -and $directory.TrimEnd('\') -ieq $root.TrimEnd('\')) {
            return $true
        }
    }

    return $false
}

function Get-PhpPath {
    $onPath = Get-Command php -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($onPath) {
        return $onPath.Source
    }

    # The repository lives in <OSPanel>\home\<site>.
    $osPanel = Split-Path -Parent (Split-Path -Parent $root)
    $php = Join-Path $osPanel 'modules\PHP-8.4\php.exe'
    if (-not (Test-Path -LiteralPath $php)) {
        throw "php is not on PATH and was not found at $php"
    }

    return $php
}

# The portal's STRIPE_SECRET from .env, accepted only as a test-mode key (sk_test_ / rk_test_), so the
# listeners need no "stripe login". The key is never printed: callers hand it to the windows through an
# environment variable, not through the command text.
function Get-StripeTestKey {
    $envFile = Join-Path $root '.env'
    $line = if (Test-Path -LiteralPath $envFile) {
        Get-Content -LiteralPath $envFile | Where-Object { $_ -match '^\s*STRIPE_SECRET\s*=' } | Select-Object -Last 1
    }
    $key = if ($line) { ($line -replace '^\s*STRIPE_SECRET\s*=\s*', '').Trim().Trim('"', "'") } else { '' }

    if ($key -eq '') {
        throw 'STRIPE_SECRET is empty in the portal''s .env. Put the Stripe test-mode secret key (sk_test_...) there; the Stripe listeners use it instead of "stripe login".'
    }

    if (-not ($key.StartsWith('sk_test_') -or $key.StartsWith('rk_test_'))) {
        throw 'STRIPE_SECRET in the portal''s .env is not a test-mode key (sk_test_ / rk_test_). The local listeners never use a live key; put the test key there.'
    }

    return $key
}

# The signing secret "stripe listen" uses for this key. The same for every listener of the key.
function Get-StripeListenSecret([string] $Key) {
    # Through cmd.exe: the CLI writes notices to stderr, which Windows PowerShell would turn into errors.
    $env:PPX_STRIPE_KEY = $Key
    try {
        $secret = (cmd.exe /d /c 'stripe listen --api-key %PPX_STRIPE_KEY% --print-secret 2>nul' | Out-String).Trim()
    } finally {
        Remove-Item Env:PPX_STRIPE_KEY -ErrorAction SilentlyContinue
    }

    if (-not $secret.StartsWith('whsec_')) {
        throw 'stripe listen --print-secret gave no signing secret. Is the Stripe CLI installed and the key valid?'
    }

    return $secret
}

# STRIPE_WEBHOOK_SECRET in the portal's .env, replaced or added when it differs. True when changed.
# The file is read and written as UTF-8 without a BOM, line endings kept.
function Sync-PortalWebhookSecret([string] $Secret) {
    $envFile = Join-Path $root '.env'
    $text = [IO.File]::ReadAllText($envFile, [Text.Encoding]::UTF8)
    $pattern = '(?m)^[ \t]*STRIPE_WEBHOOK_SECRET[ \t]*=.*$'
    $match = [regex]::Match($text, $pattern)
    $current = if ($match.Success) { ($match.Value -replace '^\s*STRIPE_WEBHOOK_SECRET\s*=\s*', '').Trim().Trim('"', "'") } else { '' }

    if ($current -ceq $Secret) {
        return $false
    }

    $line = 'STRIPE_WEBHOOK_SECRET=' + $Secret
    $newline = if ($text.Contains("`r`n")) { "`r`n" } else { "`n" }
    $text = if ($match.Success) {
        ([regex] $pattern).Replace($text, [Text.RegularExpressions.MatchEvaluator] { param($m) $line }, 1)
    } else {
        $text.TrimEnd() + $newline + $line + $newline
    }

    [IO.File]::WriteAllText($envFile, $text, (New-Object Text.UTF8Encoding $false))

    return $true
}

# The Test Signing Secret of Gravity Forms Stripe on the local copy (its option, through wp-load.php).
# Prints only "updated", "unchanged" or "missing". The secret travels in an environment variable.
function Sync-SiteWebhookSecret([string] $Php, [string] $Secret) {
    $site = Join-Path (Split-Path -Parent $root) 'properx-org.local'

    if (-not (Test-Path -LiteralPath (Join-Path $site 'wp-load.php'))) {
        return 'missing'
    }

    $code = @'
$_SERVER['HTTP_HOST'] = 'properx-org.local'; $_SERVER['HTTPS'] = 'on'; define('WP_USE_THEMES', false);
require getenv('PPX_SITE') . '/wp-load.php';
if (wp_get_environment_type() !== 'local') { echo 'not-local'; exit; }
$name = 'gravityformsaddon_gravityformsstripe_settings';
$settings = get_option($name);
if (! is_array($settings)) { echo 'missing'; exit; }
$secret = (string) getenv('PPX_SECRET');
if (($settings['test_signing_secret'] ?? '') === $secret) { echo 'unchanged'; exit; }
$settings['test_signing_secret'] = $secret;
update_option($name, $settings);
echo 'updated';
'@

    $env:PPX_SITE = $site
    $env:PPX_SECRET = $Secret
    try {
        return (& $Php -r $code 2>$null | Out-String).Trim()
    } finally {
        Remove-Item Env:PPX_SITE, Env:PPX_SECRET -ErrorAction SilentlyContinue
    }
}

function Sync-WebhookSecrets([string] $Php, [string] $Key) {
    $secret = Get-StripeListenSecret $Key

    if (Sync-PortalWebhookSecret $secret) {
        & $Php (Join-Path $root 'artisan') config:clear | Out-Null
        & $Php (Join-Path $root 'artisan') queue:restart | Out-Null
        Write-Host '  portal: STRIPE_WEBHOOK_SECRET updated in .env (config cleared, queue restarted)'
    } else {
        Write-Host '  portal: STRIPE_WEBHOOK_SECRET already matches'
    }

    switch (Sync-SiteWebhookSecret $Php $secret) {
        'updated' { Write-Host '  site: Gravity Forms Test Signing Secret updated' }
        'unchanged' { Write-Host '  site: Gravity Forms Test Signing Secret already matches' }
        'not-local' { Write-Host '  site: not changed, the copy is not WP_ENVIRONMENT_TYPE=local' -ForegroundColor Yellow }
        default { Write-Host '  site: properx-org.local or its Stripe settings not found, skipped' -ForegroundColor Yellow }
    }
}

function ConvertTo-QuotedLiteral([string] $Text) {
    return "'" + ($Text -replace "'", "''") + "'"
}

function Open-DevWindow([string] $Title, [string] $Command) {
    # npm runs its script through cmd.exe, which replaces the title, so a background thread keeps setting it.
    $script = @(
        '$titleKeeper = [PowerShell]::Create().AddScript({ while ($true) { [Console]::Title = ' + (ConvertTo-QuotedLiteral $Title) + '; Start-Sleep -Seconds 2 } })'
        '[void] $titleKeeper.BeginInvoke()'
        'Set-Location -LiteralPath ' + (ConvertTo-QuotedLiteral $root)
        $Command
    ) -join "`n"

    $encoded = [Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($script))

    Start-Process powershell.exe -WorkingDirectory $root -ArgumentList @(
        '-NoExit', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', $encoded
    ) | Out-Null

    Write-Host "  opened: $Title"
}

function Start-DevService([string] $Title, [bool] $AlreadyRunning, [string] $Command) {
    if ($AlreadyRunning) {
        Write-Host "  already running: $Title"
        return
    }

    Open-DevWindow $Title $Command
}

if ($SyncSecretsOnly) {
    Write-Host 'Stripe signing secret:'
    Sync-WebhookSecrets (Get-PhpPath) (Get-StripeTestKey)
    return
}

Write-Host 'Start OSPanel as administrator first.' -ForegroundColor Yellow

if (-not (Get-Process mysqld -ErrorAction SilentlyContinue)) {
    Write-Host 'OSPanel does not seem to be running: there is no database process.' -ForegroundColor Yellow
    Read-Host 'Start it, then press Enter to continue'
}

$php = Get-PhpPath
$stripeKey = Get-StripeTestKey

$stripeToSite = 'https://properx-org.local/?callback=gravityformsstripe'
$stripeToPortal = 'https://properx-portal.local/api/webhooks/stripe'

Start-DevService 'Azurite' (Test-PortListening 10000) 'npm run azurite'
Start-DevService 'Mailpit' ((Test-PortListening 8025) -or (Test-PortListening 1025)) 'mailpit'

# The Stripe windows inherit the key as an environment variable for --api-key; it leaves this
# process again before the queue window opens.
$env:PORTAL_STRIPE_TEST_KEY = $stripeKey
try {
    Start-DevService 'Stripe -> site' (Test-StripeListening $stripeToSite) "stripe listen --api-key `$env:PORTAL_STRIPE_TEST_KEY --all-snapshot --forward-to `"$stripeToSite`" --skip-verify"
    Start-DevService 'Stripe -> portal' (Test-StripeListening $stripeToPortal) "stripe listen --api-key `$env:PORTAL_STRIPE_TEST_KEY --all-snapshot --forward-to `"$stripeToPortal`" --skip-verify"
} finally {
    Remove-Item Env:PORTAL_STRIPE_TEST_KEY -ErrorAction SilentlyContinue
}

# Before the queue window: a worker stopped by queue:restart is then opened again with the new settings.
Write-Host 'Stripe signing secret:'
Sync-WebhookSecrets $php $stripeKey

for ($i = 0; $i -lt 15 -and (Test-QueueWorkerRunning) -and ((Get-Item (Join-Path $root '.env')).LastWriteTime -gt (Get-Date).AddSeconds(-30)); $i++) {
    Start-Sleep -Seconds 1
}

Start-DevService 'Queue' (Test-QueueWorkerRunning) ('& ' + (ConvertTo-QuotedLiteral $php) + ' artisan queue:work')

Start-Sleep -Seconds 4
