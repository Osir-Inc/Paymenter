<?php

namespace App\Services\Domain;

use App\Models\Domain;
use App\Models\Invoice;

/**
 * Creates the renewal invoice of a domain, used by the cron job and by "Renew now".
 */
class DomainRenewalInvoiceService
{
    public function __construct(private DomainPricingService $pricing = new DomainPricingService) {}

    /**
     * @return Invoice|null null when the term has no price in the domain's currency
     */
    public function create(Domain $domain, ?int $years = null): ?Invoice
    {
        $years = $years ?: $domain->years;
        $price = $this->pricing->quote($domain->tld, 'renew', $years, $domain->currency_code);
        if ($price === null) {
            return null;
        }

        $domain->years = $years;
        $domain->price = $price;
        $domain->save();

        $invoice = Invoice::create([
            'user_id' => $domain->user_id,
            'status' => 'pending',
            'due_at' => $domain->expires_at && $domain->expires_at->isFuture() ? $domain->expires_at : now()->addDays(7),
            'currency_code' => $domain->currency_code,
        ]);

        $invoice->items()->create([
            'reference_id' => $domain->id,
            'reference_type' => Domain::class,
            'price' => $price,
            'quantity' => 1,
            'description' => $domain->description('renew'),
        ]);

        return $invoice->refresh();
    }

    public function hasPendingInvoice(Domain $domain): bool
    {
        return $domain->invoices()->where('status', 'pending')->exists();
    }
}
