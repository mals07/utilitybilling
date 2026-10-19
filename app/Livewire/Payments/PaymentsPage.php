<?php

namespace App\Livewire\Payments;

use App\Enums\Access;
use App\Enums\InvoiceStatus;
use App\Enums\Page;
use App\Enums\PaymentMethod;
use App\Livewire\Concerns\GuardsPageAccess;
use App\Livewire\Concerns\ParsesIds;
use App\Livewire\Concerns\SearchesCustomers;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Billing\BillingException;
use App\Support\Billing\BillingRun;
use App\Support\Billing\Ledger;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Payments: record money received (applied to the customer's oldest bills, any excess kept as advance
 * credit), see past payments with their official receipts, and void a payment entered by mistake.
 */
#[Layout('layouts.app')]
#[Title('Payments')]
class PaymentsPage extends Component
{
    use GuardsPageAccess, ParsesIds, SearchesCustomers, WithPagination;

    public const PER_PAGE = 15;

    public const MINIMUM_ROWS = 10;

    private const AMOUNT_PATTERN = '/^\d{1,10}(\.\d{1,2})?$/';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /**
     * Opens the page with this customer chosen, e.g. from an invoice's "Record Payment".
     */
    #[Url(as: 'customer', except: '')]
    public string $preselect = '';

    public string $amount = '';

    public string $paidOn = '';

    public string $method = 'cash';

    public string $referenceNumber = '';

    public string $notes = '';

    /**
     * The payment open in the details modal.
     */
    #[Url(as: 'payment', except: '')]
    public string $paymentId = '';

    public bool $voiding = false;

    public string $voidReason = '';

    protected function page(): Page
    {
        return Page::Payments;
    }

    public function mount(): void
    {
        $this->paidOn = BillingRun::today($this->company());

        if (($id = self::idFrom($this->preselect)) !== null) {
            $this->chooseCustomer($id);
        }
        $this->preselect = '';
    }

    public function updated(string $property): void
    {
        if ($property === 'search') {
            $this->resetPage();
        }
        if (in_array($property, ['amount', 'paidOn', 'method', 'referenceNumber', 'notes', 'voidReason'], true)) {
            $this->resetValidation($property);
        }
    }

    protected function customerChosen(Customer $customer): void
    {
        // Suggest paying everything owed.
        $owed = Ledger::summary($customer)['owed'];
        $this->amount = $owed > 0 ? Money::fromCents($owed) : '';
    }

    protected function customerCleared(): void
    {
        $this->amount = '';
    }

    /**
     * The chosen customer's open invoices, earliest due first (the order payments are applied in).
     *
     * @return Collection<int, Invoice>
     */
    #[Computed]
    public function openInvoices(): Collection
    {
        $customer = $this->chosenCustomer;

        return $customer === null ? collect() : $customer->invoices()
            ->whereIn('status', InvoiceStatus::openValues())
            ->orderBy('due_date')->orderBy('id')
            ->get();
    }

    /**
     * How the amount typed would be applied: [invoice id => cents], plus what is left as advance credit.
     *
     * @return array{applied: array<int, int>, advance: int}
     */
    private function allocationPreview(): array
    {
        $remaining = preg_match(self::AMOUNT_PATTERN, trim($this->amount)) ? Money::toCents(trim($this->amount)) : 0;
        $credit = $this->chosenCustomer ? Ledger::summary($this->chosenCustomer)['credit'] : 0;
        $remaining += $credit;

        $applied = [];
        foreach ($this->openInvoices as $invoice) {
            $take = min($remaining, Money::toCents($invoice->balance));
            if ($take > 0) {
                $applied[$invoice->id] = $take;
                $remaining -= $take;
            }
        }

        return ['applied' => $applied, 'advance' => $remaining];
    }

