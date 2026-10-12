<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Services\ApiKeys\ApiKeyManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Quarterly rotation of the API Service key (Spec 3.7, 8). Prints the new key once;
 * store it in Key Vault (API_SERVICE_KEY). The previous key stays valid for the
 * overlap window so the rollout needs no downtime. Recorded in the audit log.
 */
#[Signature('app:api-key:rotate
    {--account= : Email of the API Service account (defaults to the only one)}
    {--overlap= : Hours the previous key stays valid (default from config)}')]
#[Description('Issue a new API Service key and expire the current one after an overlap window')]
class RotateApiKey extends Command
{
    public function handle(ApiKeyManager $keys): int
    {
        $accounts = User::role(Role::ApiService->value)
            ->when($this->option('account'), fn ($query, $email) => $query->where('email', $email))
            ->get();

        if ($accounts->count() !== 1) {
            $this->error($accounts->isEmpty()
                ? 'No API Service account found.'
                : 'Several API Service accounts exist; choose one with --account=<email>.');

            return self::FAILURE;
        }

        $account = $accounts->first();
        $overlap = (int) ($this->option('overlap') ?? config('services.api_service.rotation_overlap_hours'));

        [, $plain] = $keys->rotate($account, $overlap);

        $this->info("New API key for {$account->email} (shown only once):");
        $this->line($plain);
        $this->newLine();
        $this->line("Store it as API_SERVICE_KEY (Azure Key Vault in production). The previous key stops working in {$overlap} hour(s).");

        return self::SUCCESS;
    }
}
