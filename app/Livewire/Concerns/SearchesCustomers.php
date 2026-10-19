<?php

namespace App\Livewire\Concerns;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * The "Account No." field: type an account number or a name, then pick the customer from the list.
 * Used with the livewire.partials.customer-search view. The component may define customerChosen().
 */
trait SearchesCustomers
{
    /**
     * What is typed in the Account No. field.
     */
    public string $customerSearch = '';

    /**
     * The customer picked from the matches.
     */
    public string $customerId = '';

    #[Computed]
    public function chosenCustomer(): ?Customer
    {
        $id = self::idFrom($this->customerId);

        return $id === null ? null : $this->company()->customers()->find($id);
    }

    /**
     * Customers whose account number or name matches every word typed.
     *
     * @return Collection<int, Customer>
     */
    #[Computed]
    public function customerMatches(): Collection
    {
        $words = preg_split('/\s+/', trim($this->customerSearch), -1, PREG_SPLIT_NO_EMPTY);

        if ($words === [] || $this->customerId !== '') {
            return collect();
        }

        $query = $this->company()->customers()->select(['id', 'account_number', 'first_name', 'last_name']);
        foreach ($words as $word) {
            $query->where(fn (Builder $query) => $query
                ->where('account_number', 'like', "%{$word}%")
                ->orWhere('first_name', 'like', "%{$word}%")
                ->orWhere('last_name', 'like', "%{$word}%"));
        }

        return $query->orderBy('account_number')->limit(8)->get();
    }

    /**
     * Typing after a customer was picked starts a new search.
     */
    public function updatedCustomerSearch(): void
    {
        if ($this->customerId !== '' && $this->customerSearch !== $this->chosenCustomer?->account_number) {
            $this->customerId = '';
            unset($this->chosenCustomer);
            $this->customerCleared();
        }
        unset($this->customerMatches);
        $this->resetValidation('customerId');
    }

    public function chooseCustomer(int $id): void
    {
        $customer = $this->company()->customers()->find($id);
        if ($customer === null) {
            return;
        }

        $this->customerId = (string) $customer->id;
        $this->customerSearch = $customer->account_number;
        unset($this->chosenCustomer, $this->customerMatches);
        $this->resetValidation('customerId');
        $this->customerChosen($customer);
    }

    public function clearCustomer(): void
    {
        $this->reset('customerSearch', 'customerId');
        unset($this->chosenCustomer, $this->customerMatches);
        $this->customerCleared();
    }

    protected function customerChosen(Customer $customer): void {}

    protected function customerCleared(): void {}
}
