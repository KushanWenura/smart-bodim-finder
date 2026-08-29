$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$backendRoot = Join-Path $projectRoot 'backend'
$frontendRoot = Join-Path $projectRoot 'frontend'
$aiRoot = Join-Path $projectRoot 'ai-service'
$pidFile = Join-Path $PSScriptRoot '.pids.json'
$logRoot = Join-Path $PSScriptRoot 'logs'
$env:XDEBUG_MODE = 'off'
New-Item -ItemType Directory -Path $logRoot -Force | Out-Null

if (Test-Path -LiteralPath $pidFile) {
    try {
        $previousState = Get-Content -LiteralPath $pidFile -Raw | ConvertFrom-Json
        $liveProcesses = @($previousState.processIds | Where-Object { Get-Process -Id $_ -ErrorAction SilentlyContinue })
    } catch {
        $liveProcesses = @()
    }
    if ($liveProcesses.Count -gt 0) {
        Write-Host 'Smart Bodim services are already running. Open http://127.0.0.1:5173 or run .\Run\stop-all.ps1 first.' -ForegroundColor Yellow
        exit 1
    }
    Remove-Item -LiteralPath $pidFile -Force
    Write-Host 'Removed an old launcher state from services that had already stopped.' -ForegroundColor Yellow
}

$servicePorts = @(5100, 5173, 8000)
$occupiedPorts = @(
    Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue |
        Where-Object { $_.LocalPort -in $servicePorts } |
        Select-Object -ExpandProperty LocalPort -Unique
)
if ($occupiedPorts.Count -gt 0) {
    $portList = ($occupiedPorts | Sort-Object) -join ', '
    throw "Local service port(s) $portList are already in use. Run .\Run\stop-all.ps1, then start again."
}

$preferredPhp = 'C:\wamp64\bin\php\php8.3.14\php.exe'
$php = if (Test-Path -LiteralPath $preferredPhp) { $preferredPhp } else {
    Get-ChildItem -LiteralPath 'C:\wamp64\bin\php' -Filter 'php.exe' -Recurse -ErrorAction SilentlyContinue |
        Sort-Object FullName -Descending |
        Select-Object -First 1 -ExpandProperty FullName
}
if (-not $php) { $php = (Get-Command php -ErrorAction SilentlyContinue).Source }

$pnpm = (Get-Command pnpm -ErrorAction SilentlyContinue).Source
if (-not $pnpm) {
    $bundledPnpm = 'C:\Users\User\.cache\codex-runtimes\codex-primary-runtime\dependencies\bin\fallback\pnpm.cmd'
    if (Test-Path -LiteralPath $bundledPnpm) { $pnpm = $bundledPnpm }
}

$node = (Get-Command node -ErrorAction SilentlyContinue).Source
if (-not $node) {
    $bundledNode = 'C:\Users\User\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe'
    if (Test-Path -LiteralPath $bundledNode) { $node = $bundledNode }
}

$systemPython = (Get-Command python -ErrorAction SilentlyContinue).Source
$python = @(
    (Join-Path $aiRoot '.venv312\Scripts\python.exe'),
    (Join-Path $aiRoot '.venv\Scripts\python.exe')
) | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1

if (-not $php) { throw 'PHP was not found. Install PHP/WAMP or add php.exe to PATH.' }
if (-not $pnpm) { throw 'pnpm was not found. Install Node.js and run: corepack enable' }
if (-not $node) { throw 'Node.js was not found. Install Node.js or add node.exe to PATH.' }
if (-not $python -and -not $systemPython) { throw 'Python 3.12 was not found. Install Python or add python.exe to PATH.' }

if (-not (Test-Path -LiteralPath (Join-Path $backendRoot 'vendor\autoload.php'))) {
    Write-Host 'First run: installing Laravel dependencies…' -ForegroundColor Cyan
    Push-Location $backendRoot
    try { & $php '..\tools\composer.phar' install --no-interaction --prefer-dist }
    finally { Pop-Location }
    if ($LASTEXITCODE -ne 0) { throw 'Composer dependency installation failed.' }
}

function Test-FrontendRuntime {
    $viteCli = Join-Path $frontendRoot 'node_modules\vite\bin\vite.js'
    if (-not (Test-Path -LiteralPath $viteCli)) { return $false }
    & $node $viteCli --version *> $null
    return $LASTEXITCODE -eq 0
}

if (-not (Test-FrontendRuntime)) {
    Write-Host 'Installing or repairing frontend dependencies...' -ForegroundColor Cyan
    $previousCI = $env:CI
    $env:CI = 'true'
    Push-Location $frontendRoot
    try { & $pnpm install --force --frozen-lockfile }
    finally {
        Pop-Location
        if ($null -eq $previousCI) { Remove-Item Env:CI -ErrorAction SilentlyContinue } else { $env:CI = $previousCI }
    }
    if ($LASTEXITCODE -ne 0) { throw 'Frontend dependency installation failed.' }
    if (-not (Test-FrontendRuntime)) { throw 'Frontend dependencies were installed, but Vite still cannot start. Delete frontend\node_modules and run pnpm install.' }
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
} else {
    Push-Location $backendRoot
    try { & $php artisan migrate --force }
    finally { Pop-Location }
}

