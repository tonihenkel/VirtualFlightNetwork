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
$pluginsDir = Join-Path $stageDir 'Resources\plugins'
$vfnDir = Join-Path $pluginsDir 'VFN'
$binaryDir = Join-Path $vfnDir '64'
$resourceTargetDir = Join-Path $vfnDir 'resources'
$cslTargetDir = Join-Path $pluginsDir 'CSL Downloader'
$zipDirectory = Split-Path -Parent $ZipFile
$windowsZipFile = Join-Path $zipDirectory '_FlightRadarPlugin_Windows_latest.zip'
$linuxZipFile = Join-Path $zipDirectory '_FlightRadarPlugin_Linux_latest.zip'

if (Test-Path -LiteralPath $stageDir) {
    Remove-Item -LiteralPath $stageDir -Recurse -Force
}

try {
    New-Item -ItemType Directory -Path $binaryDir -Force | Out-Null
    Copy-Item -LiteralPath $XplFile -Destination (Join-Path $binaryDir 'win.xpl') -Force
    Copy-Item -LiteralPath $LinuxXplFile -Destination (Join-Path $binaryDir 'lin.xpl') -Force
    Copy-Item -LiteralPath $ResourceDir -Destination $resourceTargetDir -Recurse -Force
    Copy-Item -LiteralPath $CslDownloaderDir -Destination $cslTargetDir -Recurse -Force

    Compress-Archive `
        -LiteralPath (Join-Path $stageDir 'Resources') `
        -DestinationPath $ZipFile `
        -Force

    # Produce dedicated platform packages as well. X-Plane accepts a single
    # platform binary in VFN/64, while shared resources and the CSL downloader
    # remain identical in both archives.
    Remove-Item -LiteralPath (Join-Path $binaryDir 'lin.xpl') -Force
    Compress-Archive `
        -LiteralPath (Join-Path $stageDir 'Resources') `
        -DestinationPath $windowsZipFile `
        -Force

    Remove-Item -LiteralPath (Join-Path $binaryDir 'win.xpl') -Force
    Copy-Item -LiteralPath $LinuxXplFile -Destination (Join-Path $binaryDir 'lin.xpl') -Force
    Compress-Archive `
        -LiteralPath (Join-Path $stageDir 'Resources') `
        -DestinationPath $linuxZipFile `
        -Force

    $hash = (Get-FileHash -Algorithm SHA256 -LiteralPath $ZipFile).Hash.ToLowerInvariant()
    Set-Content `
        -LiteralPath $HashFile `
        -Value ($hash + '  ' + [IO.Path]::GetFileName($ZipFile)) `
        -Encoding ASCII

    foreach ($platformZip in @($windowsZipFile, $linuxZipFile)) {
        $platformHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $platformZip).Hash.ToLowerInvariant()
        Set-Content `
            -LiteralPath ($platformZip + '.sha256') `
            -Value ($platformHash + '  ' + [IO.Path]::GetFileName($platformZip)) `
            -Encoding ASCII
    }
}
finally {
    if (Test-Path -LiteralPath $stageDir) {
        Remove-Item -LiteralPath $stageDir -Recurse -Force
    }
}
