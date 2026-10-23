{{-- The step's how-to and worked example, with the button that applies the example. --}}
<div class="rounded-xl border border-table-line bg-card p-4 shadow-card">
    <h2 class="text-sm font-bold text-ink-strong">How to do this step</h2>
    <ol class="mt-2 list-decimal space-y-1.5 pl-5 text-sm leading-6 text-ink-soft">
        @foreach ($current['howTo'] as $line)
            <li>{{ $line }}</li>
        @endforeach
    </ol>

    <h2 class="mt-5 text-sm font-bold text-ink-strong">Example</h2>
    <dl class="mt-2 divide-y divide-table-line border-y border-table-line text-sm">
        @foreach ($current['example'] as [$label, $value])
            <div class="flex flex-col gap-0.5 py-2 sm:flex-row sm:gap-3 xl:flex-col xl:gap-0.5">
                <dt class="shrink-0 text-label sm:w-36 xl:w-auto">{{ $label }}</dt>
                <dd class="font-medium text-ink">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    <button type="button" class="form-button form-button-sm mt-4 w-full" wire:click="useExample" wire:loading.attr="disabled" wire:target="useExample">
        {{ $current['exampleButton'] }}
    </button>
    <p class="mt-2 text-xs text-label">You can change anything the example fills in before saving.</p>
</div>
