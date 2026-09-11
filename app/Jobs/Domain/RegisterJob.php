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

class RegisterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public $tries = 1;

    public function __construct(public Domain $domain, public bool $sendNotification = true) {}

    public function handle(): void
    {
        $result = ExtensionHelper::callDomain($this->domain, 'registerDomain') ?? [];

        $this->domain->status = Domain::STATUS_ACTIVE;
        $this->domain->registered_at = now();
        $this->domain->expires_at = isset($result['expires_at']) ? Carbon::parse($result['expires_at']) : now()->addYears($this->domain->years);
        if (!empty($result['nameservers'])) {
            $this->domain->nameservers = $result['nameservers'];
        }
        $this->domain->save();

        foreach ($result['properties'] ?? [] as $key => $value) {
            $this->domain->properties()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        if ($this->sendNotification) {
            NotificationHelper::domainRegisteredNotification($this->domain->user, $this->domain);
        }
    }
}
