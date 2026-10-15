<?php

namespace App\Livewire\Company;

use App\Enums\Access;
use App\Enums\Page;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Company Setup: Company Profile, Operation Setup and Linked Accounts tabs.
 */
#[Layout('layouts.app')]
#[Title('Company Setup')]
class CompanyPage extends Component
{
    public const TABS = ['profile', 'operation-setup', 'linked-accounts'];

    public string $initialTab = 'profile';

    public bool $justVerified = false;

    public function mount(): void
    {
        $tab = (string) request()->query('tab', '');
        $this->initialTab = in_array($tab, self::TABS, true) ? $tab : 'profile';
        $this->justVerified = request()->query('verified') === '1';
    }

    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();
        $onboarding = ! $user->hasCompany();

        return view('livewire.company.company-page', [
            'onboarding' => $onboarding,
            'canSave' => $onboarding || $user->canAccessPage(Page::CompanySetup, Access::Edit),
        ]);
    }
}
