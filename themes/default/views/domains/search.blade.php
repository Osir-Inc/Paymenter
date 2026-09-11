<div class="container mt-14 flex flex-col gap-8">
    <div class="bg-background-secondary border border-neutral p-6 rounded-lg">
        <h1 class="text-3xl font-bold mb-4">{{ __('domains.search_title') }}</h1>
        <form wire:submit="search" class="flex flex-col md:flex-row gap-3 items-end">
            <x-form.input wire:model="query" name="query" :label="__('domains.domain')" :placeholder="__('domains.search_placeholder')" required class="text-lg" />
            <x-form.select wire:model="years" name="years" :label="__('domains.term')" divClass="md:!w-40">
                @for ($i = 1; $i <= 10; $i++)
                    <option value="{{ $i }}">{{ trans_choice(__('domains.years'), $i, ['count' => $i]) }}</option>
                @endfor
            </x-form.select>
            <x-button.primary type="submit" class="md:!w-40 h-[46px]">
                {{ __('domains.search') }}
            </x-button.primary>
        </form>
    </div>

    @if ($searched && count($results) > 0)
        <div class="flex flex-col gap-2">
            @foreach ($results as $result)
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 bg-background-secondary border border-neutral p-4 rounded-lg">
                    <div class="flex items-center gap-3">
                        @if ($result['error'])
                            <x-ri-error-warning-fill class="size-5 text-warning" />
                        @elseif ($result['available'])
                            <x-ri-checkbox-circle-fill class="size-5 text-success" />
                        @else
                            <x-ri-forbid-fill class="size-5 text-inactive" />
                        @endif
                        <span class="text-lg font-semibold">{{ $result['domain'] }}</span>
                        @if ($result['premium'])
                            <span class="text-xs uppercase px-2 py-0.5 rounded bg-warning/20 text-warning">{{ __('domains.premium') }}</span>
                        @endif
                    </div>
                    <div class="flex items-center gap-4">
                        @if ($result['error'])
                            <span class="text-sm text-base/50">{{ __('domains.check_failed') }}</span>
                        @elseif (!$result['available'])
                            <span class="text-sm text-base/50">{{ __('domains.taken') }}</span>
                        @elseif ($result['price'] === null)
                            <span class="text-sm text-base/50">{{ __('domains.no_price') }}</span>
                        @else
                            <span class="font-semibold">{{ $result['formatted'] }} <span class="text-sm text-base/50 font-normal">/ {{ trans_choice(__('domains.years'), $years, ['count' => $years]) }}</span></span>
                            @if ($result['in_cart'])
                                <a href="{{ route('cart') }}" wire:navigate>
                                    <x-button.secondary class="!w-fit">{{ __('domains.in_cart') }}</x-button.secondary>
                                </a>
                            @else
                                <x-button.primary wire:click="addToCart({{ $result['tld_id'] }})" wire:loading.attr="disabled" class="!w-fit">
                                    <x-loading target="addToCart({{ $result['tld_id'] }})" />
                                    <div wire:loading.remove wire:target="addToCart({{ $result['tld_id'] }})">
                                        {{ __('domains.add_to_cart') }}
                                    </div>
                                </x-button.primary>
                            @endif
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="bg-background-secondary border border-neutral p-6 rounded-lg">
        <h2 class="text-2xl font-semibold mb-1">{{ __('domains.transfer_title') }}</h2>
        <p class="text-sm text-base/50 mb-4">{{ __('domains.auth_code_help') }}</p>
        <form wire:submit="transfer" class="flex flex-col md:flex-row gap-3 items-end">
            <x-form.input wire:model="transferDomain" name="transferDomain" :label="__('domains.domain')" :placeholder="__('domains.transfer_placeholder')" required />
            <x-form.input wire:model="authCode" name="authCode" :label="__('domains.auth_code')" required />
            <x-button.secondary type="submit" class="md:!w-40 h-[46px]">
                {{ __('domains.transfer') }}
            </x-button.secondary>
        </form>
    </div>
</div>
