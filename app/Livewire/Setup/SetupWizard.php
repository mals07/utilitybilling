<?php

namespace App\Livewire\Setup;

use App\Livewire\Company\OperationSettingsForm;
use App\Livewire\Company\ProfileForm;
use App\Livewire\Customers\CustomersPage;
use App\Livewire\Payments\PaymentsPage;
use App\Livewire\WorkOrders\WorkOrdersPage;
use App\Models\Company;
use App\Models\User;
use App\Support\Onboarding;
use App\Support\SetupExamples;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The setup wizard a new company works through before the rest of the app opens: company profile,
 * billing settings, lookup lists, rates and charges (required), then team, first customer, New
 * Installation work order, completing it and posting its fee payment (each skippable).
 *
 * Each step shows the real page or form it sets up, so what the user learns here is what they use
 * later. The current step is saved on the company, so signing out and back in resumes there.
 */
#[Layout('layouts.setup')]
#[Title('Company Setup')]
class SetupWizard extends Component
{
    public string $step = 'profile';

    /**
     * Bumped when an example adds records, so the embedded page is mounted again and shows them.
     */
    public int $exampleRun = 0;

    public ?string $error = null;

    public bool $justVerified = false;

    public function mount(): void
    {
        $company = $this->company();

        if ($company?->isSetUp()) {
            $this->redirectRoute('dashboard');

            return;
        }

        if ($this->user()->needsWelcome()) {
            $this->redirectRoute('welcome');

            return;
        }

        $this->justVerified = request()->query('verified') === '1';

        // Right after the profile is first saved no step is stored yet, so carry on to the next one.
        $saved = $company?->setup_step ?? ($company ? 'billing' : 'profile');
        $this->step = in_array($saved, Onboarding::STEPS, true) ? $saved : 'profile';
    }

    /**
     * Moves on once the current step is done; after the last step, setup is finished.
     */
    public function continue(): void
    {
        if (! $this->canRunSetup()) {
            return;
        }

        if (! Onboarding::isDone($this->company(), $this->step)) {
            $this->error = Onboarding::step($this->step)['notDone'];

            return;
        }

        $next = $this->offset(1);
        $next === null ? $this->finish() : $this->moveTo($next);
    }

    public function back(): void
    {
        if ($this->canRunSetup() && ($previous = $this->offset(-1)) !== null) {
            $this->moveTo($previous);
        }
    }

    /**
     * Opens an earlier step from the step list.
     */
    public function goTo(string $step): void
    {
        $target = array_search($step, Onboarding::STEPS, true);

        if ($this->canRunSetup() && $target !== false && $target < $this->index()) {
            $this->moveTo($step);
        }
    }

    /**
     * Skips an optional step: the team step moves on; skipping a later one finishes setup, since each needs the one before.
     */
    public function skip(): void
    {
        if (! $this->canRunSetup() || Onboarding::isRequired($this->step)) {
            return;
        }

        $this->step === Onboarding::FIRST_OPTIONAL_STEP ? $this->moveTo((string) $this->offset(1)) : $this->finish();
    }

    /**
     * Fills the step's form with its example, or adds the example records.
     */
    public function useExample(): void
    {
        if (! $this->canRunSetup()) {
            return;
        }

        $company = $this->company();
        if ($company === null && $this->step !== 'profile') {
            return;
        }

        $this->error = null;

        match ($this->step) {
            'profile' => $this->dispatch('setup-example')->to(ProfileForm::class),
            'billing' => $this->dispatch('setup-example')->to(OperationSettingsForm::class),
            'lists' => SetupExamples::lists($company),
            'rates' => SetupExamples::rates($company),
            'charges' => SetupExamples::charges($company),
            'team' => SetupExamples::roles($company),
            'customer' => $this->dispatch('setup-example')->to(CustomersPage::class),
            'work-order', 'complete-order' => $this->dispatch('setup-example', step: $this->step)->to(WorkOrdersPage::class),
            'payment' => $this->dispatch('setup-example')->to(PaymentsPage::class),
        };

        if (in_array($this->step, ['lists', 'rates', 'charges', 'team'], true)) {
            $this->exampleRun++;
        }
    }

    private function finish(): void
    {
        $this->company()->update(['setup_step' => null, 'setup_completed_at' => now()]);

        // The dashboard opens with the tour, which welcomes the user.
        $this->redirectRoute('dashboard');
    }

    private function moveTo(string $step): void
    {
        $this->step = $step;
        $this->error = null;
        $this->company()?->update(['setup_step' => $step]);
    }

    private function index(): int
    {
        return (int) array_search($this->step, Onboarding::STEPS, true);
    }

    private function offset(int $by): ?string
    {
        return Onboarding::STEPS[$this->index() + $by] ?? null;
    }

    /**
     * The person who signed up runs setup before their company exists; afterwards, any admin of it.
     */
    private function canRunSetup(): bool
    {
        $user = $this->user();

        return ! $user->hasCompany() || $user->is_admin;
    }

    private function user(): User
    {
        /** @var User */
        return Auth::user();
    }

    private function company(): ?Company
    {
        return $this->user()->company;
    }

    public function render(): View
    {
        $company = $this->company();

        if (! $this->canRunSetup()) {
            return view('livewire.setup.waiting', ['company' => $company]);
        }

        $steps = [];
        foreach (Onboarding::STEPS as $index => $key) {
            $steps[] = [
                'key' => $key,
                'number' => $index + 1,
                'nav' => Onboarding::step($key)['nav'],
                'state' => match (true) {
                    $key === $this->step => 'current',
                    $index < $this->index() => 'done',
                    default => 'upcoming',
                },
            ];
        }

        return view('livewire.setup.setup-wizard', [
            'company' => $company,
            'steps' => $steps,
            'current' => Onboarding::step($this->step),
            'number' => $this->index() + 1,
            'required' => Onboarding::isRequired($this->step),
            'isLast' => $this->offset(1) === null,
            'stepDone' => Onboarding::isDone($company, $this->step),
            'guide' => Onboarding::guideFor($this->step),
        ]);
    }
}
