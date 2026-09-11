<?php

namespace App\Services\Domain;

use App\Models\Tld;

/**
 * Price of a domain action: the registrar's live price plus the admin markup when the registrar
 * returned one for this currency, otherwise the TLD price grid.
 */
class DomainPricingService
{
    /**
     * @param  array|null  $live  Per-year prices from the availability check: ['register' => 9.99, 'renew' => ..., 'transfer' => ..., 'currency' => 'USD']
     * @return float|null null when no price is configured for this term/currency
     */
    public function quote(Tld $tld, string $action, int $years, string $currency, ?array $live = null): ?float
    {
        $years = max(1, $years);

        if ($live && isset($live[$action]) && strtoupper($live['currency'] ?? '') === strtoupper($currency)) {
            return round((float) $live[$action] * $years * (1 + $this->markup() / 100), 2);
        }

        return $tld->price($currency, $years, $action);
    }

    public function markup(): float
    {
        return (float) config('settings.domain_markup', 0);
    }
}
