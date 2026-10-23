<?php

namespace App\Livewire\Invoices;

use App\Enums\Access;
use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentType;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\OverdueHandling;
use App\Enums\Page;
use App\Livewire\Concerns\GuardsPageAccess;
use App\Livewire\Concerns\ParsesIds;
use App\Models\Customer;
use App\Models\Invoice;
use App\Support\Billing\Adjustments;
use App\Support\Billing\BillingException;
use App\Support\Billing\BillingRun;
use App\Support\Billing\Ledger;
use App\Support\Billing\Surcharges;
use App\Support\Format;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Invoices: every bill issued, with filters, the invoice details, voiding, and the billing settings.
 */
#[Layout('layouts.app')]
#[Title('Invoices')]
class InvoicesPage extends Component
{
    use GuardsPageAccess, ParsesIds, WithPagination;

    public const PER_PAGE = 15;

    public const MINIMUM_ROWS = 10;

    /**
     * Status filter values: the stored statuses plus "overdue" and "open".
     */
    public const STATUS_FILTERS = [
        'open' => 'Not fully paid',
        'overdue' => 'Overdue',
        'unpaid' => 'Unpaid',
        'partially_paid' => 'Partially Paid',
        'paid' => 'Paid',
        'void' => 'Void',
    ];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    #[Url(as: 'period', except: '')]
    public string $periodFilter = '';

    /**
     * The invoice open in the details modal.
     */
    #[Url(as: 'invoice', except: '')]
    public string $invoiceId = '';

    public bool $voiding = false;

    public string $voidReason = '';

    /**
     * The Adjust form (credit or debit memo) under the invoice details.
     */
    public bool $adjusting = false;

    /**
     * @var array{type: string, workOrderId: string, category: string, reason: string, amount: string, issuedOn: string}
     */
    public array $adjust = [];

    /**
     * The memo whose void reason is being asked for.
     */
    public string $voidingMemoId = '';

    public string $memoVoidReason = '';

    public bool $settingsOpen = false;

    public string $overdueHandling = '';

