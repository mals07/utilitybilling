@use('App\Enums\Access')
@use('App\Enums\Page')
@use('App\Livewire\Roles\RolesPage')

@php
    $isEditing = $editingPermissionId !== null;
    $canSubmitAccess = $isEditing ? $access['edit'] : $access['add'];
    $describe = fn (array $flags): string => collect(Access::cases())->filter(fn (Access $type) => $flags[$type->value])->map->label()->implode(', ');
@endphp

<x-page title="Roles" guide="roles">
    <x-flash class="mb-4" />

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] lg:gap-8">
        <section aria-label="Roles" class="min-w-0 rounded-xs bg-panel p-5 sm:px-6">
            @if ($access['add'])
                <form wire:submit="addRole" novalidate>
                    <div>
                        <label for="role-name" class="form-label">Role Name<x-required-mark /></label>
                        <input
                            id="role-name"
                            type="text"
                            wire:model="name"
                            aria-required="true"
                            class="form-control"
                            @error('name') aria-invalid="true" aria-describedby="role-name-error" @enderror
                        >
                        @error('name')
                            <p id="role-name-error" class="form-error" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="mt-3">
                        <label for="role-description" class="form-label">Description</label>
                        <textarea
                            id="role-description"
                            rows="3"
                            wire:model="description"
                            placeholder="Input text"
                            class="form-control"
                            @error('description') aria-invalid="true" aria-describedby="role-description-error" @enderror
                        ></textarea>
                        @error('description')
                            <p id="role-description-error" class="form-error" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="form-button" wire:loading.attr="disabled" wire:target="addRole">Add Role</button>
                    </div>
                </form>
                <hr class="form-divider">
            @endif

            <div @class(['mt-12' => $access['add']])>
                <x-search-box label="Search roles" wire:model.live.debounce.300ms="search" />
            </div>

            <div class="table-scroller mt-2">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col" class="cell-number">No</th>
                            <th scope="col">Role Name</th>
                            <th scope="col" class="cell-actions"><span class="sr-only">Delete</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($roles as $index => $item)
                            <tr
                                wire:key="role-{{ $item->id }}"
                                wire:click="select({{ $item->id }})"
                                @class(['row-clickable', 'row-selected' => $item->id === $selected?->id])
                            >
                                <td class="cell-number">{{ $roles->firstItem() + $index }}</td>
                                <td class="font-medium text-ink-strong" @if ($item->description) title="{{ $item->description }}" @endif>{{ $item->name }}</td>
                                <td class="cell-actions">
                                    @if ($access['delete'])
                                        <button
                                            type="button"
                                            class="icon-button icon-button-danger"
                                            wire:click.stop="deleteRole({{ $item->id }})"
                                            wire:confirm="Delete the {{ $item->name }} role? Its form access will be removed and its users will no longer have a role."
                                            wire:loading.attr="disabled"
                                            aria-label="Delete {{ $item->name }}"
                                        >
                                            <x-icon name="trash" :size="16" />
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        <x-filler-rows
                            :count="$roles->count()"
                            :minimum="RolesPage::MINIMUM_ROWS"
                            :columns="3"
                            :empty-text="trim($search) === '' ? 'No roles yet.' : 'No roles match your search.'"
                        />
                    </tbody>
                </table>
            </div>

            <x-pagination :paginator="$roles" />
        </section>

        <div class="hidden w-px bg-line lg:block" aria-hidden="true"></div>

        {{-- Role Access Forms: which pages a role can open, and what it may do there. --}}
        <section aria-labelledby="role-access-heading" class="min-w-0 py-1">
            <h2 id="role-access-heading" class="text-right text-heading font-normal text-ink italic">Role Access Forms</h2>

            <div class="mt-6 flex h-11 flex-col justify-center rounded-xs bg-panel px-2">
                <span id="access-role-name-label" class="text-caption text-label">Role Name</span>
                <output aria-labelledby="access-role-name-label" class="h-5 truncate text-body text-ink">{{ $selected?->name }}</output>
            </div>
            @unless ($selected)
                <p class="mt-1 text-caption text-placeholder">Click a role to set up its form access.</p>
            @endunless

            @if ($canSubmitAccess)
                <form wire:submit="saveFormAccess" novalidate class="mt-3">
                    <fieldset @disabled(! $selected) class="m-0 flex min-w-0 flex-wrap items-center justify-between gap-x-6 gap-y-3 border-0 p-0 disabled:opacity-60">
                        <div class="relative w-full sm:w-44">
                            <label for="access-page" class="pointer-events-none absolute top-1 left-2 text-caption text-label">Pages/Forms<x-required-mark /></label>
                            <select
                                id="access-page"
                                wire:model="accessPage"
                                @disabled($isEditing)
                                aria-required="true"
                                class="form-control !h-11 !pt-3.5"
                                @error('accessPage') aria-invalid="true" aria-describedby="access-page-error" @enderror
                            >
                                <option value=""></option>
                                @foreach ($pageChoices as $page)
                                    <option value="{{ $page->value }}">{{ $page->label() }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="flex flex-wrap items-center gap-4" role="group" aria-label="Access">
                            @foreach (Access::cases() as $type)
                                <label class="check">
                                    <input type="checkbox" wire:model.live="flags.{{ $type->value }}" class="check-square">
                                    {{ $type->label() }}
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    @error('accessPage')
                        <p id="access-page-error" class="form-error" role="alert">{{ $message }}</p>
                    @enderror
                    @error('canView')
                        <p class="form-error" role="alert">{{ $message }}</p>
                    @enderror

                    <div class="mt-3 flex items-center gap-3">
                        @if ($isEditing)
                            <button type="button" class="text-button" wire:click="resetAccessForm">Cancel</button>
                        @endif
                        <button type="submit" class="form-button form-button-sm flex-1" @disabled(! $selected) wire:loading.attr="disabled" wire:target="saveFormAccess">
                            {{ $isEditing ? 'Update Form Access' : 'Add Form Access' }}
                        </button>
                    </div>
                </form>
            @endif

            <div class="table-scroller mt-6">
                <table class="data-table table-striped table-compact">
                    <thead>
                        <tr>
                            <th scope="col" class="cell-number">No</th>
                            <th scope="col" class="cell-center">Forms/Pages</th>
                            <th scope="col" class="cell-center">Access</th>
                            <th scope="col" class="cell-actions w-20"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($permissions as $index => $permission)
                            @php($label = Page::from($permission->page)->label())
                            <tr wire:key="permission-{{ $permission->id }}" @class(['row-selected' => $permission->id === $editingPermissionId])>
                                <td class="cell-number">{{ $index + 1 }}</td>
                                <td class="cell-center">{{ $label }}</td>
                                <td class="cell-center cell-muted text-caption">{{ $describe($permission->flags()) }}</td>
                                <td class="cell-actions">
                                    @if ($access['edit'])
                                        <button type="button" class="icon-button" wire:click="startEdit({{ $permission->id }})" aria-label="Change access to {{ $label }}">
                                            <x-icon name="pencil" :size="16" />
                                        </button>
                                    @endif
                                    @if ($access['delete'])
                                        <button
                                            type="button"
                                            class="icon-button icon-button-danger"
                                            wire:click="deleteFormAccess({{ $permission->id }})"
                                            wire:confirm="Remove access to {{ $label }} from {{ $selected->name }}?"
                                            wire:loading.attr="disabled"
                                            aria-label="Remove access to {{ $label }}"
                                        >
                                            <x-icon name="trash" :size="16" />
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        <x-filler-rows
                            :count="$permissions->count()"
                            :minimum="RolesPage::ACCESS_MINIMUM_ROWS"
                            :columns="4"
                            :empty-text="$selected ? 'No form access yet.' : null"
                        />
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-page>
