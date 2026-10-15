@use('App\Models\WorkOrder')
@use('App\Support\Format')

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
@endphp

<x-modal :title="'Work Order '.$viewing->order_number" close="$wire.closeModal()" width="form">
    @if ($overdue)
        <x-alert tone="error" class="mt-3 flex items-center gap-2">
            <x-icon name="warning" :size="16" />
            In progress for more than {{ WorkOrder::OVERDUE_AFTER_DAYS }} days.
        </x-alert>
    @endif

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

    <div class="form-actions mt-5">
        @if ($access['edit'])
            <button type="button" class="text-button" wire:click="openEdit({{ $viewing->id }})">Update</button>
        @endif
        <button type="button" class="form-button min-w-[100px]" wire:click="closeModal">Close</button>
    </div>
</x-modal>
