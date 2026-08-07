param(
    [Parameter(Mandatory = $true)][string]$OutputPath,
    [Parameter(Mandatory = $true)][string]$Text
)

$ErrorActionPreference = 'Stop'
$directory = Split-Path -Parent $OutputPath
if (-not (Test-Path -LiteralPath $directory)) {
    New-Item -ItemType Directory -Path $directory -Force | Out-Null
}
$voice = New-Object -ComObject SAPI.SpVoice
$stream = New-Object -ComObject SAPI.SpFileStream
try {
    $englishVoice = @($voice.GetVoices()) |
        Where-Object { $_.GetDescription() -like '*Zira*' } |
        Select-Object -First 1
    if ($englishVoice) { $voice.Voice = $englishVoice }
    $voice.Rate = -1
    $voice.Volume = 100
    $stream.Format.Type = 18 # 16 kHz, 16-bit, mono PCM
    $stream.Open($OutputPath, 3, $false)
    $voice.AudioOutputStream = $stream
    [void]$voice.Speak($Text)
} finally {
    try { $stream.Close() } catch { }
    [Runtime.InteropServices.Marshal]::FinalReleaseComObject($stream) | Out-Null
    [Runtime.InteropServices.Marshal]::FinalReleaseComObject($voice) | Out-Null
}
