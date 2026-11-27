<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Maintenance\TestDataPurge;
use Database\Seeders\AgencySeeder;
use Database\Seeders\DemoLoginSeeder;
use Database\Seeders\MemberSeeder;
use Database\Seeders\StaffSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Removes test accounts and everything that belongs to them (TestDataPurge). Selects only what it is
 * given: --email, --domain (member accounts; staff only by exact email), --file (one email per
 * line) and --demo (exactly the accounts the demo seeders create). A dry run unless --force.
 * Never calls Stripe: test-mode customers and subscriptions are removed in the Stripe Dashboard.
 */
#[Signature('app:purge-test-data
    {--email=* : An account to remove (repeatable)}
    {--domain=* : Remove the member accounts of this email domain, e.g. example.com (repeatable; staff only by --email)}
    {--file= : A text file with one email per line}
    {--demo : The accounts the demo seeders create (listed in the output)}
    {--dry-run : Show what would be removed (the default)}
    {--force : Remove it}')]
#[Description('Remove listed test accounts and everything that belongs to them (dry run unless --force)')]
class PurgeTestData extends Command
{
    public function handle(TestDataPurge $purge): int
    {
        $emails = array_values(array_filter(array_map('trim', (array) $this->option('email'))));
        $domains = array_values(array_filter(array_map('trim', (array) $this->option('domain'))));

        foreach ($domains as $domain) {
            if (! preg_match('/^@?[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/i', $domain)) {
                $this->error("Not a domain: {$domain}. Give a full domain such as example.com, no wildcards.");

                return self::INVALID;
            }
        }

        if (($file = $this->option('file')) !== null) {
            if (! is_file($file) || ! is_readable($file)) {
                $this->error("Cannot read {$file}.");

                return self::INVALID;
            }

            $lines = array_filter(array_map('trim', (array) file($file, FILE_IGNORE_NEW_LINES)), fn (string $line) => $line !== '' && ! str_starts_with($line, '#'));
            $emails = [...$emails, ...$lines];
        }

        if ($this->option('demo')) {
            $demo = [...StaffSeeder::emails(), ...MemberSeeder::emails(), ...AgencySeeder::emails(), ...DemoLoginSeeder::emails(config('portal.demo_login_email'))];
            $this->line(sprintf('--demo lists %d accounts of the demo seeders:', count($demo)));
            $this->line('  '.implode(', ', $demo));
            $this->newLine();
            $emails = [...$emails, ...$demo];
        }

        foreach ($emails as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->error("Not an email address: {$email}");

                return self::INVALID;
            }
        }

        if ($emails === [] && $domains === []) {
            $this->error('Nothing selected. Give --email, --domain, --file or --demo.');

            return self::INVALID;
        }

        $plan = $purge->plan($emails, $domains);

        $this->table(['Account', 'Kind'], $plan->users->map(fn (User $user) => [
            $user->email,
            $user->isStaff() ? 'Staff: '.$user->roles->pluck('name')->implode(', ') : 'Member',
        ])->all());

        if ($plan->notFound !== []) {
            $this->warn('No account (nothing to remove): '.implode(', ', $plan->notFound));
        }

        if ($plan->skippedStaff->isNotEmpty()) {
            $this->warn('Kept, staff matched by a domain only (list them by --email to remove): '.$plan->skippedStaff->pluck('email')->implode(', '));
        }

        foreach ($plan->keptAgencies as $agency) {
            $this->warn("Agency #{$agency->id} is kept: other people stay on it. It loses its principal; assign a new one in Nova.");
        }

        $this->table(['What', 'Removed'], collect($plan->counts)->map(fn (int $count, string $label) => [$label, $count])->values()->all());
        $this->line('Kept: roles, permissions, reference data, the library, partner codes, the audit log and every account not listed above. Stripe is not called.');

        if ($plan->problem !== null) {
            $this->error($plan->problem);

            return self::FAILURE;
        }

        if ($plan->isEmpty()) {
            $this->info('Nothing to remove.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->info('Dry run: nothing was removed. Run again with --force to remove it.');

            return self::SUCCESS;
        }

        $failed = $purge->purge($plan, [
            'emails_listed' => count(array_unique(array_map('strtolower', $emails))),
            'domains' => $domains,
            'demo' => (bool) $this->option('demo'),
            'file' => $this->option('file') !== null,
        ]);

        $this->info(sprintf('Removed %d accounts.', $plan->counts['Accounts']));

        if ($failed > 0) {
            $this->warn("{$failed} stored files could not be deleted (see the log); the records are gone.");
        }

        return self::SUCCESS;
    }
}
