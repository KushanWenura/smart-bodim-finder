param([string]$OutputDirectory = ".\backups")
$ErrorActionPreference = 'Stop'
if (-not $env:MYSQL_DATABASE -or -not $env:MYSQL_USER -or -not $env:MYSQL_PASSWORD) { throw 'Set MYSQL_DATABASE, MYSQL_USER and MYSQL_PASSWORD first.' }
$backupRoot = [System.IO.Path]::GetFullPath($OutputDirectory)
[System.IO.Directory]::CreateDirectory($backupRoot) | Out-Null
$stamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$sqlPath = Join-Path $backupRoot ("smart_bodim_$stamp.sql")
$gzipPath = "$sqlPath.gz"
$env:MYSQL_PWD = $env:MYSQL_PASSWORD
try {
    & mysqldump --host ($env:MYSQL_HOST ?? '127.0.0.1') --port ($env:MYSQL_PORT ?? '3306') --user $env:MYSQL_USER --single-transaction --routines --triggers --databases $env:MYSQL_DATABASE --result-file $sqlPath
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $sqlPath)) { throw 'mysqldump failed.' }
    & gzip -9 $sqlPath
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $gzipPath)) { throw 'gzip failed.' }
    Write-Output $gzipPath
} finally { Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue }
