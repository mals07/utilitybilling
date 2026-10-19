<?php

namespace App\Support\Billing;

use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentType;
use App\Enums\InvoiceLineKind;
use App\Enums\InvoiceStatus;
use App\Enums\WorkOrderFlag;
use App\Enums\WorkOrderStatus;
use App\Enums\WorkOrderType;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Format;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Credit and debit memos on an open invoice. The invoice itself is never edited: a memo adds a line
 * (negative for a credit, positive for a debit) and voiding a memo adds a reversing line.
 */
final class Adjustments
{
    /**
     * Review flags an adjustment can resolve.
     */
    public const RESOLVABLE_FLAGS = [WorkOrderFlag::BillingAdjustment, WorkOrderFlag::LeakAdjustment];

    /**
     * The customer's completed work orders flagged for a billing or leak adjustment, not resolved yet.
     *
     * @return Collection<int, WorkOrder>
     */
    public static function flaggedWorkOrders(Customer $customer): Collection
    {
        return $customer->workOrders()
            ->where('status', WorkOrderStatus::Completed)
            ->whereIn('review_flag', array_map(fn (WorkOrderFlag $flag): string => $flag->value, self::RESOLVABLE_FLAGS))
            ->whereNull('flag_resolved_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * What a flagged work order suggests for the memo: a credit, its reason, and an amount when the
     * order recorded one (a Billing Complaint's adjustment amount).
     *
     * @return array{type: AdjustmentType, category: AdjustmentReason, amount: ?string, reason: string}
     */
    public static function suggestionFor(WorkOrder $order): array
    {
        $fields = $order->completion_data['fields'] ?? [];

        $category = match (true) {
            $order->review_flag === WorkOrderFlag::LeakAdjustment => AdjustmentReason::LeakAdjustment,
            $order->order_type === WorkOrderType::MeterReadingVerification => AdjustmentReason::MeterReadingError,
            default => AdjustmentReason::BillingError,
        };

        $detail = match ($order->order_type) {
            WorkOrderType::BillingComplaint => $fields['findings'] ?? null,
            WorkOrderType::MeterReadingVerification => isset($fields['verified_reading'])
                ? "Disputed reading {$fields['disputed_reading']}, verified reading {$fields['verified_reading']}."
                : null,
            WorkOrderType::LeakRepair => isset($fields['cause']) ? "Leak after the meter: {$fields['cause']}." : null,
            default => null,
        };

        return [
            'type' => AdjustmentType::Credit,
            'category' => $category,
            'amount' => $fields['adjustment_amount'] ?? null,
            'reason' => trim("Work order {$order->order_number} ({$order->order_type->label()}). ".($detail ?? '')),
        ];
    }

    /**
     * Issues a memo against the invoice.
     *
     * @throws BillingException
     */
    public static function issue(
        Invoice $invoice,
        AdjustmentType $type,
        AdjustmentReason $category,
        string $reason,
        int $cents,
        string $issuedOn,
        ?WorkOrder $workOrder,
        User $actor,
    ): InvoiceAdjustment {
        return DB::transaction(function () use ($invoice, $type, $category, $reason, $cents, $issuedOn, $workOrder, $actor): InvoiceAdjustment {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if (! $invoice->status->isOpen()) {
                throw new BillingException("Invoice {$invoice->invoice_number} is {$invoice->status->label()}; only unpaid or partially paid invoices can be adjusted.");
            }
            if ($cents <= 0) {
                throw new BillingException('Enter an amount greater than zero.');
            }
            $balance = Money::toCents($invoice->balance);
            if ($type === AdjustmentType::Credit && $cents > $balance) {
                throw new BillingException("A credit memo can't be more than the unpaid balance (".Format::amount(Money::fromCents($balance)).').');
            }
            if (! $category->appliesTo($type)) {
                throw new BillingException("Choose a reason for a {$type->label()}.");
            }
            if ($workOrder !== null && ! self::flaggedWorkOrders($invoice->customer)->contains('id', $workOrder->id)) {
                throw new BillingException("Work order {$workOrder->order_number} has no open adjustment flag for this customer.");
            }

            $memo = InvoiceAdjustment::createNumbered($invoice->company, [
                'type' => $type,
                'invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'reason_category' => $category,
                'reason' => $reason,
                'amount' => Money::fromCents($cents),
                'issued_on' => $issuedOn,
                'work_order_id' => $workOrder?->id,
                'created_by_id' => $actor->id,
            ]);

            $line = self::addLine($invoice, "{$type->label()} {$memo->label()} – {$category->label()}", $type->sign() * $cents, $memo->id);
            $memo->update(['invoice_line_id' => $line->id]);

            if ($workOrder !== null) {
                $workOrder->update(['flag_resolved_at' => now(), 'flag_resolution' => "{$memo->label()} on invoice {$invoice->invoice_number}"]);
                $workOrder->log($actor, 'flag_resolved', "{$workOrder->review_flag->label()} resolved by {$type->label()} {$memo->label()} (".Format::amount($memo->amount).") on invoice {$invoice->invoice_number}.");
            }

            Ledger::settle($invoice->customer);

            return $memo->fresh();
        });
    }

    /**
     * Voids a memo: a reversing line undoes its effect on the invoice, and the work order flag it
     * resolved is open again.
     *
     * @throws BillingException
     */
    public static function void(InvoiceAdjustment $memo, string $reason, User $actor): void
    {
        DB::transaction(function () use ($memo, $reason, $actor): void {
            $memo = InvoiceAdjustment::query()->lockForUpdate()->findOrFail($memo->id);
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($memo->invoice_id);

            if ($memo->isVoid()) {
                throw new BillingException("{$memo->label()} is already void.");
            }
            if ($invoice->status === InvoiceStatus::Void) {
                throw new BillingException("Invoice {$invoice->invoice_number} is void.");
            }

            $memo->update(['voided_at' => now(), 'voided_by_id' => $actor->id, 'void_reason' => $reason]);
            self::addLine($invoice, "Void of {$memo->type->label()} {$memo->label()}", -$memo->type->sign() * Money::toCents($memo->amount), $memo->id);

            if ($memo->workOrder !== null) {
                $memo->workOrder->update(['flag_resolved_at' => null, 'flag_resolution' => null]);
                $memo->workOrder->log($actor, 'flag_reopened', "{$memo->label()} was voided ({$reason}); the {$memo->workOrder->review_flag?->label()} flag is open again.");
            }

            // Voiding a debit memo can leave more paid than the invoice now totals; that goes back to advance credit.
            Ledger::trimOverpayment($invoice->fresh());
            Ledger::settle($invoice->customer);
        });
    }

    /**
     * Adds a line to the invoice and moves its total and balance by the same amount.
     */
    private static function addLine(Invoice $invoice, string $description, int $cents, int $adjustmentId): InvoiceLine
    {
        $line = $invoice->lines()->create([
            'kind' => InvoiceLineKind::Adjustment,
            'description' => mb_substr($description, 0, 255),
            'amount' => Money::fromCents($cents),
            'adjustment_id' => $adjustmentId,
            'sort' => (int) $invoice->lines()->max('sort') + 1,
        ]);

        $invoice->update(['total_amount' => Money::fromCents(max(Money::toCents($invoice->total_amount) + $cents, 0))]);
        Ledger::refresh($invoice);

        return $line;
    }
}
