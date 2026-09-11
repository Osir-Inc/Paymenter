<div class="container mt-14">
    @if($invoice = $domain->invoices()->where('status', 'pending')->first())
    <div class="w-full mb-4">
        <div class="bg-yellow-600/20 border-l-4 border-yellow-500 text-yellow-300 p-4 rounded-lg">
            <p class="font-medium">
                ⚠️ {{ __('services.outstanding_invoice') }}
                <a href="{{ route('invoices.show', $invoice)}}" class="underline hover:text-yellow-100 underline-offset-2">{{ __('services.view_and_pay') }}</a>.
            </p>
        </div>
    </div>
    @endif

    <div class="bg-background-secondary border border-neutral p-6 rounded-lg">
        <div class="flex flex-col md:flex-row justify-between md:items-center gap-2">
            <h1 class="text-2xl font-semibold">{{ $domain->domain }}</h1>
            <span class="font-semibold
                @if ($domain->status == 'active') text-success
                @elseif (in_array($domain->status, ['expired', 'cancelled', 'transferred_away'])) text-inactive
                @else text-warning
                @endif">
                {{ __('domains.statuses.' . $domain->status) }}
            </span>
        </div>

        @if (!$domain->isManageable())
            <p class="mt-4 text-sm text-base/50">{{ __('domains.not_manageable') }}</p>
        @else
        <div class="flex w-fit my-4 flex-row flex-wrap">
            @php
                $tabs = ['overview' => __('domains.overview'), 'nameservers' => __('domains.nameservers'), 'transfer' => __('domains.auth_code_title')];
                if ($supports['getContacts']) { $tabs['contacts'] = __('domains.contacts'); }
            @endphp
            @foreach ($tabs as $key => $label)
            <button wire:click="changeTab('{{ $key }}')"
                class="px-4 py-2 -mb-px focus:outline-none {{ $key == $tab ? 'border-b-2 border-gray-400 font-semibold' : 'text-base border-b border-gray-500' }}">
                {{ $label }}
            </button>
            @endforeach
        </div>
        <x-loading target="changeTab" />

        <div wire:loading.remove wire:target="changeTab">
        @if ($tab === 'overview')
            <div class="grid md:grid-cols-2 gap-6">
                <div class="flex flex-col gap-2">
                    @if ($domain->registered_at)
                    <div class="flex items-center text-base">
                        <span class="mr-2">{{ __('domains.registered_at') }}:</span>
                        <span class="text-base/50">{{ $domain->registered_at->format('M d, Y') }}</span>
                    </div>
                    @endif
                    @if ($domain->expires_at)
                    <div class="flex items-center text-base">
                        <span class="mr-2">{{ __('domains.expires_at') }}:</span>
                        <span class="text-base/50">{{ $domain->expires_at->format('M d, Y') }}</span>
                    </div>
                    @endif
                    <div class="flex items-center text-base">
                        <span class="mr-2">{{ __('domains.auto_renew') }}:</span>
                        <x-form.toggle wire:click="toggleAutoRenew" :checked="$domain->auto_renew" label="" />
                    </div>
                    <p class="text-xs text-base/50">{{ __('domains.auto_renew_help') }}</p>
                    @if ($supports['setPrivacy'])
                    <div class="flex items-center text-base mt-2">
                        <span class="mr-2">{{ __('domains.privacy') }}:</span>
                        <x-form.toggle wire:click="togglePrivacy" :checked="$domain->privacy" label="" />
                    </div>
                    <p class="text-xs text-base/50">{{ __('domains.privacy_help') }}</p>
                    @endif
                    <div class="mt-2">
                        <x-button.secondary wire:click="sync" class="!w-fit" wire:loading.attr="disabled">
                            <x-loading target="sync" />
                            <div wire:loading.remove wire:target="sync">{{ __('domains.sync') }}</div>
                        </x-button.secondary>
                    </div>
                </div>
                <form wire:submit="renew" class="flex flex-col gap-2">
                    <h3 class="text-lg font-semibold">{{ __('domains.renew_now') }}</h3>
                    <x-form.select wire:model="renewYears" name="renewYears" :label="__('domains.renew_for')">
                        @foreach ($domain->tld->terms() as $years)
                            <option value="{{ $years }}">{{ trans_choice(__('domains.years'), $years, ['count' => $years]) }}</option>
                        @endforeach
                    </x-form.select>
                    <x-button.primary type="submit" wire:loading.attr="disabled">
                        <x-loading target="renew" />
                        <div wire:loading.remove wire:target="renew">{{ __('domains.renew_now') }}</div>
                    </x-button.primary>
                </form>
            </div>
        @elseif ($tab === 'nameservers')
            <form wire:submit="updateNameservers" class="flex flex-col gap-2 max-w-xl">
                @foreach ($nameservers as $index => $nameserver)
                    <x-form.input wire:model="nameservers.{{ $index }}" name="nameservers.{{ $index }}"
                        :label="__('domains.nameserver', ['number' => $index + 1])" :required="$index === 0" placeholder="ns{{ $index + 1 }}.example.com" />
                @endforeach
                <x-button.primary type="submit" wire:loading.attr="disabled">
                    <x-loading target="updateNameservers" />
                    <div wire:loading.remove wire:target="updateNameservers">{{ __('domains.update_nameservers') }}</div>
                </x-button.primary>
            </form>
        @elseif ($tab === 'transfer')
            <div class="grid md:grid-cols-2 gap-6">
                @if ($supports['getRegistrarLock'])
                <div class="flex flex-col gap-2">
                    <h3 class="text-lg font-semibold">{{ __('domains.registrar_lock') }}</h3>
                    <p class="text-sm text-base/50">{{ __('domains.registrar_lock_help') }}</p>
                    <div class="flex items-center text-base">
                        <span class="mr-2">{{ __('domains.status') }}:</span>
                        <span class="font-semibold {{ $locked ? 'text-success' : 'text-warning' }}">
                            {{ $locked ? __('domains.locked') : __('domains.unlocked') }}
                        </span>
                    </div>
                    @if ($supports['setRegistrarLock'])
                    <x-button.secondary wire:click="toggleLock" class="!w-fit" wire:loading.attr="disabled">
                        <x-loading target="toggleLock" />
                        <div wire:loading.remove wire:target="toggleLock">{{ $locked ? __('domains.unlock') : __('domains.lock') }}</div>
                    </x-button.secondary>
                    @endif
                </div>
                @endif
                @if ($supports['getAuthCode'])
                <div class="flex flex-col gap-2">
                    <h3 class="text-lg font-semibold">{{ __('domains.auth_code_title') }}</h3>
                    <p class="text-sm text-base/50">{{ __('domains.auth_code_help_out') }}</p>
                    @if ($authCode !== null)
                        <code class="bg-background border border-neutral rounded-md px-2.5 py-2 select-all w-fit">{{ $authCode }}</code>
                    @else
                        <x-button.secondary wire:click="showAuthCode" class="!w-fit" wire:loading.attr="disabled">
                            <x-loading target="showAuthCode" />
                            <div wire:loading.remove wire:target="showAuthCode">{{ __('domains.show_auth_code') }}</div>
                        </x-button.secondary>
                    @endif
                </div>
                @endif
            </div>
        @elseif ($tab === 'contacts')
            <form wire:submit="updateContacts" class="flex flex-col gap-6">
                @foreach ($contacts as $type => $contact)
                <div>
                    <h3 class="text-lg font-semibold mb-2">{{ __('domains.contact_types.' . $type) }}</h3>
                    <div class="grid md:grid-cols-3 gap-3">
                        @foreach (\App\Livewire\Domains\Show::CONTACT_FIELDS as $field)
                            <x-form.input wire:model="contacts.{{ $type }}.{{ $field }}" name="contacts.{{ $type }}.{{ $field }}"
                                :label="__('domains.contact_fields.' . $field)" :required="in_array($field, ['first_name', 'last_name', 'email', 'country'])" />
                        @endforeach
                    </div>
                </div>
                @endforeach
                @if ($supports['setContacts'] && count($contacts) > 0)
                <x-button.primary type="submit" class="!w-fit" wire:loading.attr="disabled">
                    <x-loading target="updateContacts" />
                    <div wire:loading.remove wire:target="updateContacts">{{ __('domains.update_contacts') }}</div>
                </x-button.primary>
                @endif
            </form>
        @endif
        </div>
        @endif
    </div>
</div>
