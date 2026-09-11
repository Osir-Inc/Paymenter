<?php

namespace App\Classes\Extension;

use App\Models\Domain;
use App\Models\User;

/**
 * Base class for domain registrar extensions.
 *
 * Paymenter owns the domain lifecycle (search, cart, invoices, renewals, expiry) and the client and
 * admin UI. A registrar extension only translates Paymenter's calls into registrar API calls.
 *
 * Contact arrays use these keys: first_name, last_name, organization, email, phone,
 * address1, address2, city, state, postal_code, country.
 *
 * @link https://docs.paymenter.org/development/extensions/registrar
 */
abstract class Registrar extends Extension
{
    /**
     * Availability of a full domain name, e.g. "example.com".
     *
     * Return an array so registrars can pass on live pricing (premium names, registry promotions):
     *   ['available' => bool, 'premium' => bool, 'prices' => ['register' => 89.00, 'renew' => 89.00, 'transfer' => 89.00, 'currency' => 'USD']]
     * `prices` are per year in the registrar's currency; Paymenter applies its markup and multiplies by the term.
     * Omit `prices` (or return a plain bool) to let Paymenter use the TLD price grid.
     */
    abstract public function checkAvailability(string $domain): array|bool;

    /**
     * Base prices per year for the "Import TLDs" admin action.
     *
     * @return array ['com' => ['register' => 9.99, 'renew' => 9.99, 'transfer' => 9.99, 'currency' => 'USD', 'min_years' => 1, 'max_years' => 10], ...]
     */
    abstract public function getTldPricing(): array;

    /**
     * Register $domain->domain for $domain->years years.
     * Return ['expires_at' => 'ISO date'] when the registry tells you the expiry, plus anything you
     * want stored: ['properties' => ['registrar_ref' => '...']].
     */
    abstract public function registerDomain(Domain $domain): array;

    /**
     * Renew the domain for $years years. Same return format as registerDomain.
     */
    abstract public function renewDomain(Domain $domain, int $years): array;

    /**
     * Current state at the registry.
     *
     * @return array ['status' => 'active|expired|pending_transfer|transferred_away', 'expires_at' => 'ISO date', 'nameservers' => [...], 'locked' => bool, 'privacy' => bool]
     */
    abstract public function getDomainInfo(Domain $domain): array;

    /**
     * @return array<string>
     */
    abstract public function getNameservers(Domain $domain): array;

    /**
     * @param  array<string>  $nameservers
     */
    abstract public function setNameservers(Domain $domain, array $nameservers): void;

    /*
     * Optional hooks, detected with ExtensionHelper::registrarSupports():
     *   transferDomain(Domain $domain): array          - start an inbound transfer with $domain->auth_code
     *   getTransferStatus(Domain $domain): string      - 'pending' | 'completed' | 'failed' | 'cancelled'
     *   getRegistrarLock(Domain $domain): bool
     *   setRegistrarLock(Domain $domain, bool $locked): void
     *   getAuthCode(Domain $domain): string
     *   getContacts(Domain $domain): array             - ['registrant' => contact, 'admin' => contact, 'tech' => contact, 'billing' => contact]
     *   setContacts(Domain $domain, array $contacts): void
     *   setPrivacy(Domain $domain, bool $privacy): void
     */

    /**
     * Contact details of a Paymenter user, built from the account and the default custom properties.
     */
    protected function contactFromUser(User $user): array
    {
        $properties = $user->properties->pluck('value', 'key');

        return [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'organization' => $properties['company_name'] ?? '',
            'email' => $user->email,
            'phone' => $properties['phone'] ?? '',
            'address1' => $properties['address'] ?? '',
            'address2' => $properties['address2'] ?? '',
            'city' => $properties['city'] ?? '',
            'state' => $properties['state'] ?? '',
            'postal_code' => $properties['zip'] ?? '',
            'country' => $properties['country'] ?? '',
        ];
    }
}
