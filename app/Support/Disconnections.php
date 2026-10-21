<?php

namespace App\Support;

use App\Enums\CustomerStatus;
use App\Enums\DisconnectionNoticeStatus;
use App\Enums\InvoiceStatus;
use App\Enums\WorkOrderStatus;
use App\Enums\WorkOrderType;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DisconnectionNotice;
use App\Models\User;
use App\Models\WorkOrder;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Disconnection for non-payment. A Connected customer with at least the company's number of overdue
 * bills is eligible; staff issue a notice of disconnection, and once its deadline (in working days)
 * passes unpaid, create the Disconnection work order. Paying until fewer bills than that are overdue
 * settles the notice and cancels its work order. An exempt customer or one with an open Billing
 * Complaint is on hold.
 */
final class Disconnections
{
    /**
     * Constrains an invoices query to bills still unpaid after their due date.
     *
     * @return Closure(Builder<*>): mixed
     */
    public static function overdue(string $today): Closure
    {
        return fn (Builder $query) => $query->whereIn('status', InvoiceStatus::openValues())->whereDate('due_date', '<', $today);
    }

    public static function overdueCount(Customer $customer, ?string $today = null): int
    {
        $today ??= $customer->company->today();

        return $customer->invoices()->where(self::overdue($today))->count();
    }

    /**
     * Customers of the company with at least the trigger number of overdue bills, with
     * overdue_bills, overdue_amount and oldest_due_date loaded.
     *
     * @return Builder<Customer>
     */
    private static function overdueCustomers(Company $company, string $today): Builder
    {
        $overdue = self::overdue($today);

        return Customer::query()
            ->where('company_id', $company->id)
            ->where('status', CustomerStatus::Connected)
            ->whereHas('invoices', $overdue, '>=', max($company->disconnection_overdue_bills, 1))
            ->whereDoesntHave('disconnectionNotices', fn (Builder $query) => $query->whereIn('status', DisconnectionNoticeStatus::openValues()))
            ->withCount(['invoices as overdue_bills' => $overdue])
            ->withSum(['invoices as overdue_amount' => $overdue], 'balance')
            ->withMin(['invoices as oldest_due_date' => $overdue], 'due_date');
    }

    /**
     * Customers to issue a notice of disconnection to: overdue enough, not exempt, no open Billing
     * Complaint or Disconnection order, and no open notice.
     *
     * @return Builder<Customer>
     */
    public static function eligible(Company $company, string $today): Builder
    {
        return self::overdueCustomers($company, $today)
            ->where(fn (Builder $query) => $query
                ->whereNull('disconnection_exempt_reason')
                ->orWhereDate('disconnection_exempt_until', '<', $today))
            ->whereDoesntHave('workOrders', fn (Builder $query) => $query
                ->whereIn('status', WorkOrderStatus::openValues())
                ->whereIn('order_type', [WorkOrderType::BillingComplaint, WorkOrderType::Disconnection]));
    }

