<#
.SYNOPSIS
    Builds the WordPress plugin package: dist/properx-portal-<version>.zip, for
    Plugins > Add New > Upload Plugin. The version comes from the plugin's header.
#>
$ErrorActionPreference = 'Stop'

$root    = Split-Path -Parent $PSScriptRoot
$source  = Join-Path $root 'integrations\wordpress\properx-portal'
$main    = Join-Path $source 'properx-portal.php'
$dist    = Join-Path $root 'dist'

$versionLine = Select-String -LiteralPath $main -Pattern '^\s*\*\s*Version:\s*(\S+)\s*$' | Select-Object -First 1

if (-not $versionLine) {
    throw "No Version line in the header of $main"
}

$version = $versionLine.Matches[0].Groups[1].Value
$target  = Join-Path $dist "properx-portal-$version.zip"

New-Item -ItemType Directory -Force -Path $dist | Out-Null

if (Test-Path -LiteralPath $target) {
    Remove-Item -LiteralPath $target -Force -Confirm:$false
}

# Written entry by entry: Compress-Archive on Windows PowerShell stores backslashes in the paths,
# which WordPress on Linux unpacks into one flat folder with odd file names.
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$zip = [System.IO.Compression.ZipFile]::Open($target, [System.IO.Compression.ZipArchiveMode]::Create)

try {
    Get-ChildItem -LiteralPath $source -Recurse -File | Sort-Object FullName | ForEach-Object {
        $relative = $_.FullName.Substring($source.Length + 1).Replace('\', '/')

        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $_.FullName, "properx-portal/$relative") | Out-Null
    }
}
finally {
    $zip.Dispose()
}

# The package goes to someone else's site: tools, people, local paths and our test domains stay out of it.
# Every file is read byte for byte, so image metadata counts too; a package with a finding is deleted.
$traces = @(
    'claude', 'anthropic', 'co-authored', 'copilot', 'chatgpt', 'openai',
    'kevin', 'sergey', 'cheverda', 'vasyl', 'rotuss',
    'ospanel', '[a-z]:\\', '/users/', '/home/',
    '[a-z0-9-]\.local\b', '[a-z0-9-]\.test\b', 'example\.test', 'properx-check'
)
$email    = '[a-z0-9._%+-]+@[a-z0-9-]+(\.[a-z0-9-]+)*\.[a-z]{2,}'
$binary   = @('.png', '.jpg', '.gif', '.ico', '.woff', '.woff2')
$bytes    = [System.Text.Encoding]::GetEncoding(28591)
$found    = New-Object System.Collections.ArrayList
$options  = [System.Text.RegularExpressions.RegexOptions]::IgnoreCase

$archive = [System.IO.Compression.ZipFile]::OpenRead($target)

try {
    foreach ($entry in $archive.Entries) {
        $stream = $entry.Open()
        $buffer = New-Object System.IO.MemoryStream

        try {
            $stream.CopyTo($buffer)
        }
        finally {
            $stream.Dispose()
        }

        $text     = $entry.FullName + "`n" + $bytes.GetString($buffer.ToArray())
        $patterns = $traces

        # Compressed image data can look like an address by chance; the words above are long enough not to.
        if ($binary -notcontains [System.IO.Path]::GetExtension($entry.Name).ToLowerInvariant()) {
            $patterns = $traces + $email
        }

        foreach ($pattern in $patterns) {
            foreach ($match in [System.Text.RegularExpressions.Regex]::Matches($text, $pattern, $options)) {
                [void] $found.Add("$($entry.FullName): $($match.Value)")
            }
        }
    }
}
finally {
    $archive.Dispose()
}

if ($found.Count -gt 0) {
    Remove-Item -LiteralPath $target -Force -Confirm:$false
    $found | ForEach-Object { Write-Host "  $_" -ForegroundColor Red }

    throw "The package holds $($found.Count) trace(s) and was deleted. Remove them from $source and build again."
}

Write-Host "Built $target (checked: no traces)"
