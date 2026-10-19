@use('App\Enums\WorkOrderStatus')
@use('App\Models\WorkOrder')
@use('App\Support\Billing\Adjustments')
@use('App\Support\Format')
@use('App\Support\WorkOrderCompletion')

@php
    $details = [
        'Order Type' => $viewing->order_type->label(),
        'Status' => $viewing->status->label(),
        'Account No' => $viewing->customer->account_number,
        'Customer Name' => trim($viewing->customer->first_name.' '.$viewing->customer->last_name),
        'Meter Type' => $viewing->meterType?->name,
        'Meter No' => $viewing->meter_number,
        'Serial No' => $viewing->serial_number,
        'Assigned To' => $viewing->assignedTo?->name,
        'Requested On' => Format::date($viewing->requested_at, $timezone),
        'Requested By' => $viewing->requestedBy?->name,
        'Completed On' => $viewing->completed_at ? Format::date($viewing->completed_at, $timezone) : null,
        'Completed By' => $viewing->completedBy?->name,
    ];
    $overdue = $viewing->isOverdue($timezone);
    $completed = $viewing->status === WorkOrderStatus::Completed;
    $answers = $completed ? WorkOrderCompletion::describe($viewing) : [];
    $heading = 'mt-5 border-t border-table-line pt-3 text-[13px] font-semibold text-ink-strong';
@endphp

