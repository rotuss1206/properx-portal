<?php

namespace App\Console\Commands\Concerns;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\ScheduledTaskFailed;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * For scheduled commands under the platform's 120-second limit: go through a query until
 * portal.scheduled_task_budget_seconds is used up (the next run continues; every step must be
 * idempotent), collect errors by record id and class, and alert the Executive Director.
 */
trait RunsWithinBudget
{
    /** @var list<string> */
    private array $errors = [];

    private bool $outOfTime = false;

    private float $deadline = 0.0;

    private function startBudget(): void
    {
        $this->errors = [];
        $this->outOfTime = false;
        $this->deadline = microtime(true) + (float) config('portal.scheduled_task_budget_seconds');
    }

    /**
     * @param  callable(mixed): void  $step
     */
    private function within(Builder $query, string $label, callable $step): void
    {
        if ($this->outOfTime) {
            return;
        }

        $query->chunkById(100, function ($records) use ($label, $step) {
            foreach ($records as $record) {
                if (microtime(true) >= $this->deadline) {
                    $this->outOfTime = true;

                    return false;
                }

                try {
                    $step($record);
                } catch (Throwable $e) {
                    report($e);
                    $this->errors[] = "{$label} #{$record->getKey()}: ".$e::class;
                }
            }
        });
    }

    private function finishBudget(): int
    {
        if ($this->outOfTime) {
            $this->warn('Time budget reached; the next run continues.');
        }

        if ($this->errors === []) {
            return self::SUCCESS;
        }

        $directors = User::role(Role::ExecutiveDirector->value)->get();

        if ($directors->isNotEmpty()) {
            rescue(fn () => Notification::send($directors, new ScheduledTaskFailed($this->getName(), $this->errors)));
        }

        return self::FAILURE;
    }
}
