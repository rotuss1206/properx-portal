<?php

namespace App\Nova\Menu;

use Laravel\Nova\Menu\MenuItem;

/**
 * A Nova menu item that also names its sidebar icon (resources/js/nova/sidebar.js).
 * Nova's own menu ignores the extra key, so it still works as a fallback.
 */
class PortalMenuItem extends MenuItem
{
    public ?string $portalIcon = null;

    /**
     * @return $this
     */
    public function icon(string $icon): static
    {
        $this->portalIcon = $icon;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return parent::jsonSerialize() + ['portalIcon' => $this->portalIcon];
    }
}
