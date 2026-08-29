$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$backupRoot = Join-Path $projectRoot 'storage\backups'
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$workRoot = Join-Path $env:TEMP "bodimbuddy-backup-$stamp"
$archive = Join-Path $backupRoot "bodimbuddy-$stamp.zip"

New-Item -ItemType Directory -Path $backupRoot -Force | Out-Null
New-Item -ItemType Directory -Path $workRoot -Force | Out-Null

$sqlite = Join-Path $projectRoot 'backend\database\database.sqlite'
if (Test-Path -LiteralPath $sqlite) {
    Copy-Item -LiteralPath $sqlite -Destination (Join-Path $workRoot 'database.sqlite')
}

$uploads = Join-Path $projectRoot 'backend\storage\app\public'
if (Test-Path -LiteralPath $uploads) {
    Copy-Item -LiteralPath $uploads -Destination (Join-Path $workRoot 'uploads') -Recurse
}

$manifest = @{
    project = 'BodimBuddy.lk'
    createdAt = (Get-Date).ToString('o')
    includes = @('SQLite database when present', 'uploaded public media')
    note = 'For MySQL production deployments, also enable encrypted provider-managed database snapshots.'
} | ConvertTo-Json
$manifest | Set-Content -LiteralPath (Join-Path $workRoot 'manifest.json') -Encoding UTF8

Compress-Archive -Path (Join-Path $workRoot '*') -DestinationPath $archive -CompressionLevel Optimal
Remove-Item -LiteralPath $workRoot -Recurse -Force
Write-Host "Backup created: $archive" -ForegroundColor Green
