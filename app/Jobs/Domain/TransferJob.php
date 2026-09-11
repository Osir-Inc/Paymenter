<?php

namespace App\Jobs\Domain;

use App\Helpers\ExtensionHelper;
use App\Models\Domain;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Starts an inbound transfer; the cron job polls getTransferStatus until the registry completes it.
 */
class TransferJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public $tries = 1;

    public function __construct(public Domain $domain) {}

    public function handle(): void
    {
        $result = ExtensionHelper::callDomain($this->domain, 'transferDomain') ?? [];

        $this->domain->status = Domain::STATUS_PENDING_TRANSFER;
        $this->domain->save();

        foreach ($result['properties'] ?? [] as $key => $value) {
            $this->domain->properties()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
