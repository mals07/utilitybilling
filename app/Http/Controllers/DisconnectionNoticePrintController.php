<?php

namespace App\Http\Controllers;

use App\Models\DisconnectionNotice;
use App\Support\Disconnections;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Printable notices of disconnection, one per page, for the ids in ?notices=1,2,3. Sorted by
 * cluster and account number so they can be delivered area by area.
 */
class DisconnectionNoticePrintController extends Controller
{
    public function __invoke(Request $request): View
    {
        $company = $request->user()->company;
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $request->query('notices', ''))), fn (int $id): bool => $id > 0));

        $notices = DisconnectionNotice::query()
            ->where('disconnection_notices.company_id', $company->id)
            ->whereIn('disconnection_notices.id', $ids)
            ->join('customers', 'customers.id', '=', 'disconnection_notices.customer_id')
            ->orderBy('customers.cluster_type_id')
            ->orderBy('customers.account_number')
            ->select('disconnection_notices.*')
            ->with(['customer.clusterType'])
            ->get();

        abort_if($notices->isEmpty(), 404);

        DisconnectionNotice::query()->whereIn('id', $notices->pluck('id'))->update(['printed_at' => now()]);

        $today = $company->today();

        return view('print.disconnection-notices', [
            'company' => $company,
            'notices' => $notices,
            'overdueBills' => $notices->mapWithKeys(fn (DisconnectionNotice $notice): array => [
                $notice->id => $notice->customer->invoices()->where(Disconnections::overdue($today))->orderBy('due_date')->orderBy('id')->get(),
            ]),
        ]);
    }
}
