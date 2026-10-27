@use('App\Support\Format')

<x-page title="Reader Assignments">
    <div class="max-w-[960px]">
        <x-flash class="mb-4" />

        <a href="{{ route('meter-readings') }}" class="link-button">← Back to Meter Readings</a>

        <div class="mt-3 flex flex-wrap items-end justify-between gap-3">
            <p class="max-w-xl text-body text-label">
                Choose who reads each cluster with the meter reader app. A reader's phone downloads the meters of their clusters, and readings of other clusters are held for review.
            </p>
            <div>
                <label for="ra-period" class="mb-1 block text-caption font-semibold text-ink-strong">Billing month<x-required-mark /></label>
                <input id="ra-period" aria-required="true" type="month" max="{{ substr($today, 0, 7) }}" wire:model.live="period" class="form-control form-control-sm w-40 !text-body">
            </div>
        </div>

        <div class="mt-4 overflow-hidden rounded-md border border-table-line">
            <div class="table-scroller">
                <table class="w-full text-body">
                    <thead>
                        <tr class="border-b border-table-line text-left text-caption text-label">
                            <th scope="col" class="px-4 py-3 font-medium">Cluster</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Meters to read</th>
                            <th scope="col" class="w-72 px-4 py-3 font-medium">Reader</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($clusters as $cluster)
                            <tr wire:key="cluster-{{ $cluster->id }}-{{ $period }}" class="border-b border-table-line last:border-b-0 hover:bg-row-hover">
                                <td class="px-4 py-3 font-medium text-ink-strong">{{ $cluster->name }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-ink-soft">{{ Format::integer((int) ($meterCounts[$cluster->id] ?? 0)) }}</td>
                                <td class="px-4 py-2">
                                    <label for="ra-reader-{{ $cluster->id }}" class="sr-only">Reader for {{ $cluster->name }}</label>
                                    <select
                                        id="ra-reader-{{ $cluster->id }}"
                                        class="form-control form-control-sm w-full !text-body"
                                        wire:change="assign({{ $cluster->id }}, $event.target.value)"
                                        @disabled($periodLabel === null || ! $this->access['edit'])
                                    >
                                        <option value="">Not assigned</option>
                                        @foreach ($readers as $reader)
                                            <option value="{{ $reader->id }}" wire:key="reader-{{ $cluster->id }}-{{ $reader->id }}" @selected(($assignments[$cluster->id] ?? null) === $reader->id)>{{ $reader->name }}</option>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="px-3 py-10 text-center text-body text-label">No clusters yet. Add them in Company Setup → Operation Setup.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($readers->isEmpty())
            <p class="mt-3 text-caption text-label">No one can be assigned yet: readers need a role with Add access on Meter Readings.</p>
        @endif
    </div>
</x-page>
