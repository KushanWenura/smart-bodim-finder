$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$pidFile = Join-Path $PSScriptRoot '.pids.json'
$processIds = @()

if (Test-Path -LiteralPath $pidFile) {
    try {
        $state = Get-Content -LiteralPath $pidFile -Raw | ConvertFrom-Json
        $processIds += @($state.processIds)
    } catch {
        Write-Warning 'The launcher state was unreadable; matching project services will still be discovered safely.'
    }
}

$servicePatterns = @('artisan serve', 'artisan queue:work', 'resources/server.php', 'app.py', 'vite.js')
$projectServices = @(
    Get-CimInstance Win32_Process -ErrorAction SilentlyContinue |
        Where-Object {
            $commandLine = [string] $_.CommandLine
            $matchesService = @($servicePatterns | Where-Object {
                $commandLine.IndexOf($_, [StringComparison]::OrdinalIgnoreCase) -ge 0
            }).Count -gt 0
            $commandLine.IndexOf($projectRoot, [StringComparison]::OrdinalIgnoreCase) -ge 0 -and $matchesService
        }
)
$processIds += @($projectServices.ProcessId)
$processIds = @($processIds | Where-Object { $_ } | Sort-Object -Unique)

if ($processIds.Count -eq 0) {
    if (Test-Path -LiteralPath $pidFile) { Remove-Item -LiteralPath $pidFile -Force }
    Write-Host 'No BodimBuddy.lk services are running.' -ForegroundColor Yellow
    exit 0
}

foreach ($processId in $processIds) {
    Stop-Process -Id $processId -Force -ErrorAction SilentlyContinue
}

if (Test-Path -LiteralPath $pidFile) { Remove-Item -LiteralPath $pidFile -Force }
Write-Host 'BodimBuddy.lk services stopped.' -ForegroundColor Green
