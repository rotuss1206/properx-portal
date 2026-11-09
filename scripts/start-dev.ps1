# Opens the local development services, each in its own titled PowerShell window that stays open.
#
#   powershell -ExecutionPolicy Bypass -File scripts\start-dev.ps1
#
# Each command travels as -EncodedCommand, so its quotes and ">" need no escaping.

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot

function Test-PortListening([int] $Port) {
    return [Net.NetworkInformation.IPGlobalProperties]::GetIPGlobalProperties().GetActiveTcpListeners().Port -contains $Port
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

Write-Host 'Start OSPanel as administrator first.' -ForegroundColor Yellow

if (-not (Get-Process mysqld -ErrorAction SilentlyContinue)) {
    Write-Host 'OSPanel does not seem to be running: there is no database process.' -ForegroundColor Yellow
    Read-Host 'Start it, then press Enter to continue'
}

$php = Get-PhpPath

if (Test-PortListening 10000) {
    Write-Host '  skipped: Azurite, port 10000 is already in use'
} else {
    Open-DevWindow 'Azurite' 'npm run azurite'
}

Open-DevWindow 'Mailpit' 'mailpit'
Open-DevWindow 'Stripe -> site' 'stripe listen --all-snapshot --forward-to "https://properx-org.local/?callback=gravityformsstripe" --skip-verify'
Open-DevWindow 'Stripe -> portal' 'stripe listen --all-snapshot --forward-to https://properx-portal.local/api/webhooks/stripe --skip-verify'
Open-DevWindow 'Queue' ('& ' + (ConvertTo-QuotedLiteral $php) + ' artisan queue:work')

Start-Sleep -Seconds 4
