@use('App\Enums\Access')
@use('App\Enums\Page')
@use('App\Livewire\Users\UsersPage')
@use('App\Support\Format')
@use('App\Support\SpecialAccess')

@php
    $updating = ! $adding;
    $emailLocked = $updating && $selected?->last_login_at !== null;
    $invalid = fn (string $key): string => $errors->has($key) ? 'true' : 'false';
    $statusLabels = ['upcoming' => 'Not started yet', 'active' => 'Active now', 'ended' => 'Ended'];
    $hasUnsaved = collect($specialAccess)->contains(fn (array $row): bool => $row['id'] === null);
@endphp

<x-modal :title="$updating ? 'Update User Account' : 'Add User Account'" close="$wire.closeModal()" width="xl">
    <form wire:submit="save" novalidate class="mt-2">
        @if ($notice)
            <div class="mb-3">
                <x-alert :tone="$notice['tone']">{{ $notice['message'] }}</x-alert>
                @if ($notice['devLink'] ?? null)
                    <x-dev-email-notice :link="$notice['devLink']" label="Open the invitation link" class="mt-2" />
                @endif
            </div>
        @endif
        @if ($updating && $selected?->isDeactivated())
            <x-alert tone="info" class="mb-3">This user is deactivated and can't log in.</x-alert>
        @endif

        <fieldset @disabled($readOnly) class="m-0 min-w-0 border-0 p-0" wire:loading.attr="disabled" wire:target="save,setActive,resendInvitation">
            {{-- Update modal: filled-in fields drawn as gray boxes, as in its design. --}}
            <div @class([
                'grid grid-cols-1 gap-x-5 gap-y-3 sm:grid-cols-2',
                '[&_input:not([aria-invalid=true]):not(:focus)]:border-transparent [&_input:not([aria-invalid=true]):not(:focus)]:bg-panel' => $updating,
            ])>
                <div>
                    <label for="user-first-name" class="form-label">First Name<x-required-mark /></label>
                    <input id="user-first-name" type="text" wire:model="firstName" autocomplete="off" aria-required="true" autofocus class="form-control" aria-invalid="{{ $invalid('firstName') }}">
                    @error('firstName') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="user-last-name" class="form-label">Last Name<x-required-mark /></label>
                    <input id="user-last-name" type="text" wire:model="lastName" autocomplete="off" aria-required="true" class="form-control" aria-invalid="{{ $invalid('lastName') }}">
                    @error('lastName') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="user-email" class="form-label">Email Address<x-required-mark /></label>
                    <input
                        id="user-email"
                        type="email"
                        wire:model="email"
                        @readonly($emailLocked)
                        @if ($emailLocked) title="The email address can't be changed after the user has logged in." @endif
                        autocomplete="off"
                        aria-required="true"
                        class="form-control"
                        aria-invalid="{{ $invalid('email') }}"
                    >
                    @error('email') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="user-department" class="form-label">Department</label>
                    <select id="user-department" wire:model="departmentId" class="form-control" aria-invalid="{{ $invalid('departmentId') }}">
                        <option value=""></option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}">{{ $department->name }}</option>
                        @endforeach
                    </select>
                    @error('departmentId') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                    @if ($departments->isEmpty())
                        <p class="mt-1 text-caption text-placeholder">Add departments in Company Setup → Operation Setup.</p>
                    @endif
                </div>
            </div>

            <div class="mt-6 grid grid-cols-1 gap-x-5 gap-y-6 md:grid-cols-2">
                <section aria-label="Access">
                    <h3 class="mb-2 text-caption font-bold text-ink italic">Role Access</h3>
                    <label class="float">
                        <span class="float-label">Main User Role<x-required-mark /></span>
                        <select wire:model.live="roleId" aria-required="true" class="float-control chevron-select" aria-invalid="{{ $invalid('roleId') }}">
                            <option value=""></option>
                            @foreach ($this->roles as $roleOption)
                                <option value="{{ $roleOption->id }}">{{ $roleOption->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    @error('roleId') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                    @if ($this->roles->isEmpty())
                        <p class="mt-1 text-caption text-placeholder">Add roles on the Roles page first.</p>
                    @endif

                    <h3 class="mt-6 mb-2 text-caption font-bold text-ink italic">Special Role Access</h3>
                    @unless ($readOnly)
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_minmax(0,1fr)]">
                            <label class="float">
                                <span class="float-label">Pages/Forms<x-required-mark /></span>
                                <select wire:model="accessPage" aria-required="true" @disabled(! $chosenRole) class="float-control chevron-select" aria-invalid="{{ $invalid('accessPage') }}">
                                    <option value=""></option>
                                    @foreach ($specialPages as $page)
                                        <option value="{{ $page->value }}">{{ $page->label() }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="float">
                                <span class="float-label">Start Date<x-required-mark /></span>
                                <input type="date" wire:model="startDate" aria-required="true" min="{{ $today }}" @disabled(! $chosenRole) class="float-control" aria-invalid="{{ $invalid('startDate') }}">
                            </label>
                            <label class="float">
                                <span class="float-label">End Date<x-required-mark /></span>
                                <input type="date" wire:model="endDate" aria-required="true" min="{{ $startDate ?: $today }}" @disabled(! $chosenRole) class="float-control" aria-invalid="{{ $invalid('endDate') }}">
                            </label>
                        </div>
                        @unless ($chosenRole)
                            <p class="mt-1 text-caption text-placeholder">Select a main user role first.</p>
                        @endunless
                        @if ($chosenRole && $specialPages === [])
                            <p class="mt-1 text-caption text-placeholder">This role already has every page.</p>
                        @endif
                        @foreach (['accessPage', 'startDate', 'endDate'] as $key)
                            @error($key) <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        @endforeach

                        <div class="mt-2.5 flex flex-wrap items-center justify-center gap-x-6 gap-y-2" role="group" aria-label="Special access">
                            @foreach (Access::cases() as $type)
                                <label class="check">
                                    <input type="checkbox" wire:model.live="accessFlags.{{ $type->value }}" @disabled(! $chosenRole) class="check-square">
                                    {{ $type->specialAccessLabel() }}
                                </label>
                            @endforeach
                        </div>
                        @error('accessFlags') <p class="form-error" role="alert">{{ $message }}</p> @enderror

                        <button type="button" class="form-button mt-2.5 w-full" wire:click="addAccess" @disabled(! $chosenRole)>Add Access</button>
                        <hr class="form-divider">
                    @endunless
                    @error('specialAccess') <p class="form-error" role="alert">{{ $message }}</p> @enderror

                    <div @class(['table-scroller', 'mt-3' => ! $readOnly])>
                        <table class="data-table table-compact">
                            <thead>
                                <tr>
                                    <th scope="col" class="cell-number">No</th>
                                    <th scope="col" class="cell-center">Pages/Forms</th>
                                    <th scope="col" class="cell-center">Access</th>
                                    <th scope="col" class="cell-center">Start</th>
                                    <th scope="col" class="cell-center">End</th>
                                    <th scope="col" class="cell-actions w-8"><span class="sr-only">Remove</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($specialAccess as $index => $row)
                                    @php
                                        $status = SpecialAccess::status($row, $today);
                                        $removable = ! $readOnly && ($row['id'] === null || $status === 'upcoming');
                                        $label = Page::tryFrom($row['page'])?->label() ?? $row['page'];
                                    @endphp
                                    <tr
                                        wire:key="special-{{ $row['id'] ?? 'new-'.$index }}"
                                        @class(['row-inactive' => $status === 'ended'])
                                        title="{{ $row['id'] === null ? 'Not saved yet' : $statusLabels[$status] }}"
                                    >
                                        <td class="cell-number">{{ $index + 1 }}</td>
                                        <td class="cell-center">{{ $label }}{{ $row['id'] === null ? ' *' : '' }}</td>
                                        <td class="cell-center cell-muted text-caption">{{ SpecialAccess::describe($row['flags']) }}</td>
                                        <td class="cell-center text-caption whitespace-nowrap">{{ Format::isoDate($row['startDate']) }}</td>
                                        <td class="cell-center text-caption whitespace-nowrap">{{ Format::isoDate($row['endDate']) }}</td>
                                        <td class="cell-actions">
                                            @if ($removable)
                                                <button type="button" class="icon-button icon-button-danger" wire:click="removeAccess({{ $index }})" aria-label="Remove special access to {{ $label }}">
                                                    <x-icon name="trash" :size="14" />
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                <x-filler-rows :count="count($specialAccess)" :minimum="UsersPage::SPECIAL_ACCESS_MINIMUM_ROWS" :columns="6" />
                            </tbody>
                        </table>
                    </div>
                    @if ($hasUnsaved)
                        <p class="mt-1 text-caption text-placeholder">
                            * Not saved yet. Click {{ $updating ? 'Save Changes' : 'Send Email Invitation to User' }} to save.
                        </p>
                    @endif
                </section>

                <section aria-label="Forms and pages of the main user role">
                    <div class="table-scroller">
                        <table class="data-table table-striped table-compact">
                            <thead>
                                <tr>
                                    <th scope="col" class="cell-number">No</th>
                                    <th scope="col" class="cell-center">Forms/Pages</th>
                                    <th scope="col" class="cell-center">Access</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rolePermissions as $index => $permission)
                                    <tr wire:key="role-permission-{{ $permission->id }}">
                                        <td class="cell-number">{{ $index + 1 }}</td>
                                        <td class="cell-center">{{ Page::from($permission->page)->label() }}</td>
                                        <td class="cell-center cell-muted text-caption">{{ SpecialAccess::describe($permission->flags()) }}</td>
                                    </tr>
                                @endforeach
                                <x-filler-rows
                                    :count="$rolePermissions->count()"
                                    :minimum="UsersPage::ROLE_ACCESS_MINIMUM_ROWS"
                                    :columns="3"
                                    :empty-text="$chosenRole ? 'This role has no form access yet.' : null"
                                />
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </fieldset>

        @unless ($readOnly)
            <div class="mt-6 flex flex-wrap justify-end gap-2">
                @if ($updating && $selected && $selected->id !== auth()->id())
                    @php($fullName = $selected->first_name.' '.$selected->last_name)
                    <button
                        type="button"
                        class="form-button min-w-40 md:min-w-0 md:flex-[0_0_calc(25%-4px)]"
                        wire:click="setActive({{ $selected->isDeactivated() ? 'true' : 'false' }})"
                        wire:confirm="{{ $selected->isDeactivated()
                            ? "Reactivate {$fullName}? They will be able to log in again."
                            : "Deactivate {$fullName}? They will be signed out and won't be able to log in until reactivated." }}"
                        wire:loading.attr="disabled"
                    >
                        {{ $selected->isDeactivated() ? 'Reactivate User' : 'Deactivate User' }}
                    </button>
                @endif
                <button type="submit" class="form-button min-w-40 md:min-w-0 md:flex-[0_0_calc(25%-4px)]" wire:loading.attr="disabled" wire:target="save">
                    {{ $updating ? 'Save Changes' : 'Send Email Invitation to User' }}
                </button>
            </div>
        @endunless

        @if ($updating && ! $readOnly && $selected?->last_login_at === null && ! $selected?->isDeactivated())
            <p class="mt-2.5 text-right">
                <button type="button" class="link-button italic" wire:click="resendInvitation" wire:loading.attr="disabled">
                    Resend Email Invitation to User
                </button>
            </p>
        @endif
    </form>
</x-modal>
