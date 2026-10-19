<?php

namespace App\Support\Billing;

use App\Enums\FeeBilling;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceLineKind;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderFee;
use App\Support\Money;

/**
 * Bills a completed work order's fee: on its own invoice right away, or on the customer's next
 * monthly bill (BillingRun picks those up), as set per order type in the work order fee settings.
 */
final class WorkOrderBilling
{
    public static function billingFor(WorkOrder $order): FeeBilling
    {
        $setting = WorkOrderFee::query()
            ->where('company_id', $order->company_id)
            ->where('order_type', $order->order_type)
            ->first(['billing'])
            ?->billing;

        return $setting ?? FeeBilling::defaultFor($order->order_type);
    }

    /**
     * Issues the fee's own invoice when the order type bills fees separately.
     */
    public static function onCompleted(WorkOrder $order, User $actor, string $invoiceDate): ?Invoice
    {
        if ($order->fee_amount === null || Money::toCents($order->fee_amount) <= 0 || $order->fee_invoice_id !== null) {
            return null;
        }
        if (self::billingFor($order) !== FeeBilling::SeparateInvoice) {
            return null;
        }

        $customer = $order->customer;
        $invoice = BillingRun::issue($order->company, $customer, [
            'kind' => InvoiceKind::WorkOrder,
            'work_order_id' => $order->id,
            'invoice_date' => $invoiceDate,
            'previous_balance' => Money::fromCents(Ledger::summary($customer)['owed']),
        ], [[
            'kind' => InvoiceLineKind::WorkOrderFee,
            'description' => "{$order->order_type->label()} fee (work order {$order->order_number})",
            'quantity' => null,
            'rate' => null,
            'cents' => Money::toCents($order->fee_amount),
            'work_order_id' => $order->id,
        ]], $actor);

        $order->update(['fee_invoice_id' => $invoice->id, 'fee_billed_at' => now()]);
        Ledger::settle($customer);

        return $invoice;
    }
}
