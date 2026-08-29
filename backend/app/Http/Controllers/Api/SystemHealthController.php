<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AiServiceClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SystemHealthController extends Controller
{
    public function __invoke(AiServiceClient $ai): JsonResponse
    {
        $database = $this->databaseCheck();
        $storage = is_writable(storage_path('app'));
        $heartbeat = Cache::get('system:scheduler-heartbeat');
        $heartbeatAge = $heartbeat ? abs((int) now()->diffInSeconds(Carbon::parse((string) $heartbeat))) : null;
        $aiStatus = $ai->health();
        $backup = $this->latestBackup();
        $failedJobs = DB::table('failed_jobs')->count();
        $pendingJobs = DB::table('jobs')->count();
        $requiredHealthy = $database && $storage;
        $production = app()->isProduction();
        $frontendOrigins = config('cors.allowed_origins', []);
        $httpsReady = str_starts_with((string) config('app.url'), 'https://') && collect($frontendOrigins)->every(fn ($origin) => str_starts_with((string) $origin, 'https://'));
        $deliveryReady = ! in_array(config('mail.default'), ['log', 'array'], true);
        $workersReady = ! in_array(config('queue.default'), ['sync', 'null'], true);
        $aiSecret = (string) config('services.smart_bodim_ai.secret');
        $secretReady = strlen($aiSecret) >= 24 && ! str_contains(strtolower($aiSecret), 'change') && ! str_contains(strtolower($aiSecret), 'local-ai');
        $productionReady = ! $production || ($httpsReady && $deliveryReady && $workersReady && $secretReady && (bool) config('session.secure'));

        return response()->json([
            'status' => $requiredHealthy ? (($aiStatus['online'] ?? false) && $heartbeatAge !== null && $heartbeatAge <= 180 && $productionReady ? 'healthy' : 'degraded') : 'unhealthy',
            'checkedAt' => now()->toIso8601String(),
            'checks' => [
                ['key' => 'database', 'label' => 'Database', 'status' => $database ? 'healthy' : 'unhealthy', 'detail' => $database ? 'Connection and query execution are available.' : 'Database query failed.'],
                ['key' => 'storage', 'label' => 'Upload storage', 'status' => $storage ? 'healthy' : 'unhealthy', 'detail' => $storage ? 'Application storage is writable.' : 'Application storage is not writable.'],
                ['key' => 'queue', 'label' => 'Background queue', 'status' => $failedJobs > 0 ? 'warning' : 'healthy', 'detail' => "{$pendingJobs} pending and {$failedJobs} failed job(s)."],
                ['key' => 'scheduler', 'label' => 'Scheduler heartbeat', 'status' => $heartbeatAge !== null && $heartbeatAge <= 180 ? 'healthy' : 'warning', 'detail' => $heartbeatAge === null ? 'No recent scheduler heartbeat. Start the scheduler worker.' : "Last heartbeat {$heartbeatAge} seconds ago."],
                ['key' => 'ai', 'label' => 'Buddy AI service', 'status' => ($aiStatus['online'] ?? false) ? 'healthy' : 'warning', 'detail' => ($aiStatus['online'] ?? false) ? 'Model service and health endpoint are available.' : 'Structured filters and keyword fallback remain active.'],
                ['key' => 'routing', 'label' => 'Road routing', 'status' => config('services.routing.url') ? 'configured' : 'warning', 'detail' => config('services.routing.url') ? 'OSRM-compatible routing is configured with an offline fallback.' : 'Offline distance estimates are active.'],
                ['key' => 'backup', 'label' => 'Latest local backup', 'status' => $backup ? 'configured' : 'warning', 'detail' => $backup ? 'Latest archive: '.$backup['name'].' ('.$backup['createdAt'].').' : 'No local backup archive was found.'],
                ['key' => 'cookies', 'label' => 'Session cookie policy', 'status' => config('session.secure') || ! app()->isProduction() ? 'healthy' : 'warning', 'detail' => app()->isProduction() ? (config('session.secure') ? 'Secure cookies are enabled.' : 'Enable SESSION_SECURE_COOKIE for HTTPS.') : 'Local development mode; secure cookies are required in production.'],
                ['key' => 'https', 'label' => 'HTTPS and CORS', 'status' => $httpsReady || ! $production ? 'healthy' : 'warning', 'detail' => $production ? ($httpsReady ? 'Application and allowed frontend origins require HTTPS.' : 'Production URLs or allowed frontend origins are not fully HTTPS.') : 'Development URLs are isolated by the configured CORS allowlist.'],
                ['key' => 'delivery', 'label' => 'Notification delivery', 'status' => $deliveryReady || ! $production ? 'healthy' : 'warning', 'detail' => $production ? ($deliveryReady ? 'A real mail delivery transport is configured.' : 'The mail transport only logs messages; users will not receive email.') : 'Development email is safely captured by the configured local transport.'],
                ['key' => 'workers', 'label' => 'Worker configuration', 'status' => $workersReady || ! $production ? 'healthy' : 'warning', 'detail' => $workersReady ? 'Queued work uses a persistent backend.' : ($production ? 'Configure a persistent queue and supervised worker.' : 'Local synchronous processing is acceptable for development.')],
                ['key' => 'secrets', 'label' => 'Service secret policy', 'status' => $secretReady || ! $production ? 'healthy' : 'warning', 'detail' => $secretReady ? 'The AI service uses a non-placeholder shared secret.' : ($production ? 'Replace the placeholder AI service secret before deployment.' : 'A unique secret is required before shared deployment.')],
            ],
            'environment' => ['name' => app()->environment(), 'debug' => (bool) config('app.debug'), 'timezone' => config('app.timezone')],
            'fallbackPolicy' => 'AI and road-routing outages degrade gracefully; database or storage failures require operator action.',
        ]);
    }

    private function databaseCheck(): bool
    {
        try {
            DB::select('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function latestBackup(): ?array
    {
        $files = glob(base_path('../storage/backups/*.zip')) ?: [];
        if ($files === []) {
            return null;
        }
        usort($files, fn (string $left, string $right) => filemtime($right) <=> filemtime($left));
        $file = $files[0];

        return ['name' => basename($file), 'createdAt' => date(DATE_ATOM, (int) filemtime($file))];
    }
}
