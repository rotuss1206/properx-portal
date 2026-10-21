<?php

namespace App\Console\Commands;

use AzureOss\Storage\Blob\BlobServiceClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Local development only: creates the private container in the Azurite emulator.
 * In Azure the platform provisions the container (managed identity, no account key).
 */
#[Signature('app:storage:init')]
#[Description('Create the private Azure Blob container for documents if it does not exist')]
class InitDocumentStorage extends Command
{
    public function handle(): int
    {
        $config = config('filesystems.disks.azure');

        if (blank($config['connection_string'] ?? null)) {
            $this->error('AZURITE_CONNECTION_STRING is not set. In Azure the platform creates the container.');

            return self::FAILURE;
        }

        // No public access options: the container stays private (Spec 3.2).
        BlobServiceClient::fromConnectionString($config['connection_string'])
            ->getContainerClient($config['container'])
            ->createIfNotExists();

        $this->info("Container [{$config['container']}] is ready.");

        return self::SUCCESS;
    }
}
