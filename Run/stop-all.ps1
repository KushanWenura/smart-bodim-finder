$ErrorActionPreference = 'Stop'

$pidFile = Join-Path $PSScriptRoot '.pids.json'
if (-not (Test-Path -LiteralPath $pidFile)) {
    Write-Host 'No launcher-managed Smart Bodim services are running.' -ForegroundColor Yellow
    exit 0
}

$state = Get-Content -LiteralPath $pidFile -Raw | ConvertFrom-Json
foreach ($processId in $state.processIds) {
    if (Get-Process -Id $processId -ErrorAction SilentlyContinue) {
        & taskkill.exe /PID $processId /T /F | Out-Null
    }
}

Remove-Item -LiteralPath $pidFile -Force
Write-Host 'Smart Bodim Finder services stopped.' -ForegroundColor Green
