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

Write-Host 'Start OSPanel as administrator first.' -ForegroundColor Yellow

if (-not (Get-Process mysqld -ErrorAction SilentlyContinue)) {
    Write-Host 'OSPanel does not seem to be running: there is no database process.' -ForegroundColor Yellow
    Read-Host 'Start it, then press Enter to continue'
}

$php = Get-PhpPath

$stripeToSite = 'https://properx-org.local/?callback=gravityformsstripe'
$stripeToPortal = 'https://properx-portal.local/api/webhooks/stripe'

Start-DevService 'Azurite' (Test-PortListening 10000) 'npm run azurite'
Start-DevService 'Mailpit' ((Test-PortListening 8025) -or (Test-PortListening 1025)) 'mailpit'
Start-DevService 'Stripe -> site' (Test-StripeListening $stripeToSite) "stripe listen --all-snapshot --forward-to `"$stripeToSite`" --skip-verify"
Start-DevService 'Stripe -> portal' (Test-StripeListening $stripeToPortal) "stripe listen --all-snapshot --forward-to `"$stripeToPortal`" --skip-verify"
Start-DevService 'Queue' (Test-QueueWorkerRunning) ('& ' + (ConvertTo-QuotedLiteral $php) + ' artisan queue:work')

Start-Sleep -Seconds 4
