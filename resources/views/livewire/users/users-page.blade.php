@use('App\Enums\Access')
@use('App\Enums\Page')
@use('App\Livewire\Users\UsersPage')
@use('App\Support\Format')
@use('App\Support\SpecialAccess')

<x-page title="Users" guide="users">
    <div class="max-w-[1100px]">
        <x-flash class="mb-4" />
        @if ($devLink)
            <x-dev-email-notice :link="$devLink" label="Open the invitation link" class="mb-4" />
        @endif

        <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
            <x-search-box label="Search users" :width="200" wire:model.live.debounce.300ms="search" />
            @if ($access['add'])
                <button type="button" class="form-button form-button-sm" wire:click="openAdd">Add User</button>
            @endif
        </div>

        <div class="table-scroller mt-2">
            {{-- On narrow screens the Username (3rd) and Department (4th) columns are hidden, filler rows included. --}}
            <table class="data-table max-md:[&_tr>*:nth-child(3)]:hidden max-md:[&_tr>*:nth-child(4)]:hidden">
                <thead>
                    <tr>
                        <th scope="col" class="cell-number">No</th>
                        <th scope="col">Name</th>
                        <th scope="col">Username</th>
                        <th scope="col">Department</th>
                        <th scope="col" class="cell-center w-px">Status</th>
                        <th scope="col" class="cell-actions"><span class="sr-only">Edit</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($users as $index => $member)
                        @php($isDisabled = $member->isDeactivated())
                        <tr
                            wire:key="user-{{ $member->id }}"
                            @unless ($member->is_admin) wire:click="openUser({{ $member->id }})" @endunless
                            @class(['row-clickable' => ! $member->is_admin, 'row-selected' => $member->id === $selected?->id, 'row-inactive' => $isDisabled])
                        >
                            <td class="cell-number">{{ $users->firstItem() + $index }}</td>
                            <td>
                                <span @class(['block', 'font-medium text-ink-strong' => ! $isDisabled])>{{ $member->first_name }} {{ $member->last_name }}</span>
                                <span class="mt-0.5 block text-xs text-label">{{ $member->email }}</span>
                            </td>
                            <td>
                                @if ($member->username)
                                    {{ $member->username }}
                                @elseif (! $member->is_admin)
                                    <span class="cell-empty">Invited</span>
                                @endif
                            </td>
                            <td>
                                @if ($member->is_admin)
                                    <span class="cell-muted">Administrator</span>
                                @else
                                    {{ $member->department?->name }}
                                @endif
                            </td>
                            <td class="cell-center">
                                <span @class(['status-pill', 'text-danger' => $isDisabled, 'text-success' => ! $isDisabled])>{{ $isDisabled ? 'Disabled' : 'Active' }}</span>
                            </td>
                            <td class="cell-actions">
                                <button
                                    type="button"
                                    class="icon-button"
                                    wire:click.stop="openUser({{ $member->id }})"
                                    @disabled($member->is_admin)
                                    @if ($member->is_admin) title="The company owner's account can't be changed here." @endif
                                    aria-label="Update {{ $member->first_name }} {{ $member->last_name }}"
                                >
                                    <x-icon name="pencil-square" :size="16" />
                                </button>
                            </td>
                        </tr>
                    @endforeach
                    <x-filler-rows
                        :count="$users->count()"
                        :minimum="UsersPage::MINIMUM_ROWS"
                        :columns="6"
                        :empty-text="trim($search) === '' ? 'No users yet.' : 'No users match your search.'"
                    />
                </tbody>
            </table>
        </div>

        <x-pagination :paginator="$users" />
    </div>

    @if ($modalOpen)
        @include('livewire.users.partials.user-modal')
    @endif
</x-page>