    public function record(): void
    {
        if (! $this->allowed(Access::Add)) {
            return;
        }

        $this->resetValidation();
        unset($this->chosenCustomer);
        $customer = $this->chosenCustomer;
        $today = BillingRun::today($this->company());
        $amount = trim($this->amount);
        $method = PaymentMethod::tryFrom($this->method);
        $reference = trim($this->referenceNumber);
        $notes = trim($this->notes);

        $errors = [];
        if ($customer === null) {
            $errors['customerId'] = 'Search for the customer and pick them from the list.';
        }
        if (! preg_match(self::AMOUNT_PATTERN, $amount) || Money::toCents($amount) <= 0) {
            $errors['amount'] = 'Enter the amount received, such as 500 or 1250.50.';
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->paidOn) || ! strtotime($this->paidOn)) {
            $errors['paidOn'] = 'Enter the date the payment was received.';
        } elseif ($this->paidOn > $today) {
            $errors['paidOn'] = "The payment date can't be in the future.";
        }
        if ($method === null) {
            $errors['method'] = 'Choose how the customer paid.';
        } elseif ($method->requiresReference() && $reference === '') {
            $errors['referenceNumber'] = "Enter the {$method->label()} reference number.";
        }
        if (mb_strlen($reference) > 100) {
            $errors['referenceNumber'] = 'The reference number must not be greater than 100 characters.';
        }
        if (mb_strlen($notes) > 500) {
            $errors['notes'] = 'The notes must not be greater than 500 characters.';
        }

        if ($errors !== []) {
            foreach ($errors as $key => $message) {
                $this->addError($key, $message);
            }

            return;
        }

        $payment = Ledger::recordPayment($customer, [
            'paid_on' => $this->paidOn,
            'amount' => Money::fromCents(Money::toCents($amount)),
            'method' => $method->value,
            'reference_number' => $reference === '' ? null : $reference,
            'notes' => $notes === '' ? null : $notes,
        ], $this->user());

        $advance = Money::toCents($payment->unapplied_amount);
        $this->clearCustomer();
        $this->reset('referenceNumber', 'notes');
        $this->method = PaymentMethod::Cash->value;
        $this->paidOn = $today;
        unset($this->openInvoices);
        $this->resetPage();
        $this->paymentId = (string) $payment->id;

        $this->flash("Payment recorded. Official receipt {$payment->receipt_number}."
            .($advance > 0 ? ' '.Money::fromCents($advance).' is kept as advance credit for the next bill.' : ''));
    }

    /* ------------------------------ Details / void ----------------------------- */

    #[Computed]
    public function selected(): ?Payment
    {
        $id = self::idFrom($this->paymentId);

        return $id === null ? null : $this->company()->payments()
            ->with(['customer', 'allocations.invoice', 'receivedBy:id,name', 'voidedBy:id,name'])
            ->find($id);
    }

    public function openPayment(int $id): void
    {
        $this->closeModal();
        $this->paymentId = (string) $id;
    }

    public function closeModal(): void
    {
        $this->paymentId = '';
        $this->voiding = false;
        $this->voidReason = '';
        unset($this->selected);
        $this->resetValidation('voidReason');
    }

    public function voidPayment(): void
    {
        if (! $this->allowed(Access::Delete)) {
            return;
        }

        $reason = trim($this->voidReason);
        if ($reason === '' || mb_strlen($reason) > 255) {
            $this->addError('voidReason', $reason === '' ? 'Give the reason for voiding.' : 'The reason must not be greater than 255 characters.');

            return;
        }

        $payment = $this->selected;
        if ($payment === null) {
            $this->closeModal();

            return;
        }

        try {
            Ledger::voidPayment($payment, $reason, $this->user());
        } catch (BillingException $exception) {
            $this->addError('voidReason', $exception->getMessage());

            return;
        }

        $this->voiding = false;
        unset($this->selected, $this->openInvoices);
        $this->flash("Official receipt {$payment->receipt_number} voided. The bills it paid are open again.");
    }

    public function render(): View
    {
        $company = $this->company();
        $search = trim($this->search);
        $customer = $this->chosenCustomer;

        $query = $company->payments()
            ->with(['customer:id,account_number,first_name,last_name'])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('receipt_number', 'like', "%{$search}%")
                ->orWhere('reference_number', 'like', "%{$search}%")
                ->orWhereHas('customer', fn (Builder $query) => $query
                    ->where('account_number', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%"))))
            ->orderByDesc('paid_on')
            ->orderByDesc('id');

        return view('livewire.payments.payments-page', [
            'payments' => $this->paginateClamped($query, self::PER_PAGE),
            'chosenCustomer' => $customer,
            'customerMatches' => $this->customerMatches,
            'summary' => $customer ? Ledger::summary($customer) : null,
            'openInvoices' => $this->openInvoices,
            'preview' => $customer ? $this->allocationPreview() : null,
            'methods' => PaymentMethod::options(),
            'chosenMethod' => PaymentMethod::tryFrom($this->method),
            'selected' => $this->selected,
            'today' => BillingRun::today($company),
            'access' => $this->access,
        ]);
    }
}
