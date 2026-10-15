<?php

namespace App\Livewire\Roles;

use App\Enums\Access;
use App\Enums\Page;
use App\Livewire\Concerns\GuardsPageAccess;
use App\Livewire\Concerns\ParsesIds;
use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Roles, and the pages each role can open ("Role Access Forms").
 */
#[Layout('layouts.app')]
#[Title('Roles')]
class RolesPage extends Component
{
    use GuardsPageAccess, ParsesIds, WithPagination;

    /**
     * Roles shown per page of the roles table.
     */
    public const PER_PAGE = 8;

    /**
     * Minimum rows drawn in the roles table, so an empty table keeps its shape.
     */
    public const MINIMUM_ROWS = 8;

    /**
     * Minimum rows drawn in the form access table.
     */
    public const ACCESS_MINIMUM_ROWS = 11;

    public const NO_ACCESS = ['add' => false, 'edit' => false, 'view' => false, 'delete' => false];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /**
     * The role open in "Role Access Forms".
     */
    #[Url(as: 'role', except: '')]
    public string $role = '';

    public string $name = '';

    public string $description = '';

    /**
     * The form access row being changed; null to give access to a new page.
     */
    #[Locked]
    public ?int $editingPermissionId = null;

    public string $accessPage = '';

    /**
     * @var array<string, bool>
     */
    public array $flags = self::NO_ACCESS;

