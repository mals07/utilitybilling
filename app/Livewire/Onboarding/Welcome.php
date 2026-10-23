<?php

namespace App\Livewire\Onboarding;

use App\Models\User;
use App\Support\Onboarding;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Shown once to someone who just signed up: thanks them, then walks through each setup wizard
 * step with a screenshot before they start it.
 */
#[Layout('layouts.setup')]
#[Title('Welcome')]
class Welcome extends Component
{
    public bool $justVerified = false;

    public function mount(): void
    {
        if (! $this->user()->needsWelcome()) {
            $this->redirectRoute($this->user()->homeRoute());

            return;
        }

        $this->justVerified = request()->query('verified') === '1';
    }

    /**
     * Remembers the welcome was seen (finished or skipped) and opens the setup wizard.
     */
    public function start(): void
    {
        $user = $this->user();

        if ($user->welcomed_at === null) {
            $user->update(['welcomed_at' => now()]);
        }

        $this->redirectRoute('setup');
    }

    public function render(): View
    {
        return view('livewire.onboarding.welcome', [
            'slides' => Onboarding::welcomeSlides(),
            'requiredCount' => count(array_filter(Onboarding::STEPS, Onboarding::isRequired(...))),
        ]);
    }

    private function user(): User
    {
        /** @var User */
        return Auth::user();
    }
}
