<?php

namespace App\Rules;

use App\Models\Agency;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The submitted user may lead $agency (see Agency::principalProblem()). The model checks
 * the same on save, so a forged request that skips this rule still fails.
 */
final class EligiblePrincipal implements ValidationRule
{
    public function __construct(private Agency $agency) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $user = is_numeric($value) ? User::query()->with('memberProfile')->find($value) : null;

        if (($problem = $this->agency->principalProblem($user)) !== null) {
            $fail($problem);
        }
    }
}
