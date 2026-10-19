<?php

namespace App\Support\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\MeterReadingStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MeterReading;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a customer's bills and payments in step. Money a customer has paid but that isn't on a bill
 * yet (a payment's unapplied amount) is their advance credit; it always goes to the bill due first.
 */
final class Ledger
{
    /**
     * Applies the customer's unapplied payments, oldest first, to their open invoices, earliest due first.
     */
    public static function settle(Customer $customer): void
    {
        DB::transaction(function () use ($customer): void {
            $payments = Payment::query()
                ->where('customer_id', $customer->id)
                ->whereNull('voided_at')
                ->where('unapplied_amount', '>', 0)
                ->orderBy('paid_on')->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($payments->isEmpty()) {
                return;
            }

            $invoices = Invoice::query()
                ->where('customer_id', $customer->id)
                ->whereIn('status', InvoiceStatus::openValues())
                ->orderBy('due_date')->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($invoices as $invoice) {
                $due = Money::toCents($invoice->balance);

                foreach ($payments as $payment) {
                    $available = Money::toCents($payment->unapplied_amount);
                    if ($due <= 0 || $available <= 0) {
                        continue;
                    }

                    $applied = min($due, $available);
                    $payment->allocations()->create(['invoice_id' => $invoice->id, 'amount' => Money::fromCents($applied)]);
                    $payment->update(['unapplied_amount' => Money::fromCents($available - $applied)]);
                    $due -= $applied;
                }

                self::refresh($invoice);
            }
        });
    }

    /**
     * Recomputes what has been paid on the invoice, its balance and its status.
     */
    public static function refresh(Invoice $invoice): void
    {
        if ($invoice->status === InvoiceStatus::Void) {
            return;
        }

        $paidCents = Money::toCents(
            PaymentAllocation::query()
                ->where('invoice_id', $invoice->id)
                ->whereHas('payment', fn ($query) => $query->whereNull('voided_at'))
                ->sum('amount'),
        );
        $balanceCents = Money::toCents($invoice->total_amount) - $paidCents;

        $invoice->update([
            'amount_paid' => Money::fromCents($paidCents),
            'balance' => Money::fromCents(max($balanceCents, 0)),
            'status' => match (true) {
                $balanceCents <= 0 => InvoiceStatus::Paid,
                $paidCents > 0 => InvoiceStatus::PartiallyPaid,
                default => InvoiceStatus::Unpaid,
            },
        ]);
    }

    /**
     * When payments on the invoice add up to more than its total (after a debit memo is voided),
     * takes back the excess from the latest allocations; it returns to the payments as advance credit.
     */
    public static function trimOverpayment(Invoice $invoice): void
    {
        $allocations = PaymentAllocation::query()
            ->where('invoice_id', $invoice->id)
            ->whereHas('payment', fn ($query) => $query->whereNull('voided_at'))
            ->orderByDesc('id')
            ->get();

        $excess = Money::toCents($allocations->sum('amount')) - Money::toCents($invoice->total_amount);

        foreach ($allocations as $allocation) {
            if ($excess <= 0) {
                break;
            }

            $amount = Money::toCents($allocation->amount);
            $take = min($excess, $amount);
            $take === $amount ? $allocation->delete() : $allocation->update(['amount' => Money::fromCents($amount - $take)]);
            self::refreshPayment($allocation->payment);
            $excess -= $take;
        }

        self::refresh($invoice);
    }

    /**
     * Recomputes a payment's unapplied amount from what is allocated.
     */
    public static function refreshPayment(Payment $payment): void
    {
        $allocated = Money::toCents($payment->allocations()->sum('amount'));
        $payment->update(['unapplied_amount' => Money::fromCents(max(Money::toCents($payment->amount) - $allocated, 0))]);
    }

    /**
     * What the customer owes on open bills and the advance credit they have, in cents.
     *
     * @return array{owed: int, credit: int}
     */
    public static function summary(Customer $customer): array
    {
        return [
            'owed' => Money::toCents($customer->invoices()->whereIn('status', InvoiceStatus::openValues())->sum('balance')),
            'credit' => Money::toCents($customer->payments()->whereNull('voided_at')->sum('unapplied_amount')),
        ];
    }

    /**
     * Records a payment and applies it to the customer's oldest bills; what is left becomes advance credit.
     *
     * @param  array{paid_on: string, amount: string, method: string, reference_number: ?string, notes: ?string}  $attributes
     */
    public static function recordPayment(Customer $customer, array $attributes, User $receivedBy): Payment
    {
        return DB::transaction(function () use ($customer, $attributes, $receivedBy): Payment {
            $payment = Payment::createNumbered($customer->company, [
                ...$attributes,
                'customer_id' => $customer->id,
                'unapplied_amount' => $attributes['amount'],
                'received_by_id' => $receivedBy->id,
            ]);

            self::settle($customer);

            return $payment->fresh();
        });
    }

    /**
     * Voids a payment: the bills it paid are open again, and the customer's other credit is re-applied.
     */
    public static function voidPayment(Payment $payment, string $reason, User $user): void
    {
        DB::transaction(function () use ($payment, $reason, $user): void {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->isVoid()) {
                throw new BillingException("Official receipt {$payment->receipt_number} is already void.");
            }

            $invoiceIds = $payment->allocations()->pluck('invoice_id');
            $payment->allocations()->delete();
            $payment->update(['voided_at' => now(), 'voided_by_id' => $user->id, 'void_reason' => $reason, 'unapplied_amount' => 0]);

            Invoice::query()->whereIn('id', $invoiceIds)->get()->each(fn (Invoice $invoice) => self::refresh($invoice));
            self::settle($payment->customer);
        });
    }

    /**
     * Voids an invoice so it can be issued again correctly. Payments applied to it go back to the
     * customer's advance credit (and on to their other bills); its reading, work order fees and the
     * overdue bills it surcharged are released.
     */
    public static function voidInvoice(Invoice $invoice, string $reason, User $user): void
    {
        DB::transaction(function () use ($invoice, $reason, $user): void {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status === InvoiceStatus::Void) {
                throw new BillingException("Invoice {$invoice->invoice_number} is already void.");
            }

            $paymentIds = $invoice->allocations()->pluck('payment_id');
            $invoice->allocations()->delete();
            Payment::query()->whereIn('id', $paymentIds)->get()->each(fn (Payment $payment) => self::refreshPayment($payment));

            $invoice->update([
                'status' => InvoiceStatus::Void,
                'amount_paid' => 0,
                'balance' => 0,
                'void_reason' => $reason,
                'voided_at' => now(),
                'voided_by_id' => $user->id,
            ]);

            // Its memos go with it, and the work order flags they resolved are open again.
            foreach ($invoice->adjustments()->whereNull('voided_at')->with('workOrder')->get() as $memo) {
                $memo->update(['voided_at' => now(), 'voided_by_id' => $user->id, 'void_reason' => "Invoice {$invoice->invoice_number} voided"]);
                $memo->workOrder?->update(['flag_resolved_at' => null, 'flag_resolution' => null]);
            }

            // Its reading can be corrected on the Meter Readings page and billed again.
            MeterReading::query()->where('invoice_id', $invoice->id)->update(['invoice_id' => null, 'status' => MeterReadingStatus::Ready]);
            WorkOrder::query()->where('fee_invoice_id', $invoice->id)->update(['fee_invoice_id' => null, 'fee_billed_at' => null]);

            // Overdue bills surcharged on this invoice can be surcharged again; surcharge lines on an
            // overdue bill itself can't be undone, so that bill stays surcharged.
            Invoice::query()->where('surcharged_on_invoice_id', $invoice->id)->update(['surcharged_at' => null, 'surcharged_on_invoice_id' => null]);

            self::settle($invoice->customer);
        });
    }
}