<x-modal :title="'Work Order '.$viewing->order_number" close="$wire.closeModal()" width="form">
    <div class="mt-3 grid gap-2">
        @if ($overdue)
            <x-alert tone="error" class="flex items-center gap-2">
                <x-icon name="warning" :size="16" />
                Open for more than {{ WorkOrder::OVERDUE_AFTER_DAYS }} days.
            </x-alert>
        @endif
        @if ($viewing->review_flag && $viewing->flag_resolved_at)
            <x-alert tone="success">{{ $viewing->review_flag->label() }} — resolved by {{ $viewing->flag_resolution }}.</x-alert>
        @elseif ($viewing->review_flag)
            <x-alert tone="error">
                Flagged for the office: {{ $viewing->review_flag->label() }}.
                @if (in_array($viewing->review_flag, Adjustments::RESOLVABLE_FLAGS, true))
                    Resolve it with a credit memo on one of the customer's invoices (Invoices → open the invoice → Adjust).
                @endif
            </x-alert>
        @endif
        @if ($viewing->status === WorkOrderStatus::OnHold && $viewing->hold_reason)
            <x-alert tone="info">On hold: {{ $viewing->hold_reason }}</x-alert>
        @endif
        @if ($viewing->status === WorkOrderStatus::Cancelled && $viewing->cancel_reason)
            <x-alert tone="info">Cancelled: {{ $viewing->cancel_reason }}</x-alert>
        @endif
        @if ($viewing->parent)
            <p class="text-[13px] text-label">
                Follow-up of
                <button type="button" class="link-button" wire:click="openView({{ $viewing->parent->id }})">work order {{ $viewing->parent->order_number }}</button>
                ({{ $viewing->parent->order_type->label() }}).
            </p>
        @endif
    </div>

    <dl class="mt-3 grid grid-cols-1 gap-x-5 gap-y-3 text-[13px] min-[560px]:grid-cols-2">
        @foreach ($details as $label => $value)
            <div wire:key="detail-{{ $loop->index }}">
                <dt class="text-xs font-medium text-label">{{ $label }}</dt>
                <dd @class(['mt-0.5', 'text-placeholder' => blank($value), 'font-semibold text-danger' => $label === 'Requested On' && $overdue])>{{ blank($value) ? '—' : $value }}</dd>
            </div>
        @endforeach
        <div class="min-[560px]:col-span-2">
            <dt class="text-xs font-medium text-label">Description</dt>
            <dd @class(['mt-0.5 whitespace-pre-line', 'text-placeholder' => blank($viewing->description)])>{{ blank($viewing->description) ? '—' : $viewing->description }}</dd>
        </div>
    </dl>

    @if ($completed)
        <h3 class="{{ $heading }}">Completion</h3>
        <dl class="mt-2 grid grid-cols-1 gap-x-5 gap-y-3 text-[13px] min-[560px]:grid-cols-2">
            @foreach ($answers as $label => $value)
                <div wire:key="answer-{{ $loop->index }}" @class(['min-[560px]:col-span-2' => mb_strlen($value) > 40])>
                    <dt class="text-xs font-medium text-label">{{ $label }}</dt>
                    <dd class="mt-0.5 whitespace-pre-line">{{ $value }}</dd>
                </div>
            @endforeach
            <div>
                <dt class="text-xs font-medium text-label">Fee</dt>
                <dd class="mt-0.5">
                    @if ($viewing->fee_amount !== null)
                        {{ Format::amount($viewing->fee_amount) }}
                        @if ($viewing->fee_invoice_id)
                            <span class="text-label">· billed on</span>
                            <a href="{{ route('invoices', ['invoice' => $viewing->fee_invoice_id]) }}" class="link-button">invoice {{ $viewing->feeInvoice?->invoice_number }}</a>
                        @else
                            <span class="text-label">· not billed yet — goes on the next monthly bill</span>
                        @endif
                    @else
                        <span class="text-placeholder">—</span>
                    @endif
                </dd>
            </div>
            @if ($viewing->completion_data['remarks'] ?? null)
                <div class="min-[560px]:col-span-2">
                    <dt class="text-xs font-medium text-label">Remarks</dt>
                    <dd class="mt-0.5 whitespace-pre-line">{{ $viewing->completion_data['remarks'] }}</dd>
                </div>
            @endif
        </dl>

        @if ($viewing->completion_data['checklist'] ?? [])
            <ul class="mt-3 grid gap-1 text-[13px] min-[560px]:grid-cols-2">
                @foreach ($viewing->completion_data['checklist'] as $item)
                    <li wire:key="checked-{{ $loop->index }}" class="flex items-center gap-1.5">
                        <x-icon name="check" :size="14" class="text-success" />
                        {{ $item }}
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($viewing->readings->isNotEmpty())
            <h3 class="{{ $heading }}">Readings</h3>
            <div class="table-scroller mt-2">
                <table class="data-table table-compact">
                    <thead>
                        <tr>
                            <th scope="col">Kind</th>
                            <th scope="col">Meter No</th>
                            <th scope="col" class="cell-right">Reading</th>
                            <th scope="col">Read On</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($viewing->readings as $reading)
                            <tr wire:key="reading-{{ $reading->id }}">
                                <td>{{ $reading->kind->label() }}</td>
                                <td>{{ $reading->meter_number ?? '—' }}</td>
                                <td class="cell-right">{{ $reading->reading }}</td>
                                <td>{{ Format::isoDate($reading->read_on->toDateString()) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($viewing->photos->isNotEmpty())
            <h3 class="{{ $heading }}">Photos</h3>
            <div class="mt-2 flex flex-wrap gap-3">
                @foreach ($viewing->photos as $photo)
                    <a wire:key="photo-{{ $photo->id }}" href="{{ $photo->url() }}" target="_blank" rel="noopener" class="block">
                        <img src="{{ $photo->url() }}" alt="{{ $photo->file_name }}" loading="lazy" class="size-24 rounded-xs border border-line object-cover hover:opacity-90">
                    </a>
                @endforeach
            </div>
        @endif
    @endif

    @if ($viewing->followUps->isNotEmpty())
        <h3 class="{{ $heading }}">Follow-up orders</h3>
        <ul class="mt-2 grid gap-1 text-[13px]">
            @foreach ($viewing->followUps as $followUp)
                <li wire:key="follow-up-{{ $followUp->id }}">
                    <button type="button" class="link-button" wire:click="openView({{ $followUp->id }})">{{ $followUp->order_number }}</button>
                    {{ $followUp->order_type->label() }} · <span class="{{ $followUp->status->colorClass() }}">{{ $followUp->status->label() }}</span>
                </li>
            @endforeach
        </ul>
    @endif

    <h3 class="{{ $heading }}">Timeline</h3>
    @if ($viewing->activities->isEmpty())
        <p class="mt-2 text-[13px] text-placeholder">No activity recorded.</p>
    @else
        <ol class="mt-2 grid gap-2 border-l-2 border-table-line pl-3 text-[13px]">
            @foreach ($viewing->activities->sortByDesc('id') as $activity)
                <li wire:key="activity-{{ $activity->id }}">
                    <p>{{ $activity->description }}</p>
                    <p class="text-xs text-label">
                        {{ $activity->user?->name ?? 'System' }} · {{ Format::date($activity->created_at, $timezone) }}
                        {{ $activity->created_at->copy()->setTimezone($timezone ?? Format::DEFAULT_TIMEZONE)->format('g:i A') }}
                    </p>
                </li>
            @endforeach
        </ol>
    @endif

    <div class="form-actions mt-5">
        @if ($access['edit'] && $viewing->status->isOpen())
            <button type="button" class="text-button" wire:click="openEdit({{ $viewing->id }})">Update</button>
            <button type="button" class="text-button" wire:click="openComplete({{ $viewing->id }})">Complete…</button>
        @endif
        <button type="button" class="form-button min-w-[100px]" wire:click="closeModal">Close</button>
    </div>
</x-modal>
