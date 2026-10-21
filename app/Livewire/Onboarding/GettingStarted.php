<?php

namespace App\Livewire\Onboarding;

use App\Models\User;
use App\Support\Onboarding;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The Getting Started checklist on the Dashboard: the setup steps a new company works through, ticked off as they happen.
 */
class GettingStarted extends Component
{
    /**
     * Hides the checklist for good; the tour stays available from the sidebar.
     */
    public function dismiss(): void
    {
        $this->user()->update(['getting_started_dismissed_at' => now()]);
    }

    public function render(): View
    {
        $user = $this->user();
        $steps = Onboarding::showsChecklist($user) ? Onboarding::checklist($user->company) : [];

        return view('livewire.onboarding.getting-started', [
            'steps' => $steps,
            'doneCount' => count(array_filter($steps, fn (array $step): bool => $step['done'])),
            'nextKey' => collect($steps)->first(fn (array $step): bool => ! $step['done'])['key'] ?? null,
        ]);
    }

    private function user(): User
    {
        /** @var User */
        return Auth::user();
    }
}
