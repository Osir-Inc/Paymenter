<div class="container mt-14 space-y-4">
    <x-navigation.breadcrumb />
    <div class="flex justify-end">
        <a href="{{ route('domains.search') }}" wire:navigate>
            <x-button.primary class="!w-fit">{{ __('domains.register') }}</x-button.primary>
        </a>
    </div>
    @forelse ($domains as $domain)
    <a href="{{ route('domains.show', $domain) }}" wire:navigate>
        <div class="bg-background-secondary hover:bg-background-secondary/80 border border-neutral p-4 rounded-lg mb-4">
            <div class="flex items-center justify-between mb-2">
                <div class="flex items-center gap-3">
                    <div class="bg-secondary/10 p-2 rounded-lg">
                        <x-ri-global-line class="size-5 text-secondary" />
                    </div>
                    <span class="font-medium">{{ $domain->domain }}</span>
                </div>
                <span class="text-sm font-semibold
                    @if ($domain->status == 'active') text-success
                    @elseif (in_array($domain->status, ['expired', 'cancelled', 'transferred_away'])) text-inactive
                    @else text-warning
                    @endif">
                    {{ __('domains.statuses.' . $domain->status) }}
                </span>
            </div>
            <div class="text-base text-sm flex gap-1">
                @if ($domain->expires_at)
                    {{ __('domains.expires_at') }}
                    <x-tooltip :message="$domain->expires_at->format('M d, Y')">
                        {{ $domain->expires_at->isFuture() ? $domain->expires_at->longAbsoluteDiffForHumans() : $domain->expires_at->format('M d, Y') }}
                    </x-tooltip>
                @endif
            </div>
        </div>
    </a>
    @empty
    <div class="bg-background-secondary border border-neutral p-4 rounded-lg">
        <p class="text-base text-sm">{{ __('domains.no_domains') }}</p>
    </div>
    @endforelse

    {{ $domains->links() }}
</div>
