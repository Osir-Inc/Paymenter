<?php

namespace App\Livewire\Domains;

use App\Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public function render()
    {
        return view('domains.index', [
            'domains' => Auth::user()->domains()->with('tld')->orderBy('created_at', 'desc')->paginate(config('settings.pagination')),
        ])->layoutData([
            'title' => __('domains.my_domains'),
            'sidebar' => true,
        ]);
    }
}