    protected function page(): Page
    {
        return Page::Invoices;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'statusFilter', 'periodFilter'], true)) {
            $this->resetPage();
        }
        if (in_array($property, ['voidReason', 'memoVoidReason'], true)) {
            $this->resetValidation($property);
        }
    }

    #[Computed]
    public function selected(): ?Invoice
    {
        $id = self::idFrom($this->invoiceId);

        return $id === null ? null : $this->company()->invoices()
            ->with([
                'customer', 'lines', 'meterReading', 'workOrder:id,order_number', 'allocations.payment', 'voidedBy:id,name', 'createdBy:id,name',
                'adjustments.createdBy:id,name', 'adjustments.voidedBy:id,name', 'adjustments.workOrder:id,order_number',
            ])
            ->find($id);
    }

    public function openInvoice(int $id): void
    {
        $this->closeModal();
        $this->invoiceId = (string) $id;
        unset($this->selected);
    }

    public function closeModal(): void
    {
        $this->invoiceId = '';
        $this->voiding = false;
        $this->voidReason = '';
        $this->closeAdjust();
        $this->reset('voidingMemoId', 'memoVoidReason');
        unset($this->selected);
        $this->resetValidation();
    }

    /* ------------------------------- Adjust ---------------------------------- */

    /**
     * Opens the Adjust form. Users with Delete access start on a credit memo; others can only add a debit memo.
     */
    public function openAdjust(): void
    {
        if (! $this->allowed(Access::Edit)) {
            return;
        }

        $this->voiding = false;
        $this->adjusting = true;
        $this->adjust = [
            'type' => ($this->access['delete'] ? AdjustmentType::Credit : AdjustmentType::Debit)->value,
            'workOrderId' => '',
            'category' => '',
            'reason' => '',
            'amount' => '',
            'issuedOn' => BillingRun::today($this->company()),
        ];
        $this->resetValidation();
    }

    public function closeAdjust(): void
    {
        $this->adjusting = false;
        $this->adjust = [];
        $this->resetValidation(array_map(fn (string $key): string => "adjust.{$key}", ['type', 'workOrderId', 'category', 'reason', 'amount', 'issuedOn']));
    }

    /**
     * Picking a flagged work order fills in the memo from it; changing the type clears a reason that doesn't fit.
     */
    public function updatedAdjust(mixed $value, ?string $key = null): void
    {
        if ($key !== null) {
            $this->resetValidation("adjust.{$key}");
        }

        if ($key === 'type') {
            $type = AdjustmentType::tryFrom((string) $value);
            $category = AdjustmentReason::tryFrom($this->adjust['category'] ?? '');
            if ($type === null || ($category !== null && ! $category->appliesTo($type))) {
                $this->adjust['category'] = '';
            }
            if ($type === AdjustmentType::Debit) {
                $this->adjust['workOrderId'] = '';
            }
        }

        if ($key === 'workOrderId' && ($id = self::idFrom((string) $value)) !== null && $this->selected) {
            $order = Adjustments::flaggedWorkOrders($this->selected->customer)->firstWhere('id', $id);
            if ($order !== null) {
                $suggestion = Adjustments::suggestionFor($order);
                $this->adjust['type'] = $suggestion['type']->value;
                $this->adjust['category'] = $suggestion['category']->value;
                $this->adjust['reason'] = $suggestion['reason'];
                if ($suggestion['amount'] !== null) {
                    $this->adjust['amount'] = (string) $suggestion['amount'];
                }
            }
        }
    }

    public function saveAdjustment(): void
    {
        $type = AdjustmentType::tryFrom($this->adjust['type'] ?? '');
        if ($type === null) {
            $this->addError('adjust.type', 'Choose a credit memo or a debit memo.');

            return;
        }
        if (! $this->allowed($type->requiredAccess())) {
            return;
        }

        $invoice = $this->selected;
        if ($invoice === null) {
            $this->closeModal();

            return;
        }

        $this->resetValidation();
        $category = AdjustmentReason::tryFrom($this->adjust['category'] ?? '');
        $reason = trim((string) ($this->adjust['reason'] ?? ''));
        $amount = trim((string) ($this->adjust['amount'] ?? ''));
        $issuedOn = (string) ($this->adjust['issuedOn'] ?? '');
        $today = BillingRun::today($this->company());

        $errors = [];
        if ($category === null || ! $category->appliesTo($type)) {
            $errors['adjust.category'] = 'Choose the reason.';
        }
        if ($reason === '') {
            $errors['adjust.reason'] = 'Explain the adjustment; it is printed on the memo.';
        } elseif (mb_strlen($reason) > 500) {
            $errors['adjust.reason'] = 'The explanation must not be greater than 500 characters.';
        }
        if (! preg_match('/^\d{1,10}(\.\d{1,2})?$/', $amount) || Money::toCents($amount) <= 0) {
            $errors['adjust.amount'] = 'Enter an amount such as 150 or 150.50.';
        } elseif ($type === AdjustmentType::Credit && Money::toCents($amount) > Money::toCents($invoice->balance)) {
            $errors['adjust.amount'] = "A credit memo can't be more than the unpaid balance (".Format::amount($invoice->balance).').';
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $issuedOn) || ! strtotime($issuedOn)) {
            $errors['adjust.issuedOn'] = 'Enter the memo date.';
        } elseif ($issuedOn > $today) {
            $errors['adjust.issuedOn'] = "The memo date can't be in the future.";
        }

        $workOrder = null;
        if (($id = self::idFrom($this->adjust['workOrderId'] ?? '')) !== null) {
            $workOrder = Adjustments::flaggedWorkOrders($invoice->customer)->firstWhere('id', $id);
            if ($workOrder === null) {
                $errors['adjust.workOrderId'] = 'Choose a flagged work order from the list.';
            }
        }

        if ($errors !== []) {
            foreach ($errors as $key => $message) {
                $this->addError($key, $message);
            }

            return;
        }

        try {
            $memo = Adjustments::issue($invoice, $type, $category, $reason, Money::toCents($amount), $issuedOn, $workOrder, $this->user());
        } catch (BillingException $exception) {
            $this->addError('adjust.amount', $exception->getMessage());

            return;
        }

        $this->closeAdjust();
        unset($this->selected);
        $this->flash("{$type->label()} {$memo->label()} issued for ".Format::amount($memo->amount)." on invoice {$invoice->invoice_number}.");
    }

    public function askVoidMemo(int $id): void
    {
        $this->voidingMemoId = (string) $id;
        $this->memoVoidReason = '';
        $this->resetValidation('memoVoidReason');
    }

    public function voidMemo(): void
    {
        if (! $this->allowed(Access::Delete)) {
            return;
        }

        $reason = trim($this->memoVoidReason);
        if ($reason === '' || mb_strlen($reason) > 255) {
            $this->addError('memoVoidReason', $reason === '' ? 'Give the reason for voiding.' : 'The reason must not be greater than 255 characters.');

            return;
        }

        $memo = $this->selected?->adjustments->firstWhere('id', self::idFrom($this->voidingMemoId));
        if ($memo === null) {
            $this->reset('voidingMemoId', 'memoVoidReason');

            return;
        }

        try {
            Adjustments::void($memo, $reason, $this->user());
        } catch (BillingException $exception) {
            $this->addError('memoVoidReason', $exception->getMessage());

            return;
        }

        $this->reset('voidingMemoId', 'memoVoidReason');
        unset($this->selected);
        $this->flash("{$memo->label()} voided; the invoice is back to its amount before the memo.");
    }

    /* ---------------------------------- Void --------------------------------- */

    public function askVoid(): void
    {
        $this->voiding = true;
        $this->voidReason = '';
    }

    public function voidInvoice(): void
    {
        if (! $this->allowed(Access::Delete)) {
            return;
        }

        $reason = trim($this->voidReason);
        if ($reason === '' || mb_strlen($reason) > 255) {
            $this->addError('voidReason', $reason === '' ? 'Give the reason for voiding.' : 'The reason must not be greater than 255 characters.');

            return;
        }

        $invoice = $this->selected;
        if ($invoice === null) {
            $this->closeModal();

            return;
        }

        try {
            Ledger::voidInvoice($invoice, $reason, $this->user());
        } catch (BillingException $exception) {
            $this->addError('voidReason', $exception->getMessage());

            return;
        }

        $this->voiding = false;
        unset($this->selected);
        $this->flash("Invoice {$invoice->invoice_number} voided."
            .($invoice->kind === InvoiceKind::Monthly ? ' The customer can be billed again for '.$invoice->periodLabel().' on Generate Bills.' : ''));
    }

    /* -------------------------------- Settings ------------------------------- */

    public function openSettings(): void
    {
        $this->overdueHandling = $this->company()->overdue_handling?->value ?? OverdueHandling::NextBill->value;
        $this->settingsOpen = true;
    }

    public function closeSettings(): void
    {
        $this->settingsOpen = false;
        $this->resetValidation('overdueHandling');
    }

    public function saveSettings(): void
    {
        if (! $this->allowed(Access::Edit)) {
            return;
        }

        $this->validate(
            ['overdueHandling' => ['required', Rule::enum(OverdueHandling::class)]],
            ['overdueHandling.required' => 'Choose how overdue bills are handled.', 'overdueHandling.enum' => 'Choose how overdue bills are handled.'],
        );

        $this->company()->update(['overdue_handling' => $this->overdueHandling]);
        $this->settingsOpen = false;
        $this->flash('Billing settings saved.');
    }

    public function applySurcharges(): void
    {
        if (! $this->allowed(Access::Edit)) {
            return;
        }

        $count = Surcharges::applyToOverdueInvoices($this->company(), BillingRun::today($this->company()));
        $this->flash($count === 0 ? 'No overdue invoices are waiting for a surcharge.' : "Surcharge added to {$count} overdue invoice".($count === 1 ? '' : 's').'.');
    }

    /* --------------------------------- Render -------------------------------- */

    public function render(): View
    {
        $company = $this->company();
        $today = BillingRun::today($company);
        $search = trim($this->search);

        $query = $company->invoices()
            ->with(['customer:id,account_number,first_name,last_name'])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('invoice_number', 'like', "%{$search}%")
                ->orWhereIn('customer_id', Customer::idsMatching($company->id, $search))))
            ->when(preg_match('/^\d{4}-\d{2}$/', $this->periodFilter), fn (Builder $query) => $query->where('billing_period', $this->periodFilter))
            ->when($this->statusFilter !== '', fn (Builder $query) => match ($this->statusFilter) {
                'open' => $query->whereIn('status', InvoiceStatus::openValues()),
                'overdue' => $query->whereIn('status', InvoiceStatus::openValues())->whereDate('due_date', '<', $today),
                default => InvoiceStatus::tryFrom($this->statusFilter) ? $query->where('status', $this->statusFilter) : $query,
            })
            ->orderByDesc('invoice_date')
            ->orderByDesc('id');

        $open = $company->invoices()->whereIn('status', InvoiceStatus::openValues());
        $overdue = (clone $open)->whereDate('due_date', '<', $today);

        $selected = $this->selected;
        $adjustType = AdjustmentType::tryFrom($this->adjust['type'] ?? '');

        return view('livewire.invoices.invoices-page', [
            'invoices' => $this->paginateClamped($query, self::PER_PAGE),
            'selected' => $selected,
            'flaggedWorkOrders' => $selected && $this->adjusting ? Adjustments::flaggedWorkOrders($selected->customer) : collect(),
            'adjustType' => $adjustType,
            'adjustReasons' => $adjustType ? AdjustmentReason::options($adjustType) : [],
            'today' => $today,
            'timezone' => $company->timezone,
            'outstanding' => Money::fromCents(Money::toCents((clone $open)->sum('balance'))),
            'overdueAmount' => Money::fromCents(Money::toCents((clone $overdue)->sum('balance'))),
            'overdueCount' => (clone $overdue)->count(),
            'awaitingSurcharge' => $company->overdue_handling === OverdueHandling::SameInvoice
                ? (clone $overdue)->whereNull('surcharged_at')->count()
                : 0,
            'overdueHandling' => $company->overdue_handling ?? OverdueHandling::NextBill,
            'access' => $this->access,
            'canRecordPayments' => $this->user()->canAccessPage(Page::Payments, Access::Add),
        ]);
    }
}
