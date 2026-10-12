<?php

namespace App\Console\Commands;

use AzureOss\Storage\Blob\BlobServiceClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Creates the private Azure container used by the "azure" disk (needed once per
 * environment, e.g. after starting a fresh Azurite emulator).
 */
#[Signature('app:storage:init')]
#[Description('Create the private Azure Blob container for documents if it does not exist')]
class InitDocumentStorage extends Command
{
    public function handle(): int
    {
        $config = config('filesystems.disks.azure');

        if (blank($config['connection_string'] ?? null)) {
            $this->error('AZURE_STORAGE_CONNECTION_STRING is not set.');

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
