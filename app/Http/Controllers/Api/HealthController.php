<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Documents\StorageHealth;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Health probes for AKS. No authentication; the response reveals only ok/fail per
 * check, never configuration or error details (those go to the log).
 *
 * - GET /api/health/live  — liveness: the PHP process answers (no dependencies,
 *   so an outage of the database does not make Kubernetes restart every pod).
 * - GET /api/health       — readiness: database, cache, queue and document storage.
 */
class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return $this->respond(['status' => 'ok'], Response::HTTP_OK);
    }

    public function ready(StorageHealth $storage): JsonResponse
    {
        $checks = [
            'database' => $this->check('database', fn () => DB::select('select 1')),
            'cache' => $this->check('cache', function () {
                $key = 'health:'.Str::random(12);
                Cache::put($key, 'ok', 10);
                $healthy = Cache::get($key) === 'ok';
                Cache::forget($key);

                return $healthy;
            }),
            'queue' => $this->check('queue', fn () => Queue::size()),
            // A round trip to the disk; the marker file need not exist, only the call must succeed
            // (the azure disk throws on connection or authorisation errors).
            // A quick reachability probe first, so a dead account fails within the probe's
            // timeouts instead of the Blob client's long retries.
            'storage' => $this->check('storage', function () use ($storage) {
                if (! $storage->available()) {
                    return false;
                }

                Storage::disk(config('portal.documents_disk'))->exists('.health');

                return true;
            }),
        ];

        $healthy = ! in_array('fail', $checks, true);

        return $this->respond(
            ['status' => $healthy ? 'ok' : 'fail', 'checks' => $checks],
            $healthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    /**
     * Run a probe; any exception (or an explicit false) counts as a failure.
     */
    private function check(string $name, Closure $probe): string
    {
        try {
            return $probe() === false ? $this->fail($name, 'returned false') : 'ok';
        } catch (Throwable $e) {
            return $this->fail($name, $e::class.': '.$e->getMessage());
        }
    }

    private function fail(string $name, string $reason): string
    {
        Log::error("Health check failed: {$name}", ['reason' => $reason]);

        return 'fail';
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function respond(array $body, int $status): JsonResponse
    {
        return response()->json($body, $status, ['Cache-Control' => 'no-store']);
    }
}
