<?php

namespace App\Livewire;

use App\Enums\Page;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Dashboard')]
class Dashboard extends Component
{
    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();
        $company = $user->company()->with(['city:id,name', 'province:id,name', 'country:id,name'])->firstOrFail();

        return view('livewire.dashboard', [
            'company' => $company,
            'canEditCompany' => $user->canAccessPage(Page::CompanySetup),
        ]);
    }
}
