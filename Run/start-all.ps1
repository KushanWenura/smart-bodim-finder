$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$backendRoot = Join-Path $projectRoot 'backend'
$frontendRoot = Join-Path $projectRoot 'frontend'
$aiRoot = Join-Path $projectRoot 'ai-service'
$pidFile = Join-Path $PSScriptRoot '.pids.json'

if (Test-Path -LiteralPath $pidFile) {
    Write-Host 'Smart Bodim services appear to be running. Run .\Run\stop-all.ps1 first.' -ForegroundColor Yellow
    exit 1
}

$php = (Get-Command php -ErrorAction SilentlyContinue).Source
if (-not $php) {
    $php = Get-ChildItem -LiteralPath 'C:\wamp64\bin\php' -Filter 'php.exe' -Recurse -ErrorAction SilentlyContinue |
        Sort-Object FullName -Descending |
        Select-Object -First 1 -ExpandProperty FullName
}

$pnpm = (Get-Command pnpm -ErrorAction SilentlyContinue).Source
if (-not $pnpm) {
    $bundledPnpm = 'C:\Users\User\.cache\codex-runtimes\codex-primary-runtime\dependencies\bin\fallback\pnpm.cmd'
    if (Test-Path -LiteralPath $bundledPnpm) { $pnpm = $bundledPnpm }
}

$systemPython = (Get-Command python -ErrorAction SilentlyContinue).Source
$python = @(
    (Join-Path $aiRoot '.venv312\Scripts\python.exe'),
    (Join-Path $aiRoot '.venv\Scripts\python.exe')
) | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1

if (-not $php) { throw 'PHP was not found. Install PHP/WAMP or add php.exe to PATH.' }
if (-not $pnpm) { throw 'pnpm was not found. Install Node.js and run: corepack enable' }
if (-not $python -and -not $systemPython) { throw 'Python 3.12 was not found. Install Python or add python.exe to PATH.' }

if (-not (Test-Path -LiteralPath (Join-Path $backendRoot 'vendor\autoload.php'))) {
    Write-Host 'First run: installing Laravel dependencies…' -ForegroundColor Cyan
    Push-Location $backendRoot
    try { & $php '..\tools\composer.phar' install --no-interaction --prefer-dist }
    finally { Pop-Location }
    if ($LASTEXITCODE -ne 0) { throw 'Composer dependency installation failed.' }
}

if (-not (Test-Path -LiteralPath (Join-Path $frontendRoot 'node_modules'))) {
    Write-Host 'First run: installing frontend dependencies…' -ForegroundColor Cyan
    Push-Location $frontendRoot
    try { & $pnpm install }
    finally { Pop-Location }
    if ($LASTEXITCODE -ne 0) { throw 'Frontend dependency installation failed.' }
}

if (-not $python) {
    Write-Host 'First run: creating the Python environment…' -ForegroundColor Cyan
    & $systemPython -m venv (Join-Path $aiRoot '.venv312')
    $python = Join-Path $aiRoot '.venv312\Scripts\python.exe'
    & $python -m pip install -r (Join-Path $aiRoot 'requirements.txt')
    if ($LASTEXITCODE -ne 0) { throw 'AI dependency installation failed.' }
}

$backendEnv = Join-Path $backendRoot '.env'
if (-not (Test-Path -LiteralPath $backendEnv)) {
    Copy-Item -LiteralPath (Join-Path $backendRoot '.env.example') -Destination $backendEnv
    Push-Location $backendRoot
    try { & $php artisan key:generate }
    finally { Pop-Location }
}

$sqlite = Join-Path $backendRoot 'database\database.sqlite'
if (-not (Test-Path -LiteralPath $sqlite)) {
    New-Item -ItemType File -Path $sqlite | Out-Null
    Push-Location $backendRoot
    try { & $php artisan migrate --seed --force }
    finally { Pop-Location }
}

$env:AI_PROFILE = 'base'
$env:SEARCH_MODEL_PATH = Join-Path $projectRoot 'models\smart-bodim-minilm-v1'
$env:SEARCH_MODEL_VERSION = 'smart-bodim-minilm-v1'
$env:LOAD_SENTIMENT_MODEL = '0'
$env:AI_INTERNAL_SECRET = 'change-this-in-every-shared-environment'

$processes = @(
    Start-Process -FilePath $php -ArgumentList @('artisan', 'serve', '--host=127.0.0.1', '--port=8000') -WorkingDirectory $backendRoot -WindowStyle Hidden -PassThru
    Start-Process -FilePath $php -ArgumentList @('artisan', 'queue:work', '--tries=3') -WorkingDirectory $backendRoot -WindowStyle Hidden -PassThru
    Start-Process -FilePath $python -ArgumentList @('app.py') -WorkingDirectory $aiRoot -WindowStyle Hidden -PassThru
    Start-Process -FilePath $pnpm -ArgumentList @('dev', '--host', '127.0.0.1') -WorkingDirectory $frontendRoot -WindowStyle Hidden -PassThru
)

@{
    startedAt = (Get-Date).ToString('o')
    processIds = @($processes.Id)
} | ConvertTo-Json | Set-Content -LiteralPath $pidFile -Encoding UTF8

Write-Host 'Starting services and loading the trained search model…' -ForegroundColor Cyan
$ready = $false
$deadline = (Get-Date).AddSeconds(90)
do {
    Start-Sleep -Seconds 2
    try {
        $health = Invoke-RestMethod -Uri 'http://127.0.0.1:5100/health' -TimeoutSec 2
        $ready = $health.service -eq 'healthy'
    } catch {
        $ready = $false
    }
} while (-not $ready -and (Get-Date) -lt $deadline -and -not $processes[2].HasExited)

if ($ready) {
    Write-Host 'Smart Bodim Finder started. Trained AI is ready.' -ForegroundColor Green
} elseif ($processes[2].HasExited) {
    Write-Warning 'The website started, but the AI process exited. Search will use its safe fallback until the AI service is restarted.'
} else {
    Write-Warning 'The website started, but the trained model is still loading. Refresh the site in a moment.'
}
Write-Host 'Website: http://127.0.0.1:5173'
Write-Host 'API:     http://127.0.0.1:8000/api/v1/health'
Write-Host 'AI:      http://127.0.0.1:5100/health'
Write-Host 'Stop all services with: .\Run\stop-all.ps1'
