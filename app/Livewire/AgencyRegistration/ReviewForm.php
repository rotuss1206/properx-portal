<?php

namespace App\Livewire\AgencyRegistration;

/**
 * Screen 6 · Review & payment: the four required consents of Agency spec 5.5, each a separate
 * box that starts unticked. When each was given is kept by the wizard (Wizard::$consentsGivenAt,
 * locked, so the browser cannot write it) and saved in the draft with CONSENT_VERSION.
 */
class ReviewForm extends StepForm
{
    /** Raise when the wording changes, so a stored consent says which text was agreed to. */
    public const CONSENT_VERSION = 1;

    /** The Terms of Service and Privacy Policy are links in the first one (steps/6.blade.php). */
    public const CONSENTS = [
        'terms' => 'I agree to the Terms of Service and Privacy Policy.',
        'dues_separate' => 'I understand that membership dues are separate from health plan premiums billed by the plan administrator, and that ProPerX does not sell insurance.',
        'annual_no_refund' => 'I understand that membership is billed annually, dues are non-refundable, and a member whose membership is canceled cannot reapply for 24 months.',
        'roster_confirmed' => 'I confirm that every person listed is either a licensed agent or an employee of this agency.',
    ];

    public bool $terms = false;

    public bool $dues_separate = false;

    public bool $annual_no_refund = false;

    public bool $roster_confirmed = false;

    public function rules(): array
    {
        return array_map(fn () => ['accepted'], self::CONSENTS);
    }

    public function messages(): array
    {
        return array_combine(
            array_map(fn (string $key) => "{$key}.accepted", array_keys(self::CONSENTS)),
            array_fill(0, count(self::CONSENTS), 'Please tick this box to enroll.'),
        );
    }

    public function allGiven(): bool
    {
        foreach (array_keys(self::CONSENTS) as $key) {
            if (! $this->{$key}) {
                return false;
            }
        }

        return true;
    }
}
