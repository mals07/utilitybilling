<div>
    @if ($steps !== [])
        <section aria-labelledby="getting-started-title" class="mt-6 border border-table-line bg-card px-4 py-6 sm:px-8">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id="getting-started-title" class="text-base font-bold text-ink-strong">Getting started</h2>
                    <p class="mt-1 text-sm text-label">
                        @if ($doneCount === count($steps))
                            Your company is set up and billing. Nice work!
                        @else
                            Work through these steps to bill your first customer. Each one ticks itself off once it's done.
                        @endif
                    </p>
                </div>

                <p class="text-sm font-medium text-ink-soft">{{ $doneCount }} of {{ count($steps) }} done</p>
            </div>

            <div class="mt-4 h-1.5 overflow-hidden rounded-full bg-panel" role="progressbar" aria-label="Getting started progress" aria-valuemin="0" aria-valuemax="{{ count($steps) }}" aria-valuenow="{{ $doneCount }}">
                <div class="h-full bg-success transition-[width]" style="width: {{ round($doneCount / count($steps) * 100) }}%"></div>
            </div>

            <ol class="mt-5 divide-y divide-table-line border-y border-table-line">
                @foreach ($steps as $step)
                    <li wire:key="step-{{ $step['key'] }}" class="flex items-start gap-3 py-3" data-step="{{ $step['key'] }}" data-done="{{ $step['done'] ? 'true' : 'false' }}">
                        @if ($step['done'])
                            <span class="mt-0.5 inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-success text-white">
                                <x-icon name="check" :size="14" />
                                <span class="sr-only">Done:</span>
                            </span>
                        @else
                            <span class="mt-0.5 inline-flex size-6 shrink-0 items-center justify-center rounded-full border border-line text-xs font-semibold text-label" aria-hidden="true">{{ $loop->iteration }}</span>
                        @endif

                        <div class="min-w-0 flex-1">
                            <p @class(['text-sm font-medium', 'text-label line-through' => $step['done'], 'text-ink-strong' => ! $step['done']])>
                                {{ $step['title'] }}
                                @if ($step['optional'])
                                    <span class="ml-1 text-xs font-normal text-label no-underline">(optional)</span>
                                @endif
                            </p>
                            @unless ($step['done'])
                                <p class="mt-0.5 text-sm text-label">{{ $step['description'] }}</p>
                            @endunless
                        </div>

                        @unless ($step['done'])
                            <a href="{{ $step['url'] }}" @class(['form-button form-button-sm shrink-0' => $step['key'] === $nextKey, 'link-button shrink-0 text-sm' => $step['key'] !== $nextKey])>
                                {{ $step['key'] === $nextKey ? 'Start' : 'Open' }}
                            </a>
                        @endunless
                    </li>
                @endforeach
            </ol>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                <button type="button" class="link-button text-sm" x-on:click="$dispatch('start-tour')">Take the tour</button>
                <button type="button" class="text-button text-sm" wire:click="dismiss" wire:confirm="Hide the Getting Started checklist? You can still take the tour from the sidebar.">Hide checklist</button>
            </div>
        </section>
    @endif
</div>
