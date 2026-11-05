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

Write-Host "Built $target"