Push-Location $backendRoot
try { & $php artisan db:seed --class=Database\Seeders\InstitutionSeeder --force }
finally { Pop-Location }

$env:AI_PROFILE = 'base'
$env:SEARCH_MODEL_PATH = Join-Path $projectRoot 'models\smart-bodim-minilm-v1'
$env:SEARCH_MODEL_VERSION = 'smart-bodim-minilm-v1'
$env:LOAD_SENTIMENT_MODEL = '0'
$env:AI_INTERNAL_SECRET = 'change-this-in-every-shared-environment'

$viteCli = Join-Path $frontendRoot 'node_modules\vite\bin\vite.js'
$processes = @(
    Start-Process -FilePath $php -ArgumentList @('artisan', 'serve', '--host=127.0.0.1', '--port=8000') -WorkingDirectory $backendRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logRoot 'api.out.log') -RedirectStandardError (Join-Path $logRoot 'api.error.log') -PassThru
    Start-Process -FilePath $php -ArgumentList @('artisan', 'queue:work', '--tries=3') -WorkingDirectory $backendRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logRoot 'queue.out.log') -RedirectStandardError (Join-Path $logRoot 'queue.error.log') -PassThru
    Start-Process -FilePath $php -ArgumentList @('artisan', 'schedule:work') -WorkingDirectory $backendRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logRoot 'scheduler.out.log') -RedirectStandardError (Join-Path $logRoot 'scheduler.error.log') -PassThru
    Start-Process -FilePath $python -ArgumentList @('app.py') -WorkingDirectory $aiRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logRoot 'ai.out.log') -RedirectStandardError (Join-Path $logRoot 'ai.error.log') -PassThru
    Start-Process -FilePath $node -ArgumentList @("`"$viteCli`"", '--host', '127.0.0.1', '--port', '5173') -WorkingDirectory $frontendRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logRoot 'frontend.out.log') -RedirectStandardError (Join-Path $logRoot 'frontend.error.log') -PassThru
)

@{
    startedAt = (Get-Date).ToString('o')
    processIds = @($processes.Id)
} | ConvertTo-Json | Set-Content -LiteralPath $pidFile -Encoding UTF8

function Test-Endpoint([string] $Uri) {
    try {
        return (Invoke-WebRequest -Uri $Uri -UseBasicParsing -TimeoutSec 2).StatusCode -eq 200
    } catch {
        return $false
    }
}

Write-Host 'Starting Website, API and trained AI services...' -ForegroundColor Cyan
$websiteReady = $false
$apiReady = $false
$aiReady = $false
$deadline = (Get-Date).AddSeconds(120)
do {
    Start-Sleep -Seconds 2
    $websiteReady = Test-Endpoint 'http://127.0.0.1:5173/'
    $apiReady = Test-Endpoint 'http://127.0.0.1:8000/api/v1/health'
    try {
        $health = Invoke-RestMethod -Uri 'http://127.0.0.1:5100/health' -TimeoutSec 2
        $aiReady = $health.service -eq 'healthy' -and $health.modelReady -and $health.queryIntentReady
    } catch {
        $aiReady = $false
    }
    $criticalProcessExited = $processes[0].HasExited -or $processes[4].HasExited
} while (-not $criticalProcessExited -and -not ($websiteReady -and $apiReady -and ($aiReady -or $processes[3].HasExited)) -and (Get-Date) -lt $deadline)

if (-not $websiteReady -or -not $apiReady) {
    $failed = @()
    if (-not $websiteReady) { $failed += 'Website' }
    if (-not $apiReady) { $failed += 'API' }
    Write-Host ''
    Write-Host ('Startup failed: ' + ($failed -join ' and ') + ' did not become ready.') -ForegroundColor Red
    foreach ($name in @('frontend.error.log', 'api.error.log')) {
        $path = Join-Path $logRoot $name
        if ((Test-Path -LiteralPath $path) -and (Get-Item -LiteralPath $path).Length -gt 0) {
            Write-Host ("--- $name ---") -ForegroundColor Yellow
            Get-Content -LiteralPath $path -Tail 12
        }
    }
    & (Join-Path $PSScriptRoot 'stop-all.ps1')
    throw 'BodimBuddy.lk did not start. Read Run\logs\frontend.error.log and Run\logs\api.error.log for details.'
}

if ($aiReady) {
    Push-Location $backendRoot
    try { & $php artisan ai:index-rebuild --sync }
    finally { Pop-Location }
    if ($LASTEXITCODE -ne 0) {
        Write-Warning 'The trained model is ready, but the semantic listing index could not be synchronized. Structured search remains available.'
    } else {
        Write-Host 'BodimBuddy.lk started. Search, query-intent and listing index data are ready.' -ForegroundColor Green
    }
} elseif ($processes[3].HasExited) {
    Write-Warning 'Website and API are ready, but the AI process exited. Search will use its safe fallback. See Run\logs\ai.error.log.'
} else {
    Write-Warning 'Website and API are ready, but the trained model is still loading. Refresh the site in a moment.'
}
Write-Host 'Website: http://127.0.0.1:5173'
Write-Host 'API:     http://127.0.0.1:8000/api/v1/health'
Write-Host 'AI:      http://127.0.0.1:5100/health'
Write-Host 'Stop all services with: .\Run\stop-all.ps1'
