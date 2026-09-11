<?php

namespace Paymenter\Extensions\Registrars\Osir;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Registrar;
use App\Models\Domain;
use Exception;
use Illuminate\Support\Facades\Http;

#[ExtensionMeta(
    name: 'OSIR',
    description: 'Domain registration, transfers and renewals through the OSIR registrar API',
    version: 'builtin',
    author: 'OSIR',
    url: 'https://osir.com/en/developers/',
)]
class Osir extends Registrar
{
    // OSIR contact field => Paymenter contact field
    private const CONTACT_FIELDS = [
        'firstName' => 'first_name',
        'lastName' => 'last_name',
        'organization' => 'organization',
        'email' => 'email',
        'phone' => 'phone',
        'street1' => 'address1',
        'street2' => 'address2',
        'city' => 'city',
        'state' => 'state',
        'postalCode' => 'postal_code',
        'country' => 'country',
    ];

    public function getConfig($values = []): array
    {
        return [
            [
                'name' => 'api_url',
                'label' => 'API URL',
                'type' => 'text',
                'default' => 'https://be.osir.com',
                'required' => true,
            ],
            [
                'name' => 'api_key',
                'label' => 'API key',
                'type' => 'password',
                'description' => 'Create one in the OSIR panel under API keys (osir_live_... or osir_test_...)',
                'required' => true,
                'encrypted' => true,
            ],
            [
                'name' => 'tenant_id',
                'label' => 'Tenant ID',
                'type' => 'text',
                'default' => 'osir',
                'required' => true,
            ],
            [
                'name' => 'environment',
                'label' => 'Environment',
                'type' => 'select',
                'options' => ['production' => 'Production', 'ote1' => 'OTE 1 (testing)', 'ote2' => 'OTE 2 (testing)'],
                'default' => 'production',
                'required' => true,
            ],
            [
                'name' => 'nameservers',
                'label' => 'Default nameservers',
                'type' => 'text',
                'description' => 'Comma separated, used for new registrations',
                'default' => 'ns1.osir.com,ns3.osir.com',
                'required' => true,
            ],
            [
                'name' => 'initialize_dns_zone',
                'label' => 'Create DNS zone',
                'type' => 'checkbox',
                'description' => 'Create a DNS zone at OSIR for new registrations',
            ],
        ];
    }

    public function testConfig(): bool|string
    {
        try {
            $this->request('get', '/api/v1/tenants/' . ($this->config('tenant_id') ?: 'osir') . '/health');
        } catch (Exception $e) {
            return $e->getMessage();
        }

        return true;
    }

    public function checkAvailability(string $domain): array|bool
    {
        // Public endpoint: no API key needed, and it quotes the name (premium and promo prices included)
        $response = $this->request('get', '/v1/public/catalog/domains/' . $domain . '/availability');

        $result = [
            'available' => (bool) ($response['available'] ?? false),
            'premium' => (bool) ($response['premium'] ?? false),
        ];

        // Prices are in cents. totalPrice includes ICANN and registrar fees, promoPrice replaces the first year price.
        $register = $response['promoApplied'] ?? false ? ($response['promoPrice'] ?? null) : ($response['totalPrice'] ?? $response['price'] ?? null);
        if ($register !== null) {
            $result['prices'] = array_filter([
                'register' => $register / 100,
                'renew' => isset($response['renewPrice']) ? $response['renewPrice'] / 100 : null,
                'transfer' => isset($response['transferPrice']) ? $response['transferPrice'] / 100 : null,
                'currency' => $response['currency'] ?? 'USD',
            ], fn ($value) => $value !== null);
        }

        return $result;
    }

    public function getTldPricing(): array
    {
        $response = $this->request('get', '/v1/public/catalog/domains');
        $pricing = [];

        foreach ($response['extensions'] ?? [] as $extension) {
            $tld = ltrim(strtolower($extension['extension'] ?? ''), '.');
            if (!$tld) {
                continue;
            }
            // OSIR returns prices in cents
            $pricing[$tld] = [
                'register' => ($extension['registrationPrice'] ?? 0) / 100,
                'renew' => ($extension['renewalPrice'] ?? 0) / 100,
                'transfer' => ($extension['transferPrice'] ?? 0) / 100,
                'currency' => $extension['currency'] ?? 'USD',
                'min_years' => $extension['minRegistrationPeriod'] ?? 1,
                'max_years' => $extension['maxRegistrationPeriod'] ?? 10,
            ];
        }

        return $pricing;
    }

    public function registerDomain(Domain $domain): array
    {
        $response = $this->request('post', '/v2/domains/register', [
            'domain' => $domain->domain,
            'period' => $domain->years,
            'nameservers' => $domain->nameservers ?: $this->defaultNameservers(),
            'registrant' => $this->toOsirContact($this->contactFromUser($domain->user)),
            'privacyProtection' => $domain->privacy,
            'initializeDnsZone' => (bool) $this->config('initialize_dns_zone'),
            'autoRenew' => false,
        ], ['Idempotency-Key' => 'paymenter-register-' . $domain->id]);

        return array_filter([
            'expires_at' => $response['expiryDate'] ?? null,
            'nameservers' => $domain->nameservers ?: $this->defaultNameservers(),
        ]);
    }

    public function transferDomain(Domain $domain): array
    {
        $this->request('post', '/v2/transfer/initiate', [
            'domain' => $domain->domain,
            'authCode' => $domain->auth_code,
            'period' => $domain->years,
            'registrant' => $this->toOsirContact($this->contactFromUser($domain->user)),
            'privacyProtection' => $domain->privacy,
        ], ['Idempotency-Key' => 'paymenter-transfer-' . $domain->id]);

        return [];
    }

