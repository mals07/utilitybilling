@use('App\Enums\Access')
@use('App\Enums\Page')
@use('App\Livewire\Users\UsersPage')
@use('App\Support\Format')
@use('App\Support\SpecialAccess')

<x-page title="Users" guide="users">
    <div class="max-w-[960px]">
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
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col" class="cell-number">No</th>
                        <th scope="col">First Name</th>
                        <th scope="col">Last Name</th>
                        <th scope="col">Username</th>
                        <th scope="col">Email</th>
                        <th scope="col">Department</th>
                        <th scope="col" class="cell-center">Disabled On</th>
                        <th scope="col" class="cell-actions"><span class="sr-only">Edit</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($users as $index => $member)
                        <tr wire:key="user-{{ $member->id }}" @class(['row-selected' => $member->id === $selected?->id, 'row-inactive' => $member->isDeactivated()])>
                            <td class="cell-number">{{ $users->firstItem() + $index }}</td>
                            <td>{{ $member->first_name }}</td>
                            <td>{{ $member->last_name }}</td>
                            <td>
                                @if ($member->username)
                                    {{ $member->username }}
                                @elseif (! $member->is_admin)
                                    <span class="cell-empty">Invited</span>
                                @endif
                            </td>
                            <td>{{ $member->email }}</td>
                            <td>
                                @if ($member->is_admin)
                                    <span class="cell-muted">Administrator</span>
                                @else
                                    {{ $member->department?->name }}
                                @endif
                            </td>
                            <td class="cell-center">
                                @if ($member->isDeactivated())
                                    <span class="text-xs font-semibold text-danger">YES</span>
                                @endif
                            </td>
                            <td class="cell-actions">
                                <button
                                    type="button"
                                    class="icon-button"
                                    wire:click="openUser({{ $member->id }})"
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
                        :columns="8"
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