    /**
     * Overdue enough to be eligible, but exempt or with an open Billing Complaint.
     *
     * @return Builder<Customer>
     */
    public static function onHold(Company $company, string $today): Builder
    {
        return self::overdueCustomers($company, $today)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query
                    ->whereNotNull('disconnection_exempt_reason')
                    ->where(fn (Builder $query) => $query->whereNull('disconnection_exempt_until')->orWhereDate('disconnection_exempt_until', '>=', $today)))
                ->orWhereHas('workOrders', fn (Builder $query) => $query
                    ->whereIn('status', WorkOrderStatus::openValues())
                    ->where('order_type', WorkOrderType::BillingComplaint)));
    }

    /**
     * Why the customer may not be disconnected for non-payment right now, or null.
     */
    public static function holdReason(Customer $customer, string $today): ?string
    {
        if ($customer->isExemptFromDisconnection($today)) {
            return 'exempt: '.$customer->disconnection_exempt_reason;
        }

        $complaint = $customer->workOrders()
            ->whereIn('status', WorkOrderStatus::openValues())
            ->where('order_type', WorkOrderType::BillingComplaint)
            ->value('order_number');

        return $complaint === null ? null : "billing complaint {$complaint} is open";
    }

    /* ------------------------------ Working days ------------------------------ */

    /**
     * The last day to pay: the company's number of working days after $issuedOn.
     */
    public static function deadline(Company $company, string $issuedOn): string
    {
        $skip = $company->disconnectionSkipDays();
        $date = Carbon::parse($issuedOn);

        for ($left = max($company->disconnection_notice_days, 0); $left > 0;) {
            $date = $date->addDay();
            if (! in_array($date->dayOfWeekIso, $skip, true)) {
                $left--;
            }
        }

        return $date->toDateString();
    }

    /**
     * Working days after $today up to the deadline; 0 on the deadline itself and after it.
     */
    public static function workingDaysLeft(Company $company, string $today, string $deadline): int
    {
        $skip = $company->disconnectionSkipDays();
        $days = 0;

        for ($date = Carbon::parse($today)->addDay(); $date->toDateString() <= $deadline; $date = $date->addDay()) {
            if (! in_array($date->dayOfWeekIso, $skip, true)) {
                $days++;
            }
        }

        return $days;
    }

    /* --------------------------------- Notices -------------------------------- */

    /**
     * Issues a notice of disconnection to each customer that is still eligible, and emails it to
     * those with an email address when $email.
     *
     * @param  list<int>  $customerIds
     * @return array{issued: list<DisconnectionNotice>, skipped: int}
     */
    public static function issue(Company $company, array $customerIds, User $actor, bool $email): array
    {
        $today = $company->today();
        $deadline = self::deadline($company, $today);
        $issued = [];

        foreach (array_unique($customerIds) as $customerId) {
            $notice = DB::transaction(function () use ($company, $customerId, $actor, $today, $deadline): ?DisconnectionNotice {
                $customer = self::eligible($company, $today)->whereKey($customerId)->lockForUpdate()->first();
                if ($customer === null) {
                    return null;
                }

                return DisconnectionNotice::createNumbered($company, [
                    'customer_id' => $customer->id,
                    'status' => DisconnectionNoticeStatus::Pending,
                    'overdue_bills' => (int) $customer->overdue_bills,
                    'overdue_amount' => Money::fromCents(Money::toCents($customer->overdue_amount)),
                    'oldest_due_date' => $customer->oldest_due_date,
                    'issued_on' => $today,
                    'deadline' => $deadline,
                    'issued_by_id' => $actor->id,
                ]);
            });

            if ($notice !== null) {
                if ($email) {
                    self::email($notice);
                }
                $issued[] = $notice;
            }
        }

        return ['issued' => $issued, 'skipped' => count(array_unique($customerIds)) - count($issued)];
    }

    /**
     * Emails the notice to the customer. False when they have no email address.
     */
    public static function email(DisconnectionNotice $notice): bool
    {
        $customer = $notice->customer;
        if ($customer->email === null || $customer->email === '') {
            return false;
        }

        $company = $notice->company;
        $currency = $company->currency ? $company->currency.' ' : '';

        EmailDelivery::send(
            $customer->email,
            "Notice of Disconnection {$notice->notice_number} – {$company->company_name}",
            implode("\n", [
                "Dear {$customer->fullName()},",
                '',
                "Our records show {$notice->overdue_bills} unpaid bill(s) past their due date on account {$customer->account_number}, "
                    ."totaling {$currency}".Format::amount($notice->overdue_amount).'.',
                '',
                'Please pay on or before '.$notice->deadline->format('F j, Y').' to avoid disconnection of your water service. '
                    .'Once disconnected, the overdue bills and a reconnection fee must be paid before service is restored.',
                '',
                'If you have already paid, please disregard this notice. For questions, contact us'
                    .($company->phone_number ? " at {$company->phone_number}" : '').'.',
                '',
                $company->company_name,
            ]),
        );

        $notice->update(['emailed_at' => now()]);

        return true;
    }

    /**
     * Withdraws an open notice, cancelling its work order if one was created.
     */
    public static function cancel(DisconnectionNotice $notice, string $reason, User $actor): void
    {
        DB::transaction(function () use ($notice, $reason, $actor): void {
            $notice = DisconnectionNotice::query()->lockForUpdate()->findOrFail($notice->id);
            if (! $notice->status->isOpen()) {
                return;
            }

            self::close($notice, DisconnectionNoticeStatus::Cancelled, $reason, $actor);
        });
    }

    /**
     * Settles the customer's open notices once fewer bills than the trigger are overdue, and cancels
     * a notice still waiting for its deadline once the customer is no longer connected. Runs after
     * every change to the customer's bills or payments.
     */
    public static function review(Customer $customer, ?User $actor = null): void
    {
        $notices = DisconnectionNotice::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', DisconnectionNoticeStatus::openValues())
            ->get();

        if ($notices->isEmpty()) {
            return;
        }

        $customer = $customer->fresh();
        $company = $customer->company;
        $overdue = self::overdueCount($customer, $company->today());

        foreach ($notices as $notice) {
            if ($overdue < max($company->disconnection_overdue_bills, 1)) {
                self::close($notice, DisconnectionNoticeStatus::Settled, $overdue === 0
                    ? 'Paid: no overdue bills left.'
                    : "Paid: {$overdue} overdue bill(s) left, under the {$company->disconnection_overdue_bills} that trigger disconnection.", $actor);
            } elseif ($notice->status === DisconnectionNoticeStatus::Pending && $customer->status !== CustomerStatus::Connected) {
                self::close($notice, DisconnectionNoticeStatus::Cancelled, "Customer is {$customer->status->label()}.", $actor);
            }
        }
    }

    /**
     * Re-checks every open notice of the company, in case a change was missed.
     *
     * @return int Notices closed.
     */
    public static function reviewAll(Company $company): int
    {
        $before = $company->disconnectionNotices()->whereIn('status', DisconnectionNoticeStatus::openValues())->count();

        Customer::query()
            ->whereIn('id', $company->disconnectionNotices()->whereIn('status', DisconnectionNoticeStatus::openValues())->select('customer_id'))
            ->get()
            ->each(fn (Customer $customer) => self::review($customer));

        return $before - $company->disconnectionNotices()->whereIn('status', DisconnectionNoticeStatus::openValues())->count();
    }

    private static function close(DisconnectionNotice $notice, DisconnectionNoticeStatus $status, string $reason, ?User $actor): void
    {
        $notice->update(['status' => $status, 'closed_at' => now(), 'close_reason' => mb_substr($reason, 0, 255), 'closed_by_id' => $actor?->id]);

        $order = $notice->workOrder;
        if ($order !== null && $order->status->isOpen()) {
            $why = "Disconnection notice {$notice->notice_number} {$status->label()}: {$reason}";
            $order->update(['status' => WorkOrderStatus::Cancelled, 'hold_reason' => null, 'cancel_reason' => mb_substr($why, 0, 255)]);
            $order->log($actor, 'cancelled', "Cancelled: {$why}");
        }
    }

    /* ------------------------------- Work orders ------------------------------ */

    /**
     * Creates a Disconnection work order for each notice whose deadline has passed, after checking
     * again that the customer still owes and isn't on hold.
     *
     * @param  list<int>  $noticeIds
     * @return array{created: list<WorkOrder>, skipped: list<string>}
     */
    public static function createWorkOrders(Company $company, array $noticeIds, ?int $assigneeId, User $actor): array
    {
        $today = $company->today();
        $created = [];
        $skipped = [];

        $notices = $company->disconnectionNotices()
            ->whereIn('id', array_unique($noticeIds))
            ->with('customer')
            ->orderBy('notice_number')
            ->get();

        foreach ($notices as $notice) {
            $result = DB::transaction(function () use ($notice, $assigneeId, $actor, $today): WorkOrder|string {
                $notice = DisconnectionNotice::query()->lockForUpdate()->findOrFail($notice->id);
                $customer = $notice->customer;
                $label = "{$customer->account_number} {$customer->fullName()}";

                if (! $notice->isDue($today)) {
                    return "{$label}: notice {$notice->notice_number} is ".($notice->status === DisconnectionNoticeStatus::Pending ? 'not past its deadline' : strtolower($notice->status->label())).'.';
                }

                self::review($customer, $actor);
                $notice->refresh();
                if ($notice->status !== DisconnectionNoticeStatus::Pending) {
                    return "{$label}: {$notice->close_reason}";
                }
                if ($hold = self::holdReason($customer, $today)) {
                    return "{$label}: on hold, {$hold}.";
                }
                if ($reason = WorkOrderRules::whyNot(WorkOrderType::Disconnection, $customer)) {
                    return "{$label}: {$reason}.";
                }

                $order = WorkOrder::createNumbered($customer->company, [
                    'customer_id' => $customer->id,
                    'order_type' => WorkOrderType::Disconnection,
                    'meter_type_id' => $customer->meter_type_id,
                    'meter_number' => $customer->meter_number,
                    'serial_number' => $customer->meter_serial_number,
                    'description' => "Disconnection for non-payment. Notice {$notice->notice_number}: {$notice->overdue_bills} overdue bill(s), "
                        .Format::amount($notice->overdue_amount).', deadline '.$notice->deadline->format('m/d/Y').'. Check the balance before disconnecting.',
                    'assigned_to_id' => $assigneeId,
                    'status' => WorkOrderStatus::InProgress,
                    'requested_by_id' => $actor->id,
                    'requested_at' => now(),
                ]);
                $order->log($actor, 'submitted', "Created from disconnection notice {$notice->notice_number} and assigned to ".($order->assignedTo?->name ?? 'nobody').'.');
                $notice->update(['status' => DisconnectionNoticeStatus::Scheduled, 'work_order_id' => $order->id]);

                return $order;
            });

            if ($result instanceof WorkOrder) {
                $created[] = $result;
            } else {
                $skipped[] = $result;
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Why a Disconnection work order created from a notice can't be completed, or null. Other
     * Disconnection orders (e.g. requested by the customer) are not checked.
     */
    public static function whyNotComplete(WorkOrder $order, Customer $customer): ?string
    {
        $notice = DisconnectionNotice::query()->where('work_order_id', $order->id)->latest('id')->first();
        if ($notice === null) {
            return null;
        }
        if ($notice->status !== DisconnectionNoticeStatus::Scheduled) {
            return "disconnection notice {$notice->notice_number} is {$notice->status->label()}";
        }

        $today = $customer->company->today();
        $overdue = self::overdueCount($customer, $today);
        if ($overdue < max($customer->company->disconnection_overdue_bills, 1)) {
            return "the customer has paid; only {$overdue} overdue bill(s) left";
        }

        return ($hold = self::holdReason($customer, $today)) === null ? null : "the customer is on hold, {$hold}";
    }

    /**
     * Closes the notice of a Disconnection work order that was just completed.
     */
    public static function workOrderCompleted(WorkOrder $order, ?User $actor): void
    {
        DisconnectionNotice::query()
            ->where('work_order_id', $order->id)
            ->where('status', DisconnectionNoticeStatus::Scheduled)
            ->update([
                'status' => DisconnectionNoticeStatus::Disconnected,
                'closed_at' => now(),
                'close_reason' => "Disconnected by work order {$order->order_number}.",
                'closed_by_id' => $actor?->id,
            ]);
    }

    /**
     * Cancels the notice of a Disconnection work order that staff cancelled.
     */
    public static function workOrderCancelled(WorkOrder $order, string $reason, ?User $actor): void
    {
        DisconnectionNotice::query()
            ->where('work_order_id', $order->id)
            ->where('status', DisconnectionNoticeStatus::Scheduled)
            ->update([
                'status' => DisconnectionNoticeStatus::Cancelled,
                'closed_at' => now(),
                'close_reason' => mb_substr("Work order {$order->order_number} cancelled: {$reason}", 0, 255),
                'closed_by_id' => $actor?->id,
            ]);
    }

    /* -------------------------------- Exemptions ------------------------------- */

    public static function exempt(Customer $customer, string $reason, ?string $until, User $actor): void
    {
        $customer->update([
            'disconnection_exempt_reason' => $reason,
            'disconnection_exempt_until' => $until,
            'disconnection_exempted_by_id' => $actor->id,
        ]);
    }

    public static function removeExemption(Customer $customer): void
    {
        $customer->update([
            'disconnection_exempt_reason' => null,
            'disconnection_exempt_until' => null,
            'disconnection_exempted_by_id' => null,
        ]);
    }

    /**
     * Customers of the company exempt from disconnection today.
     *
     * @return Collection<int, Customer>
     */
    public static function exemptCustomers(Company $company, string $today): Collection
    {
        return $company->customers()
            ->whereNotNull('disconnection_exempt_reason')
            ->where(fn (Builder $query) => $query->whereNull('disconnection_exempt_until')->orWhereDate('disconnection_exempt_until', '>=', $today))
            ->with('disconnectionExemptedBy:id,name')
            ->orderBy('account_number')
            ->get();
    }
}
