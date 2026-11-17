<?php

namespace App\Notifications\Staff;

use Laravel\Nova\Notifications\NovaNotification;

/**
 * A staff bell notification (App\Services\StaffAlerts) that carries its kind and record, so the
 * same record does not ring twice while the first one is unread.
 */
class StaffAlert extends NovaNotification
{
    public string $alertKey = '';

    public string $alertKind = '';

    public function key(string $kind, string $key): static
    {
        $this->alertKind = $kind;
        $this->alertKey = $key;

        return $this;
    }

    public function toArray()
    {
        return parent::toArray() + ['ppx_kind' => $this->alertKind, 'ppx_key' => $this->alertKey];
    }
}
