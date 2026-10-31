@use('App\Support\Format')

{{--
    The customer's history, latest first, in a drawer on the right. Escape and a click on the
    backdrop close it, like a modal.
--}}
<div
    class="modal-backdrop"
    x-data
    x-on:click.self="$wire.closeHistory()"
    x-on:keydown.escape.window="
        if ($event.defaultPrevented || [...document.querySelectorAll('.modal-backdrop')].at(-1) !== $el) return;
        $event.preventDefault();
        $wire.closeHistory();
    "
>
    <aside
        role="dialog"
        aria-modal="true"
        aria-labelledby="customer-history-title"
        class="drawer-panel"
        x-trap.noscroll="true"
    >
        <div class="flex items-start justify-between gap-3 border-b border-table-line px-5 pt-6 pb-4">
            <div class="min-w-0">
                <h2 id="customer-history-title" class="text-heading font-bold text-ink-strong">Customer History</h2>
                <p class="mt-0.5 truncate text-body text-ink-soft">
                    {{ $historyCustomer->fullName() }} · <span class="tabular-nums">Acct No: {{ $historyCustomer->account_number }}</span>
                </p>
            </div>
            <button type="button" class="modal-close" x-on:click="$wire.closeHistory()" aria-label="Close">
                <x-icon name="close" />
            </button>
        </div>

        <div class="flex-1 overflow-y-auto px-5 py-4">
            @if ($hiddenHistorySections !== [])
                <p class="mb-4 rounded bg-panel px-3 py-2 text-caption text-ink-soft">
                    Not shown, as you don't have access to them: {{ strtolower(implode(', ', $hiddenHistorySections)) }}.
                </p>
            @endif

            @if ($history === [])
                <p class="text-body text-placeholder">No history yet.</p>
            @else
                <ol class="grid gap-3 border-l-2 border-table-line pl-4">
                    @foreach ($history as $entry)
                        <li wire:key="history-{{ $loop->index }}" class="relative">
                            <span @class([
                                'absolute top-1.5 -left-[21px] size-2 rounded-full ring-2 ring-card',
                                'bg-danger' => $entry['isVoid'],
                                'bg-accent' => ! $entry['isVoid'],
                            ]) aria-hidden="true"></span>
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <span class="inline-block rounded bg-panel px-1.5 py-0.5 text-caption text-ink-soft">{{ $entry['kind'] }}</span>
                                    <p @class(['mt-1 font-medium', 'text-danger' => $entry['isVoid'], 'text-ink-strong' => ! $entry['isVoid']])>{{ $entry['title'] }}</p>
                                    @if ($entry['detail'])
                                        <p class="mt-0.5 text-body text-ink-soft">{{ $entry['detail'] }}</p>
                                    @endif
                                </div>
                                @if ($entry['amount'])
                                    <span class="shrink-0 font-semibold whitespace-nowrap text-ink-strong tabular-nums">{{ $entry['amount'] }}</span>
                                @endif
                            </div>
                            <p class="mt-0.5 text-caption text-label">
                                {{ $entry['by'] ?? 'System' }} · {{ Format::dateTime($entry['at'], $timezone) }}
                            </p>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    </aside>
</div>