    protected function page(): Page
    {
        return Page::Roles;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function selected(): ?Role
    {
        $id = self::idFrom($this->role);

        return $id === null ? null : $this->company()->roles()->with('permissions')->find($id);
    }

    public function select(int $roleId): void
    {
        $this->role = (string) $roleId;
        unset($this->selected);
        $this->resetAccessForm();
    }

    /**
     * Add a new role and list it in the roles table.
     */
    public function addRole(): void
    {
        if (! $this->allowed(Access::Add)) {
            return;
        }

        $this->name = trim($this->name);
        $this->description = trim($this->description);

        $this->validate([
            'name' => ['bail', 'required', 'max:255'],
            'description' => ['nullable', 'max:1000'],
        ], [
            'name.required' => 'The role name field is required.',
            'name.max' => 'The role name must not be greater than 255 characters.',
            'description.max' => 'The description must not be greater than 1000 characters.',
        ]);

        if ($this->company()->roles()->where('name', $this->name)->exists()) {
            $this->addError('name', 'The role name has already been taken.');

            return;
        }

        try {
            $this->company()->roles()->create([
                'name' => $this->name,
                'description' => $this->description === '' ? null : $this->description,
            ]);
        } catch (UniqueConstraintViolationException) {
            $this->addError('name', 'The role name has already been taken.');

            return;
        }

        $this->reset('name', 'description');
        $this->resetValidation();
        $this->flash('Role added.');
    }

    /**
     * Delete a role and its form access; its users are left without a role.
     */
    public function deleteRole(int $roleId): void
    {
        if (! $this->allowed(Access::Delete)) {
            return;
        }

        // Permissions cascade and users.role_id is set to null by the foreign keys.
        $this->company()->roles()->whereKey($roleId)->delete();

        if (self::idFrom($this->role) === $roleId) {
            $this->role = '';
            $this->resetAccessForm();
        }
        unset($this->selected);

        $this->flash('Role deleted.');
    }

    /* --------------------------- Role access forms --------------------------- */

    /**
     * Adding, editing or deleting needs the page to be visible, so View is checked with them;
     * without View nothing else is possible, so unchecking it clears the others.
     */
    public function updatedFlags(mixed $checked, ?string $access = null): void
    {
        if ($access !== null) {
            $this->flags = self::toggleAccess($this->flags, $access, (bool) $checked);
        }
    }

    /**
     * @param  array<string, bool>  $flags
     * @return array<string, bool>
     */
    public static function toggleAccess(array $flags, string $access, bool $checked): array
    {
        $flags = [...self::NO_ACCESS, ...array_map('boolval', $flags)];

        if ($access === Access::View->value) {
            return $checked ? [...$flags, 'view' => true] : self::NO_ACCESS;
        }

        return [...$flags, $access => $checked, 'view' => $flags['view'] || $checked];
    }

    public function startEdit(int $permissionId): void
    {
        $permission = $this->selected?->permissions->firstWhere('id', $permissionId);
        if ($permission === null) {
            return;
        }

        $this->editingPermissionId = $permission->id;
        $this->accessPage = $permission->page;
        $this->flags = $permission->flags();
        $this->resetValidation();
    }

    public function resetAccessForm(): void
    {
        $this->editingPermissionId = null;
        $this->accessPage = '';
        $this->flags = self::NO_ACCESS;
        $this->resetValidation(['accessPage', 'canView']);
    }

    /**
     * Give the role access to a page, or save the changes to the access being edited.
     */
    public function saveFormAccess(): void
    {
        $editing = $this->editingPermissionId !== null;
        if (! $this->allowed($editing ? Access::Edit : Access::Add)) {
            return;
        }

        $role = $this->selected;
        if ($role === null) {
            $this->flash('Select a role first.', 'error');

            return;
        }

        $this->resetValidation();

        // Any access implies View.
        $flags = [...self::NO_ACCESS, ...array_map('boolval', $this->flags)];
        $flags['view'] = $flags['view'] || $flags['add'] || $flags['edit'] || $flags['delete'];

        $errors = [];
        if (! $flags['view']) {
            $errors['canView'] = 'Check at least one access.';
        }
        if (! $editing) {
            $errors['accessPage'] = match (true) {
                $this->accessPage === '' => 'Select a page or form.',
                Page::tryFrom($this->accessPage) === null => 'Select a page or form from the list.',
                $role->permissions->contains('page', $this->accessPage) => "This page already has access for {$role->name}. Use the pencil icon to change it.",
                default => null,
            };
        }

        $errors = array_filter($errors);
        if ($errors !== []) {
            foreach ($errors as $key => $message) {
                $this->addError($key, $message);
            }

            return;
        }

        $data = [
            'can_add' => $flags['add'],
            'can_edit' => $flags['edit'],
            'can_view' => $flags['view'],
            'can_delete' => $flags['delete'],
        ];

        try {
            if ($editing) {
                $updated = RolePermission::query()->whereKey($this->editingPermissionId)->where('role_id', $role->id)->update($data);
                if ($updated === 0) {
                    unset($this->selected);
                    $this->flash('That form access no longer exists. The table has been refreshed.', 'error');

                    return;
                }
            } else {
                $role->permissions()->create([...$data, 'page' => $this->accessPage]);
            }
        } catch (UniqueConstraintViolationException) {
            $this->addError('accessPage', "This page already has access for {$role->name}. Use the pencil icon to change it.");

            return;
        }

        unset($this->selected);
        $this->resetAccessForm();
        $this->flash($editing ? 'Form access updated.' : 'Form access added.');
    }

    /**
     * Remove a page's access from the role.
     */
    public function deleteFormAccess(int $permissionId): void
    {
        if (! $this->allowed(Access::Delete)) {
            return;
        }

        $role = $this->selected;
        if ($role === null) {
            $this->flash('Select a role first.', 'error');

            return;
        }

        RolePermission::query()->whereKey($permissionId)->where('role_id', $role->id)->delete();

        if ($this->editingPermissionId === $permissionId) {
            $this->resetAccessForm();
        }
        unset($this->selected);
    }

    public function render(): View
    {
        $search = trim($this->search);
        $query = $this->company()->roles()
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('id');

        $selected = $this->selected;
        // Rows for pages that no longer exist are skipped.
        $permissions = $selected?->permissions->filter(fn (RolePermission $permission): bool => Page::tryFrom($permission->page) !== null)->values()
            ?? collect();

        // Pages the role already has are left out, except the one being edited.
        $assigned = $permissions->pluck('page')->all();
        $pageChoices = array_filter(
            Page::cases(),
            fn (Page $page): bool => $page->value === $this->accessPage || ! in_array($page->value, $assigned, true),
        );

        return view('livewire.roles.roles-page', [
            'roles' => $this->paginateClamped($query, self::PER_PAGE),
            'selected' => $selected,
            'permissions' => $permissions,
            'pageChoices' => $pageChoices,
            'access' => $this->access,
        ]);
    }
}
