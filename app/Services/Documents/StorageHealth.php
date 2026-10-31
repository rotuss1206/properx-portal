<?php

namespace App\Services\Documents;

use App\Exceptions\StorageUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Is document storage (Azure Blob; Azurite locally) reachable? The Blob client retries
 * unreachable hosts many times with growing pauses (its retry policy is fixed inside the
 * azure-oss package), so a download against a dead account would hang until the gateway
 * gives up. A short HEAD request to the Blob endpoint answers first: any HTTP response means
 * reachable (authorisation is the real client's business); no connection means unavailable.
 * The outcome is cached for portal.storage.probe_cache_seconds.
 */
class StorageHealth
{
    private const CACHE_KEY = 'documents-storage:reachable';

    public function available(): bool
    {
        $endpoint = self::endpoint();

        if ($endpoint === null) {
            return true; // not an Azure disk (tests fake it)
        }

        $cached = Cache::get(self::CACHE_KEY);

        if (is_bool($cached)) {
            return $cached;
        }

        $reachable = $this->probe($endpoint);
        Cache::put(self::CACHE_KEY, $reachable, (int) config('portal.storage.probe_cache_seconds'));

        return $reachable;
    }

    /**
     * @throws StorageUnavailableException
     */
    public function ensureAvailable(string $kind, int|string|null $id = null): void
    {
        if (! $this->available()) {
            throw new StorageUnavailableException($kind, $id);
        }
    }

    private function probe(string $endpoint): bool
    {
        try {
            Http::connectTimeout((int) config('portal.storage.connect_timeout'))
                ->timeout((int) config('portal.storage.probe_timeout'))
                ->retry((int) config('portal.storage.probe_retries') + 1, 200, throw: false)
                ->head($endpoint);

            return true;
        } catch (ConnectionException) {
            return false;
        }
    }

    /**
     * The Blob endpoint of the documents disk: BlobEndpoint of a connection string (Azurite),
     * the configured endpoint, or the account's default endpoint.
     */
    public static function endpoint(): ?string
    {
        $disk = config('filesystems.disks.'.config('portal.documents_disk'));

        if (($disk['driver'] ?? null) !== 'azure-storage-blob') {
            return null;
        }

        if (filled($disk['connection_string'] ?? null) && preg_match('/BlobEndpoint=([^;]+)/i', $disk['connection_string'], $match)) {
            return $match[1];
        }

        if (filled($disk['endpoint'] ?? null)) {
            return $disk['endpoint'];
        }

        return filled($disk['account_name'] ?? null) ? "https://{$disk['account_name']}.blob.core.windows.net" : null;
    }
}
