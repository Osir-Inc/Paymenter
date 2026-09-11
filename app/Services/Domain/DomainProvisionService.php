<?php

namespace App\Services\Domain;

use App\Jobs\Domain\RegisterJob;
use App\Jobs\Domain\RenewJob;
use App\Jobs\Domain\TransferJob;
use App\Models\Domain;

/**
 * What happens to a domain once an invoice for it is paid.
 */
class DomainProvisionService
{
    public function handle(Domain $domain): void
    {
        if ($domain->status === Domain::STATUS_PENDING) {
            $domain->action === Domain::ACTION_TRANSFER
                ? TransferJob::dispatch($domain)
                : RegisterJob::dispatch($domain);

            return;
        }

        if ($domain->status === Domain::STATUS_PENDING_TRANSFER) {
            // The transfer is still running at the registry, nothing to renew yet
            return;
        }

        RenewJob::dispatch($domain, $domain->years);
    }
}
