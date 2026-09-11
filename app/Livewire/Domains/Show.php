<?php

namespace App\Livewire\Domains;

use App\Helpers\ExtensionHelper;
use App\Livewire\Component;
use App\Models\Domain;
use App\Services\Domain\DomainRenewalInvoiceService;
use Exception;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/**
 * Client side domain management. Every registrar call goes through ExtensionHelper::callDomain,
 * optional registrar features are detected with ExtensionHelper::registrarSupports.
 */
class Show extends Component
{
    #[Locked]
    public Domain $domain;

    #[Url('tab', except: 'overview')]
    public string $tab = 'overview';

    public array $supports = [];

    public array $nameservers = [];

    public ?bool $locked = null;

    public ?string $authCode = null;

    public array $contacts = [];

    public int $renewYears = 1;

    public const CONTACT_TYPES = ['registrant', 'admin', 'tech', 'billing'];

    public const CONTACT_FIELDS = ['first_name', 'last_name', 'organization', 'email', 'phone', 'address1', 'address2', 'city', 'state', 'postal_code', 'country'];

    public function mount()
    {
        foreach (['transferDomain', 'getRegistrarLock', 'setRegistrarLock', 'getAuthCode', 'getContacts', 'setContacts', 'setPrivacy'] as $function) {
            $this->supports[$function] = ExtensionHelper::registrarSupports($this->domain->registrar, $function);
        }
        $this->renewYears = $this->domain->years;
        $this->nameservers = array_pad(array_slice($this->domain->nameservers ?? [], 0, 5), 5, '');

        if ($this->domain->isManageable()) {
            $this->loadTab();
        }
    }

    public function changeTab(string $tab)
    {
        $this->tab = $tab;
        if ($this->domain->isManageable()) {
            $this->loadTab();
        }
    }

    private function loadTab(): void
    {
        try {
            if ($this->tab === 'nameservers') {
                $this->nameservers = array_pad(array_slice(ExtensionHelper::callDomain($this->domain, 'getNameservers'), 0, 5), 5, '');
            } elseif ($this->tab === 'transfer' && $this->supports['getRegistrarLock']) {
                $this->locked = (bool) ExtensionHelper::callDomain($this->domain, 'getRegistrarLock');
            } elseif ($this->tab === 'contacts' && $this->supports['getContacts'] && empty($this->contacts)) {
                $this->contacts = ExtensionHelper::callDomain($this->domain, 'getContacts') ?? [];
            }
        } catch (Exception $e) {
            $this->notify(__('domains.registrar_error', ['message' => $e->getMessage()]), 'error');
        }
    }

    public function sync()
    {
        try {
            $info = ExtensionHelper::callDomain($this->domain, 'getDomainInfo') ?? [];
        } catch (Exception $e) {
            return $this->notify(__('domains.registrar_error', ['message' => $e->getMessage()]), 'error');
        }

        $this->domain->fill(array_filter([
            'status' => in_array($info['status'] ?? null, Domain::STATUSES) ? $info['status'] : null,
            'expires_at' => isset($info['expires_at']) ? Carbon::parse($info['expires_at']) : null,
            'nameservers' => $info['nameservers'] ?? null,
        ]));
        if (array_key_exists('privacy', $info)) {
            $this->domain->privacy = (bool) $info['privacy'];
        }
        $this->domain->save();
        $this->nameservers = array_pad(array_slice($this->domain->nameservers ?? [], 0, 5), 5, '');

        $this->notify(__('domains.synced'));
    }

    public function toggleAutoRenew()
    {
        $this->domain->update(['auto_renew' => !$this->domain->auto_renew]);
        $this->notify(__('domains.auto_renew_updated'));
    }

    public function renew()
    {
        $this->validate(['renewYears' => ['integer', 'min:1', 'max:10']]);
        $service = new DomainRenewalInvoiceService;

        if (!$this->domain->isManageable() || $service->hasPendingInvoice($this->domain)) {
            return $this->notify(__('domains.renewal_unavailable'), 'error');
        }

        $invoice = $service->create($this->domain, $this->renewYears);
        if (!$invoice) {
            return $this->notify(__('domains.renewal_unavailable'), 'error');
        }

        return $this->redirect(route('invoices.show', [$invoice, 'pay' => true]), true);
    }

    public function updateNameservers()
    {
        $this->validate([
            'nameservers' => ['array', 'max:5'],
            'nameservers.*' => ['nullable', 'string', 'regex:/^([a-z0-9-]+\.)+[a-z0-9-]+$/i'],
        ]);
        $nameservers = array_values(array_filter(array_map('trim', $this->nameservers)));
        if (count($nameservers) < 1) {
            return $this->addError('nameservers.0', __('validation.required', ['attribute' => __('domains.nameservers')]));
        }

        try {
            ExtensionHelper::callDomain($this->domain, 'setNameservers', [$nameservers]);
        } catch (Exception $e) {
            return $this->notify(__('domains.registrar_error', ['message' => $e->getMessage()]), 'error');
        }

        $this->domain->update(['nameservers' => $nameservers]);
        $this->notify(__('domains.nameservers_updated'));
    }

    public function toggleLock()
    {
        if (!$this->supports['setRegistrarLock']) {
            return;
        }
        try {
            ExtensionHelper::callDomain($this->domain, 'setRegistrarLock', [!$this->locked]);
            $this->locked = !$this->locked;
        } catch (Exception $e) {
            return $this->notify(__('domains.registrar_error', ['message' => $e->getMessage()]), 'error');
        }
        $this->notify(__('domains.lock_updated'));
    }

    public function showAuthCode()
    {
        if (!$this->supports['getAuthCode']) {
            return;
        }
        try {
            $this->authCode = (string) ExtensionHelper::callDomain($this->domain, 'getAuthCode');
        } catch (Exception $e) {
            $this->notify(__('domains.registrar_error', ['message' => $e->getMessage()]), 'error');
        }
    }

    public function togglePrivacy()
    {
        if (!$this->supports['setPrivacy']) {
            return;
        }
        try {
            ExtensionHelper::callDomain($this->domain, 'setPrivacy', [!$this->domain->privacy]);
        } catch (Exception $e) {
            return $this->notify(__('domains.registrar_error', ['message' => $e->getMessage()]), 'error');
        }
        $this->domain->update(['privacy' => !$this->domain->privacy]);
        $this->notify(__('domains.privacy_updated'));
    }

    public function updateContacts()
    {
        if (!$this->supports['setContacts']) {
            return;
        }
        $rules = [];
        foreach (self::CONTACT_TYPES as $type) {
            foreach (self::CONTACT_FIELDS as $field) {
                $rules["contacts.$type.$field"] = in_array($field, ['first_name', 'last_name', 'email', 'country']) && isset($this->contacts[$type]) ? ['required', 'string', 'max:255'] : ['nullable', 'string', 'max:255'];
            }
        }
        $this->validate($rules);

        try {
            ExtensionHelper::callDomain($this->domain, 'setContacts', [$this->contacts]);
        } catch (Exception $e) {
            return $this->notify(__('domains.registrar_error', ['message' => $e->getMessage()]), 'error');
        }
        $this->notify(__('domains.contacts_updated'));
    }

    public function render()
    {
        return view('domains.show')->layoutData([
            'title' => $this->domain->domain,
            'sidebar' => true,
        ]);
    }
}
