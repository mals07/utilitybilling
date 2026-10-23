@use('App\Support\CustomerImport\CustomerImporter')
@use('App\Support\CustomerImport\CustomerSpreadsheet')
@use('App\Support\Format')

<div class="contents">
    <button type="button" class="link-button text-[11px]" wire:click="openImport">Import Customers</button>

    @if ($open)
        <x-modal title="Import Customers" close="$wire.closeImport()" width="lg">
            <div class="mt-3 grid gap-4 text-[13px] text-ink">
                @if ($imported)
                    {{-- Result --}}
                    <x-alert tone="success">
                        {{ $imported->imported_count === 1 ? '1 customer' : number_format($imported->imported_count).' customers' }} added with account {{ $imported->imported_count === 1 ? 'number' : 'numbers' }} {{ $imported->accountNumberRange() }}.
                        @if ($imported->skipped_count > 0)
                            {{ $imported->skipped_count === 1 ? '1 row was' : number_format($imported->skipped_count).' rows were' }} skipped.
                        @endif
                    </x-alert>
                    <div class="flex flex-wrap items-center justify-end gap-x-5 gap-y-2">
                        @if ($imported->skipped_count > 0)
                            <button type="button" class="link-button text-[13px]" wire:click="downloadSkipped({{ $imported->id }})">Download skipped rows</button>
                        @endif
                        <button type="button" class="text-button" wire:click="resetImport">Import another file</button>
                        <button type="button" class="form-button min-w-[120px]" wire:click="closeImport">Done</button>
                    </div>
                @elseif ($preview)
                    {{-- Preview --}}
                    <p class="truncate text-label" title="{{ $preview['fileName'] }}">File: <span class="font-semibold text-ink">{{ $preview['fileName'] }}</span></p>

                    <div class="flex flex-wrap gap-3">
                        <div class="min-w-36 rounded-xs bg-panel px-4 py-3">
                            <p class="text-xl font-bold text-success">{{ number_format($preview['readyCount']) }}</p>
                            <p class="text-xs text-label">Ready to import</p>
                        </div>
                        <div class="min-w-36 rounded-xs bg-panel px-4 py-3">
                            <p @class(['text-xl font-bold', 'text-danger' => $preview['skippedCount'] > 0, 'text-muted' => $preview['skippedCount'] === 0])>{{ number_format($preview['skippedCount']) }}</p>
                            <p class="text-xs text-label">With problems</p>
                        </div>
                    </div>

                    @if ($preview['skippedCount'] > 0)
                        <div>
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <p class="font-semibold text-ink-strong">
                                    {{ $preview['readyCount'] > 0 ? 'These rows will be skipped' : 'None of the rows can be imported' }}
                                </p>
                                <button type="button" class="link-button" wire:click="downloadProblems">Download this list</button>
                            </div>
                            <div class="table-scroller mt-2 max-h-72 overflow-y-auto">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th scope="col" class="w-14">Row</th>
                                            <th scope="col" class="w-40">Name</th>
                                            <th scope="col">Problem</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($preview['skipped'] as $row)
                                            <tr wire:key="skipped-{{ $row['row'] }}">
                                                <td>{{ $row['row'] }}</td>
                                                <td>{{ $row['name'] !== '' ? $row['name'] : '—' }}</td>
                                                <td class="text-danger">{{ $row['problem'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            @if ($preview['skippedCount'] > count($preview['skipped']))
                                <p class="mt-1 text-xs text-muted">Showing the first {{ count($preview['skipped']) }}. Download the list to see all {{ number_format($preview['skippedCount']) }}.</p>
                            @endif
                        </div>
                    @endif

                    @error('file')
                        <x-alert tone="error">{{ $message }}</x-alert>
                    @enderror

                    <div class="flex flex-wrap items-center justify-end gap-x-5 gap-y-2">
                        <button type="button" class="text-button" wire:click="resetImport">{{ $preview['readyCount'] > 0 ? 'Cancel and fix the file' : 'Choose another file' }}</button>
                        @if ($preview['readyCount'] > 0)
                            <button type="button" class="form-button min-w-[120px]" wire:click="import" wire:loading.attr="disabled" wire:target="import">
                                <span wire:loading.remove wire:target="import">
                                    @if ($preview['skippedCount'] > 0)
                                        Import {{ number_format($preview['readyCount']) }} valid {{ $preview['readyCount'] === 1 ? 'row' : 'rows' }}, skip {{ number_format($preview['skippedCount']) }}
                                    @else
                                        Import {{ $preview['readyCount'] === 1 ? '1 customer' : number_format($preview['readyCount']).' customers' }}
                                    @endif
                                </span>
                                <span wire:loading wire:target="import">Importing…</span>
                            </button>
                        @endif
                    </div>
                @else
                    {{-- Choose a file --}}
                    <ol class="grid gap-1 pl-5 text-label [list-style:decimal]">
                        <li>
                            Download the template:
                            <button type="button" class="link-button text-[13px]" wire:click="downloadTemplate('xlsx')">Excel template</button>
                            or
                            <button type="button" class="link-button text-[13px]" wire:click="downloadTemplate('csv')">CSV template</button>
                        </li>
                        <li>Fill in one customer per row. The Excel template has dropdowns and instructions.</li>
                        <li>Upload the file. You'll see which rows are ready before anything is added.</li>
                    </ol>

                    <label
                        for="customer-import-file"
                        @class([
                            'flex cursor-pointer flex-col items-center gap-1.5 rounded-xs border border-dashed bg-panel px-4 py-8 text-center hover:bg-[#e6f1f9]',
                            'border-danger' => $errors->has('file'),
                            'border-line' => ! $errors->has('file'),
                        ])
                    >
                        <x-icon name="upload" :size="22" />
                        <span wire:loading.remove wire:target="file" class="font-semibold text-ink-strong">Choose an Excel or CSV file</span>
                        <span wire:loading wire:target="file" class="text-muted">Checking the file…</span>
                        <span class="text-xs text-muted">.xlsx or .csv, up to {{ CustomerSpreadsheet::MAX_KILOBYTES / 1024 }}MB and {{ number_format(CustomerImporter::MAX_ROWS) }} customers</span>
                    </label>
                    <input
                        id="customer-import-file"
                        type="file"
                        accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv"
                        wire:model="file"
                        class="sr-only"
                        @error('file') aria-invalid="true" aria-describedby="customer-import-file-error" @enderror
                    >
                    @error('file')
                        <p id="customer-import-file-error" class="form-error" role="alert">{{ $message }}</p>
                    @enderror
                @endif

                {{-- History --}}
                <section class="border-t border-line pt-3">
                    <h3 class="font-semibold text-ink-strong">Import history</h3>
                    @if ($this->history->isEmpty())
                        <p class="mt-1 text-xs text-muted">No imports yet.</p>
                    @else
                        <div class="table-scroller mt-2">
                            <table class="data-table text-xs">
                                <thead>
                                    <tr>
                                        <th scope="col">Date</th>
                                        <th scope="col">By</th>
                                        <th scope="col">File</th>
                                        <th scope="col" class="cell-center">Added</th>
                                        <th scope="col" class="cell-center">Skipped</th>
                                        <th scope="col">Account Numbers</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($this->history as $item)
                                        <tr wire:key="import-{{ $item->id }}">
                                            <td class="whitespace-nowrap">{{ Format::dateTime($item->created_at, $timezone) }}</td>
                                            <td>{{ $item->user?->name ?? '—' }}</td>
                                            <td class="max-w-44 truncate" title="{{ $item->file_name }}">{{ $item->file_name }}</td>
                                            <td class="cell-center">{{ number_format($item->imported_count) }}</td>
                                            <td class="cell-center">
                                                @if ($item->skipped_count > 0)
                                                    <button type="button" class="link-button" wire:click="downloadSkipped({{ $item->id }})" aria-label="Download the {{ $item->skipped_count }} skipped rows of {{ $item->file_name }}">{{ number_format($item->skipped_count) }}</button>
                                                @else
                                                    0
                                                @endif
                                            </td>
                                            <td class="whitespace-nowrap">{{ $item->accountNumberRange() ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            </div>
        </x-modal>
    @endif
</div>
