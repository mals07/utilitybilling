@use('App\Models\InvoiceNumberBlock')
@use('App\Support\Format')
@use('App\Support\Reader\NumberBlocks')

<x-page title="Reader Devices">
    <div class="max-w-[1080px]">
        <x-flash class="mb-4" />

        <a href="{{ route('users') }}" class="link-button">← Back to Users</a>

        <p class="mt-3 max-w-2xl text-body text-label">
            Phones signed in to the meter reader app. A lost or replaced phone should be signed out here: it can no longer read or print, but it can still upload the readings it already took, and each one is held for review.
        </p>
        <p class="mt-2 max-w-2xl text-body text-label">
            Open numbers are invoice numbers reserved for a phone that aren't on an invoice yet: unused, or on bills it printed but hasn't synced. A lost phone's open numbers are the bills to look for.
        </p>

        @if (file_exists(public_path('downloads/meter-reader.apk')))
            <div class="mt-4 flex max-w-2xl flex-wrap items-center gap-3 rounded-md border border-table-line px-4 py-3">
                <p class="min-w-0 flex-1 text-body text-ink-soft">
                    To set up a reader's Android phone, open this page on the phone, download the app and install it. The phone may ask to allow installing apps from the browser.
                </p>
                <a href="{{ asset('downloads/meter-reader.apk') }}" download class="form-button form-button-sm">Download the app</a>
            </div>
        @endif

        <div class="mt-4 overflow-hidden rounded-md border border-table-line">
            <div class="table-scroller">
                <table class="w-full text-body">
                    <thead>
                        <tr class="border-b border-table-line text-left text-caption text-label">
                            <th scope="col" class="px-4 py-3 font-medium">Device</th>
                            <th scope="col" class="px-4 py-3 font-medium">Reader</th>
                            <th scope="col" class="px-4 py-3 font-medium">Last seen</th>
                            <th scope="col" class="px-4 py-3 font-medium">Last synced</th>
                            <th scope="col" class="px-4 py-3 font-medium">Open numbers</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Held</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($devices as $device)
                            <tr wire:key="device-{{ $device->id }}" class="border-b border-table-line last:border-b-0 hover:bg-row-hover">
                                <td class="px-4 py-3">
                                    <span class="block font-medium text-ink-strong">{{ $device->name }}</span>
                                    <span class="block text-caption text-label">Registered {{ Format::date($device->created_at, $timezone) }}{{ $device->app_version ? ' · App '.$device->app_version : '' }}</span>
                                </td>
                                <td class="px-4 py-3 text-ink-soft">{{ $device->user->name }}</td>
                                <td class="px-4 py-3 text-ink-soft">{{ $device->last_seen_at ? Format::dateTime($device->last_seen_at, $timezone) : '—' }}</td>
                                <td class="px-4 py-3 text-ink-soft">{{ $device->last_synced_at ? Format::dateTime($device->last_synced_at, $timezone) : 'Never' }}</td>
                                <td class="px-4 py-3 text-ink-soft">
                                    @forelse ($device->numberBlocks as $block)
                                        <span wire:key="block-{{ $block->id }}" class="block">
                                            <span class="tabular-nums text-ink-strong">{{ InvoiceNumberBlock::format($block->first_number) }}–{{ InvoiceNumberBlock::format($block->last_number) }}</span>
                                            <span class="block text-caption text-label">{{ Format::integer(NumberBlocks::unaccountedCount($block)) }} of {{ Format::integer($block->last_number - $block->first_number + 1) }} not on an invoice</span>
                                        </span>
                                    @empty
                                        <span class="text-placeholder">None</span>
                                    @endforelse
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    @if ($device->held_count > 0)
                                        <a href="{{ route('meter-readings.discrepancies') }}" class="font-semibold text-warning underline">{{ Format::integer($device->held_count) }}</a>
                                    @else
                                        <span class="text-placeholder">0</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @if ($device->isRevoked())
                                        <span class="rounded-full bg-panel px-2 py-0.5 text-caption text-label">Signed out</span>
                                    @elseif ($this->access['edit'])
                                        <button type="button" class="link-button text-danger" wire:click="revoke({{ $device->id }})" wire:confirm="Sign out {{ $device->name }}? It can still upload readings it already took, held for review. The reader must sign in online again to read meters on it.">Sign out</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7"class="px-3 py-10 text-center text-body text-label">No phones yet. A reader's phone appears here after they sign in to the meter reader app.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-page>
