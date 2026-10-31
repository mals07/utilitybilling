<?php

namespace App\Support;

use App\Enums\DisconnectionNoticeStatus;
use App\Enums\Page;
use App\Models\Customer;
use App\Models\User;
use App\Models\WorkOrderActivity;
use Carbon\CarbonInterface;

/**
 * Everything that happened on a customer's account, latest first: work order updates, bills, payments,
 * credit and debit memos, meter readings and disconnection notices. Voids and closings are entries of
 * their own, so the record they change still shows when it was first made.
 *
 * Each section shows only to users who may view the page it is managed on.
 */
final class CustomerHistory
{
    /**
     * The history's sections and the page whose View access shows each. Memos are issued on the Invoices page.
     *
     * @var array<string, Page>
     */
    public const SECTIONS = [
        'Work orders' => Page::WorkOrders,
        'Invoices and memos' => Page::Invoices,
        'Payments' => Page::Payments,
        'Meter readings' => Page::MeterReadings,
        'Disconnection notices' => Page::Disconnections,
    ];

    /**
     * The sections the user may not see.
     *
     * @return list<string>
     */
    public static function hiddenSections(User $viewer): array
    {
        return array_keys(array_filter(self::SECTIONS, fn (Page $page): bool => ! $viewer->canAccessPage($page)));
    }

    /**
     * @return list<array{kind: string, title: string, detail: ?string, amount: ?string, by: ?string, at: CarbonInterface, isVoid: bool}>
     */
    public static function for(Customer $customer, User $viewer): array
    {
        $entries = [];
        $add = function (string $kind, string $title, ?string $detail, ?string $amount, ?User $by, ?CarbonInterface $at, bool $isVoid = false) use (&$entries): void {
            if ($at !== null) {
                $entries[] = ['kind' => $kind, 'title' => $title, 'detail' => $detail, 'amount' => $amount, 'by' => $by?->name, 'at' => $at, 'isVoid' => $isVoid];
            }
        };

        if ($viewer->canAccessPage(Page::WorkOrders)) {
            $activities = WorkOrderActivity::query()
                ->whereIn('work_order_id', $customer->workOrders()->select('id'))
                ->with(['workOrder:id,order_number,order_type', 'user:id,name'])
                ->orderBy('id')
                ->get();
            foreach ($activities as $activity) {
                $order = $activity->workOrder;
                $add('Work Order', "Work order {$order->order_number} · {$order->order_type->label()}", $activity->description, null, $activity->user, $activity->created_at, $activity->event === 'cancelled');
            }
        }

        if ($viewer->canAccessPage(Page::Invoices)) {
            foreach ($customer->invoices()->with(['createdBy:id,name', 'voidedBy:id,name'])->orderBy('id')->get() as $invoice) {
                $detail = implode(' · ', array_filter([$invoice->kind->label(), $invoice->periodLabel(), 'Due '.Format::shortDate($invoice->due_date)]));
                $add('Invoice', "Invoice {$invoice->invoice_number} issued", $detail, Format::amount($invoice->total_amount), $invoice->createdBy, $invoice->created_at);
                $add('Invoice', "Invoice {$invoice->invoice_number} voided", $invoice->void_reason, null, $invoice->voidedBy, $invoice->voided_at, true);
            }

            $memos = $customer->invoiceAdjustments()
                ->with(['invoice:id,invoice_number', 'createdBy:id,name', 'voidedBy:id,name'])
                ->orderBy('id')
                ->get();
            foreach ($memos as $memo) {
                $add($memo->type->label(), "{$memo->type->label()} {$memo->label()} on invoice {$memo->invoice?->invoice_number}", $memo->reason, Format::amount($memo->amount), $memo->createdBy, $memo->created_at);
                $add($memo->type->label(), "{$memo->type->label()} {$memo->label()} voided", $memo->void_reason, null, $memo->voidedBy, $memo->voided_at, true);
            }
        }

        if ($viewer->canAccessPage(Page::Payments)) {
            foreach ($customer->payments()->with(['receivedBy:id,name', 'voidedBy:id,name'])->orderBy('id')->get() as $payment) {
                $detail = implode(' · ', array_filter([$payment->method->label(), $payment->reference_number, 'Paid on '.Format::shortDate($payment->paid_on)]));
                $add('Payment', "Payment {$payment->receipt_number} received", $detail, Format::amount($payment->amount), $payment->receivedBy, $payment->created_at);
                $add('Payment', "Payment {$payment->receipt_number} voided", $payment->void_reason, null, $payment->voidedBy, $payment->voided_at, true);
            }
        }

        if ($viewer->canAccessPage(Page::MeterReadings)) {
            foreach ($customer->meterReadings()->with('createdBy:id,name')->orderBy('id')->get() as $reading) {
                $period = date('F Y', strtotime($reading->billing_period.'-01'));
                $detail = $reading->present_reading === null
                    ? $reading->reading_code?->label()
                    : 'Reading '.Format::quantity($reading->present_reading).' · '.Format::quantity($reading->consumption ?? 0).' cu.m. used';
                $add('Meter Reading', "Meter reading for {$period}", $detail, null, $reading->createdBy, $reading->created_at);
            }
        }

        if ($viewer->canAccessPage(Page::Disconnections)) {
            foreach ($customer->disconnectionNotices()->with(['issuedBy:id,name', 'closedBy:id,name'])->orderBy('id')->get() as $notice) {
                $bills = $notice->overdue_bills === 1 ? '1 overdue bill' : "{$notice->overdue_bills} overdue bills";
                $add('Disconnection', "Disconnection notice {$notice->notice_number} issued", "{$bills} · Deadline ".Format::shortDate($notice->deadline), Format::amount($notice->overdue_amount), $notice->issuedBy, $notice->created_at);
                $add('Disconnection', "Disconnection notice {$notice->notice_number} ".strtolower($notice->status->label()), $notice->close_reason, null, $notice->closedBy, $notice->closed_at, $notice->status === DisconnectionNoticeStatus::Cancelled);
            }
        }

        // Latest first; entries made in the same second keep the order they were added in, reversed.
        $order = array_keys($entries);
        usort($order, fn (int $a, int $b): int => [$entries[$b]['at']->getTimestamp(), $b] <=> [$entries[$a]['at']->getTimestamp(), $a]);

        return array_map(fn (int $key): array => $entries[$key], $order);
    }
}
