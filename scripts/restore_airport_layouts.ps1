param(
    [string]$ProjectRoot = (Split-Path -Parent $PSScriptRoot)
)

$archive = Join-Path $ProjectRoot 'backup-data\airport-layouts.tar.gz'
$dataDirectory = Join-Path $ProjectRoot 'htdocs\data'

if (-not (Test-Path -LiteralPath $archive)) {
    throw "Airport-Layout-Archiv nicht gefunden: $archive"
}

New-Item -ItemType Directory -Path $dataDirectory -Force | Out-Null
& tar.exe -xzf $archive -C $dataDirectory
if ($LASTEXITCODE -ne 0) {
    throw "Das Airport-Layout-Archiv konnte nicht entpackt werden."
}

Write-Host "Airport-Layouts wurden nach $dataDirectory wiederhergestellt."
