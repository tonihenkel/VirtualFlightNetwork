param(
    [Parameter(Mandatory = $true)]
    [string]$XplFile,

    [Parameter(Mandatory = $true)]
    [string]$LinuxXplFile,

    [Parameter(Mandatory = $true)]
    [string]$ResourceDir,

    [Parameter(Mandatory = $true)]
    [string]$CslDownloaderDir,

    [Parameter(Mandatory = $true)]
    [string]$ZipFile,

    [Parameter(Mandatory = $true)]
    [string]$HashFile
)

$ErrorActionPreference = 'Stop'

foreach ($requiredPath in @($XplFile, $LinuxXplFile, $ResourceDir, $CslDownloaderDir)) {
    if (-not (Test-Path -LiteralPath $requiredPath)) {
        throw "Release source is missing: $requiredPath"
    }
}

$downloadDir = Split-Path -Parent $ZipFile
$stageDir = Join-Path $downloadDir '_vfn_release_stage'
$zipDirectory = Split-Path -Parent $ZipFile

# Keep only the established latest release archive. Remove all obsolete
# platform-specific packages left by earlier packaging versions.
foreach ($obsoletePackage in @(
    (Join-Path $zipDirectory '_FlightRadarPlugin - Windows.zip'),
    (Join-Path $zipDirectory '_FlightRadarPlugin - Windows.zip.sha256'),
    (Join-Path $zipDirectory '_FlightRadarPlugin - Linux.zip'),
    (Join-Path $zipDirectory '_FlightRadarPlugin - Linux.zip.sha256'),
    (Join-Path $zipDirectory '_FlightRadarPlugin_Windows_latest.zip'),
    (Join-Path $zipDirectory '_FlightRadarPlugin_Windows_latest.zip.sha256'),
    (Join-Path $zipDirectory '_FlightRadarPlugin_Linux_latest.zip'),
    (Join-Path $zipDirectory '_FlightRadarPlugin_Linux_latest.zip.sha256')
)) {
    if (Test-Path -LiteralPath $obsoletePackage) {
        Remove-Item -LiteralPath $obsoletePackage -Force
    }
}

if (Test-Path -LiteralPath $stageDir) {
    Remove-Item -LiteralPath $stageDir -Recurse -Force
}

try {
    # Windows intentionally uses X-Plane's legacy flat plugin layout. This
    # keeps the XPL directly replaceable by X-Reload during development.
    $windowsPluginsDir = Join-Path $stageDir 'Windows\Resources\plugins'
    New-Item -ItemType Directory -Path $windowsPluginsDir -Force | Out-Null
    Copy-Item -LiteralPath $XplFile -Destination (Join-Path $windowsPluginsDir 'Flight Radar Sim Projekt.xpl') -Force
    Copy-Item -LiteralPath $ResourceDir -Destination (Join-Path $windowsPluginsDir 'resources') -Recurse -Force
    Copy-Item -LiteralPath $CslDownloaderDir -Destination (Join-Path $windowsPluginsDir 'CSL Downloader') -Recurse -Force

    # Linux uses the native multi-platform plugin layout required by X-Plane.
    $linuxPluginsDir = Join-Path $stageDir 'Linux\Resources\plugins'
    $linuxVfnDir = Join-Path $linuxPluginsDir 'VFN'
    $linuxBinaryDir = Join-Path $linuxVfnDir '64'
    New-Item -ItemType Directory -Path $linuxBinaryDir -Force | Out-Null
    Copy-Item -LiteralPath $LinuxXplFile -Destination (Join-Path $linuxBinaryDir 'lin.xpl') -Force
    Copy-Item -LiteralPath $ResourceDir -Destination (Join-Path $linuxVfnDir 'resources') -Recurse -Force
    Copy-Item -LiteralPath $CslDownloaderDir -Destination (Join-Path $linuxPluginsDir 'CSL Downloader') -Recurse -Force

    Compress-Archive `
        -LiteralPath (Join-Path $stageDir 'Windows'), (Join-Path $stageDir 'Linux') `
        -DestinationPath $ZipFile `
        -Force

    $hash = (Get-FileHash -Algorithm SHA256 -LiteralPath $ZipFile).Hash.ToLowerInvariant()
    Set-Content `
        -LiteralPath $HashFile `
        -Value ($hash + '  ' + [IO.Path]::GetFileName($ZipFile)) `
        -Encoding ASCII

}
finally {
    if (Test-Path -LiteralPath $stageDir) {
        Remove-Item -LiteralPath $stageDir -Recurse -Force
    }
}
