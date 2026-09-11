<?php

namespace App\Jobs\Domain;

use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Models\Domain;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class RenewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public $tries = 1;

    public function __construct(public Domain $domain, public int $years, public bool $sendNotification = true) {}

    public function handle(): void
    {
        $result = ExtensionHelper::callDomain($this->domain, 'renewDomain', [$this->years]) ?? [];

        $base = $this->domain->expires_at && $this->domain->expires_at->isFuture() ? $this->domain->expires_at : now();
        $this->domain->expires_at = isset($result['expires_at']) ? Carbon::parse($result['expires_at']) : $base->copy()->addYears($this->years);
        $this->domain->status = Domain::STATUS_ACTIVE;
        $this->domain->save();

        if ($this->sendNotification) {
            NotificationHelper::domainRenewedNotification($this->domain->user, $this->domain);
        }
    }
}
