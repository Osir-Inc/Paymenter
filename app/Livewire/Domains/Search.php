<?php

namespace App\Livewire\Domains;

use App\Classes\Cart;
use App\Classes\Price;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Livewire\Component;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\Tld;
use App\Services\Domain\DomainPricingService;
use Exception;
use Livewire\Attributes\Url;

class Search extends Component
{
    #[Url(as: 'q')]
    public string $query = '';

    #[Url]
    public int $years = 1;

    /** @var array<int, array> keyed by TLD id */
    public array $results = [];

    public bool $searched = false;

    public string $transferDomain = '';

    public string $authCode = '';

    public const NAME_REGEX = '/^(?!-)[a-z0-9-]{1,63}(?<!-)$/i';

    public function mount()
    {
        if ($this->query !== '') {
            $this->search();
        }
    }

    public function search()
    {
        $this->validate(['query' => ['required', 'string', 'max:255'], 'years' => ['integer', 'min:1', 'max:10']]);
        $this->results = [];
        $this->searched = true;

        [$name, $typedTld] = Tld::split($this->query);
        if (!preg_match(self::NAME_REGEX, $name)) {
            $this->addError('query', __('domains.invalid_domain'));

            return;
        }

        $tlds = Tld::where('enabled', true)->whereNotNull('registrar_id')->where('featured', true)->orderBy('sort')
            ->limit((int) config('settings.domain_search_results', 8))->get();
        if ($typedTld && $typedTld->enabled && $typedTld->registrar_id) {
            $tlds = $tlds->reject(fn ($tld) => $tld->id === $typedTld->id)->prepend($typedTld);
        }

        foreach ($tlds as $tld) {
            $this->results[$tld->id] = $this->check($tld, strtolower($name));
        }
    }

    private function check(Tld $tld, string $name): array
    {
        $domain = $name . '.' . $tld->tld;
        $result = ['tld_id' => $tld->id, 'tld' => $tld->tld, 'domain' => $domain, 'available' => false, 'premium' => false, 'price' => null, 'formatted' => null, 'error' => false, 'in_cart' => false];

        try {
            $availability = ExtensionHelper::checkDomainAvailability($tld, $domain);
        } catch (Exception $e) {
            report($e);
            $result['error'] = true;

            return $result;
        }

        $result['available'] = $availability['available'];
        $result['premium'] = $availability['premium'];
        if ($result['available'] && in_array($this->years, $tld->terms())) {
            $result['price'] = (new DomainPricingService)->quote($tld, 'register', $this->years, $this->currency(), $availability['prices']);
            $result['formatted'] = $result['price'] === null ? null : (string) new Price(['price' => $result['price'], 'currency' => Currency::find($this->currency())]);
        }
        $result['in_cart'] = Cart::get()->items->where('tld_id', $tld->id)->where('domain', $name)->isNotEmpty();

        return $result;
    }

    public function addToCart(int $tldId)
    {
        $result = $this->results[$tldId] ?? null;
        if (!$result || !$result['available'] || $result['price'] === null) {
            return;
        }

        [$name] = Tld::split($result['domain']);
        try {
            Cart::addDomain(Tld::findOrFail($tldId), $name, $this->years, Domain::ACTION_REGISTER, $result['price']);
        } catch (DisplayException $e) {
            return $this->notify($e->getMessage(), 'error');
        }

        $this->results[$tldId]['in_cart'] = true;
        $this->dispatch('cartUpdated');
        $this->notify(__('domains.in_cart'));
    }

    public function transfer()
    {
        $this->validate(['transferDomain' => ['required', 'string', 'max:255'], 'authCode' => ['required', 'string', 'max:255']]);

        [$name, $tld] = Tld::split($this->transferDomain);
        if (!$tld || !$tld->enabled || !$tld->registrar_id) {
            return $this->addError('transferDomain', __('domains.unknown_tld'));
        }
        if (!$tld->supports_transfer || !ExtensionHelper::registrarSupports($tld->registrar, 'transferDomain')) {
            return $this->addError('transferDomain', __('domains.transfer_not_supported'));
        }
        if (!preg_match(self::NAME_REGEX, $name)) {
            return $this->addError('transferDomain', __('domains.invalid_domain'));
        }

        $years = max(1, min($this->years, $tld->max_years));
        $price = (new DomainPricingService)->quote($tld, 'transfer', $years, $this->currency());
        if ($price === null) {
            return $this->addError('transferDomain', __('domains.no_price'));
        }

        try {
            Cart::addDomain($tld, strtolower($name), $years, Domain::ACTION_TRANSFER, $price, $this->authCode);
        } catch (DisplayException $e) {
            return $this->notify($e->getMessage(), 'error');
        }

        return $this->redirect(route('cart'), true);
    }

    private function currency(): string
    {
        return session('currency', config('settings.default_currency'));
    }

    public function render()
    {
        return view('domains.search')->layoutData([
            'title' => __('domains.search_title'),
        ]);
    }
}