    public function getTransferStatus(Domain $domain): string
    {
        $response = $this->request('get', '/v2/transfer/' . $domain->domain . '/status');

        return match (strtolower($response['status'] ?? 'pending')) {
            'approved', 'completed', 'complete' => 'completed',
            'rejected', 'failed' => 'failed',
            'cancelled', 'canceled' => 'cancelled',
            default => 'pending',
        };
    }

    public function renewDomain(Domain $domain, int $years): array
    {
        $response = $this->request('post', '/v2/domains/' . $domain->domain . '/renew', [
            'period' => $years,
        ], ['Idempotency-Key' => 'paymenter-renew-' . $domain->id . '-' . now()->format('Ymd')]);

        return array_filter(['expires_at' => $response['expiryDate'] ?? null]);
    }

    public function getDomainInfo(Domain $domain): array
    {
        $info = $this->request('get', '/v2/domains/' . $domain->domain . '/info');
        $statuses = $info['statuses'] ?? [];

        $status = Domain::STATUS_ACTIVE;
        if (in_array('redemptionPeriod', $statuses) || in_array('pendingDelete', $statuses) || !empty($info['expired'])) {
            $status = Domain::STATUS_EXPIRED;
        } else {
            $status = match (strtolower($info['status'] ?? 'active')) {
                'expired', 'redemption' => Domain::STATUS_EXPIRED,
                'pendingtransfer' => Domain::STATUS_PENDING_TRANSFER,
                'transferred', 'transferredaway' => Domain::STATUS_TRANSFERRED_AWAY,
                'cancelled', 'deleted' => Domain::STATUS_CANCELLED,
                default => Domain::STATUS_ACTIVE,
            };
        }

        return [
            'status' => $status,
            'expires_at' => $info['expiryDate'] ?? null,
            'nameservers' => array_values(array_filter($info['nameservers'] ?? [], 'is_string')),
            'locked' => (bool) ($info['locked'] ?? false),
            'privacy' => (bool) ($info['privacy'] ?? false),
        ];
    }

    public function getNameservers(Domain $domain): array
    {
        return $this->getDomainInfo($domain)['nameservers'];
    }

    public function setNameservers(Domain $domain, array $nameservers): void
    {
        $this->request('put', '/v2/domains/' . $domain->domain . '/nameservers', [
            'nameservers' => array_values($nameservers),
            'replaceAll' => true,
        ]);
    }

    public function getRegistrarLock(Domain $domain): bool
    {
        return $this->getDomainInfo($domain)['locked'];
    }

    public function setRegistrarLock(Domain $domain, bool $locked): void
    {
        $this->request('post', '/v2/domains/' . $domain->domain . '/' . ($locked ? 'lock' : 'unlock'));
    }

    public function getAuthCode(Domain $domain): string
    {
        $response = $this->request('get', '/v2/domains/' . $domain->domain . '/authcode');

        if (empty($response['authCode'])) {
            throw new Exception('Authorization code not available');
        }

        return $response['authCode'];
    }

    public function setPrivacy(Domain $domain, bool $privacy): void
    {
        $this->request('post', '/v2/domains/' . $domain->domain . '/privacy/' . ($privacy ? 'enable' : 'disable'));
    }

    public function getContacts(Domain $domain): array
    {
        $response = $this->request('get', '/v2/domains/' . $domain->domain . '/contacts');
        $contacts = [];

        foreach (['registrant', 'admin', 'tech', 'billing'] as $type) {
            if (isset($response[$type]) && is_array($response[$type])) {
                $contacts[$type] = $this->fromOsirContact($response[$type]);
            }
        }

        return $contacts;
    }

    public function setContacts(Domain $domain, array $contacts): void
    {
        $payload = [];
        foreach ($contacts as $type => $contact) {
            $payload[$type] = $this->toOsirContact($contact);
        }

        $this->request('put', '/v2/domains/' . $domain->domain . '/contacts', $payload);
    }

    private function defaultNameservers(): array
    {
        $nameservers = array_values(array_filter(array_map('trim', explode(',', (string) $this->config('nameservers')))));

        return $nameservers ?: ['ns1.osir.com', 'ns3.osir.com'];
    }

    private function toOsirContact(array $contact): array
    {
        $osir = [];
        foreach (self::CONTACT_FIELDS as $osirField => $field) {
            $osir[$osirField] = $contact[$field] ?? '';
        }

        return $osir;
    }

    private function fromOsirContact(array $contact): array
    {
        $result = [];
        foreach (self::CONTACT_FIELDS as $osirField => $field) {
            $result[$field] = $contact[$osirField] ?? '';
        }

        return $result;
    }

    /**
     * Call the OSIR API and unwrap its {success, data, error} envelope
     *
     * @throws Exception on transport, HTTP or API errors
     */
    private function request(string $method, string $path, array $data = [], array $headers = []): array
    {
        $response = Http::baseUrl(rtrim($this->config('api_url') ?: 'https://be.osir.com', '/'))
            ->withHeaders(array_merge([
                'X-API-Key' => $this->config('api_key'),
                'X-Tenant-ID' => $this->config('tenant_id') ?: 'osir',
                'X-Environment' => $this->config('environment') ?: 'production',
            ], $headers))
            ->acceptJson()
            ->timeout(30)
            ->$method($path, $data);

        $json = $response->json() ?? [];

        if ($response->failed() || (isset($json['success']) && !$json['success'])) {
            throw new Exception($json['error'] ?? $json['message'] ?? 'OSIR API error (HTTP ' . $response->status() . ')');
        }

        return isset($json['data']) && is_array($json['data']) ? $json['data'] : $json;
    }
}
