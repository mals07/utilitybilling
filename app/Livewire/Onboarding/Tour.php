<?php

namespace App\Livewire\Onboarding;

use App\Models\User;
use App\Support\Onboarding;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The tour of the app: starts on its own until the user finishes or skips it, and again from "Take the tour".
 */
class Tour extends Component
{
    /**
     * Remembers that the user finished or skipped the tour, so it no longer starts on its own.
     */
    public function complete(): void
    {
        $user = $this->user();

        if ($user->tour_completed_at === null) {
            $user->update(['tour_completed_at' => now()]);
        }
    }

    public function render(): View
    {
        $user = $this->user();

        return view('livewire.onboarding.tour', [
            'stops' => Onboarding::tour($user),
            'autoStart' => $user->tour_completed_at === null,
        ]);
    }

    private function user(): User
    {
        /** @var User */
        return Auth::user();
    }
}
