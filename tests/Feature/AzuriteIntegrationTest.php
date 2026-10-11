<?php

namespace Tests\Feature;

use App\Services\Documents\DocumentVault;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Round trip against a running Azurite emulator (`npm run azurite`). Skipped when it is not running.
 */
#[Group('azurite')]
class AzuriteIntegrationTest extends TestCase
{
    private const AZURITE = 'DefaultEndpointsProtocol=http;AccountName=devstoreaccount1;AccountKey=Eby8vdM02xNOcqFlqUwJPLlmEtlCDXJ1OUzFT50uSRZ6IFsuFq2UVErCz4I6tq/K1SZFPTOtr/KBHBeksoGMGw==;BlobEndpoint=http://127.0.0.1:10000/devstoreaccount1;';

    protected function setUp(): void
    {
        parent::setUp();

        $socket = @fsockopen('127.0.0.1', 10000, $errno, $error, 0.5);

        if ($socket === false) {
            $this->markTestSkipped('Azurite is not running on 127.0.0.1:10000.');
        }

        fclose($socket);

        config([
            'filesystems.disks.azure.connection_string' => self::AZURITE,
            'filesystems.disks.azure.container' => 'portal-tests',
            'portal.documents_disk' => 'azure',
        ]);

        $this->artisan('app:storage:init')->assertSuccessful();
    }

    public function test_encrypted_round_trip_private_container_and_sas_link(): void
    {
        $vault = app(DocumentVault::class);
        $disk = Storage::disk('azure');

        $secret = $vault->put('license-documents', 'pdf', '%PDF-1.4 secret', encrypt: true);
        $public = $vault->put('library', 'pdf', '%PDF-1.4 library', encrypt: false);

        try {
            $this->assertStringNotContainsString('secret', $disk->get($secret));
            $this->assertSame('%PDF-1.4 secret', $vault->read($secret));

            $blobUrl = 'http://127.0.0.1:10000/devstoreaccount1/portal-tests/'.$public;
            $this->assertSame(403, Http::get($blobUrl)->status(), 'Container must not allow anonymous reads.');

            $sas = $disk->temporaryUrl($public, now()->addMinutes(5));
            $this->assertSame('%PDF-1.4 library', Http::get($sas)->body());
        } finally {
            $disk->delete([$secret, $public]);
        }
    }
}
