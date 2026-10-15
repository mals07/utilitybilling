<?php

namespace App\Livewire\Users;

use App\Enums\Access;
use App\Enums\AuthTokenType;
use App\Enums\Page;
use App\Livewire\Auth\AuthRules;
use App\Livewire\Concerns\GuardsPageAccess;
use App\Livewire\Concerns\ParsesIds;
use App\Livewire\Roles\RolesPage;
use App\Models\Department;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserSpecialAccess;
use App\Support\AuthEmails;
use App\Support\AuthTokens;
use App\Support\SpecialAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Users of the company: invite them, update them, deactivate and reactivate them.
 */
#[Layout('layouts.app')]
#[Title('Users')]
class UsersPage extends Component
{
    use GuardsPageAccess, ParsesIds, WithPagination;

    /**
     * Users shown per page of the users table.
     */
    public const PER_PAGE = 15;

    /**
     * Minimum rows drawn in the users table, so an empty table keeps its shape.
     */
    public const MINIMUM_ROWS = 15;

    /**
     * Minimum rows drawn in the modal's role access table.
     */
    public const ROLE_ACCESS_MINIMUM_ROWS = 12;

    /**
     * Minimum rows drawn in the modal's special access table.
     */
    public const SPECIAL_ACCESS_MINIMUM_ROWS = 4;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /**
     * The user open in the Update User Account modal.
     */
    #[Url(as: 'user', except: '')]
    public string $userId = '';

    public bool $adding = false;

    /**
     * Development only: the invitation link shown above the table after a save.
     */
    public ?string $devLink = null;

    /* ------------------------------- Modal form ------------------------------ */

    public string $firstName = '';

    public string $lastName = '';

    public string $email = '';

    public string $departmentId = '';

    public string $roleId = '';

    /**
     * Special access rows; rows without an id are new and not saved yet.
     *
     * @var list<array{id: ?int, page: string, flags: array<string, bool>, startDate: string, endDate: string}>
     */
    public array $specialAccess = [];

    /** The special access row being put together, added to the table by "Add Access". */
    public string $accessPage = '';

    public string $startDate = '';

    public string $endDate = '';

    /**
     * @var array<string, bool>
     */
    public array $accessFlags = RolesPage::NO_ACCESS;

    /**
     * @var array{tone: string, message: string, devLink?: ?string}|null
     */
    #[Locked]
    public ?array $notice = null;

    protected function page(): Page
    {
        return Page::Users;
    }

    public function mount(): void
    {
        if ($this->userId !== '') {
            $this->fillFromUser();
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    private function today(): string
    {
        return $this->company()->today();
    }

    /**
     * A user of the company that this page manages: everyone except the owner.
     */
    private function findManagedUser(?int $id): ?User
    {
        return $id === null
            ? null
            : $this->company()->members()->where('is_admin', false)->with('specialAccess')->find($id);
    }

    #[Computed]
    public function selected(): ?User
    {
        return $this->findManagedUser(self::idFrom($this->userId));
    }

    /* ---------------------------- Opening/closing ---------------------------- */

    public function openAdd(): void
    {
        $this->devLink = null;
        $this->userId = '';
        $this->resetForm();
        $this->adding = true;
    }

    public function openUser(int $id): void
    {
        $this->devLink = null;
        $this->adding = false;
        $this->userId = (string) $id;
        $this->fillFromUser();
    }

    public function closeModal(): void
    {
        $this->adding = false;
        $this->userId = '';
        $this->resetForm();
    }

    private function resetForm(): void
    {
        unset($this->selected);
        $this->reset('firstName', 'lastName', 'email', 'departmentId', 'roleId', 'specialAccess', 'notice');
        $this->resetDraft();
        $this->resetValidation();
    }

    private function resetDraft(): void
    {
        $this->accessPage = '';
        $this->startDate = '';
        $this->endDate = '';
        $this->accessFlags = RolesPage::NO_ACCESS;
    }

    private function fillFromUser(): void
    {
        $this->resetForm();
        $user = $this->selected;

        if ($user === null) {
            $this->userId = '';

            return;
        }

        $this->firstName = $user->first_name;
        $this->lastName = $user->last_name;
        $this->email = $user->email;
        $this->departmentId = (string) ($user->department_id ?? '');
        $this->roleId = (string) ($user->role_id ?? '');
        $this->specialAccess = self::savedRows($user);
    }

    /**
     * @return list<array{id: int, page: string, flags: array<string, bool>, startDate: string, endDate: string}>
     */
    private static function savedRows(User $user): array
    {
        return $user->specialAccess
            ->sortBy([['start_date', 'asc'], ['id', 'asc']])
            ->map(fn (UserSpecialAccess $row): ?array => $row->toRow())
            ->filter()
            ->values()
            ->all();
    }

    /* ----------------------------- Special access ---------------------------- */

    /**
     * The roles of the company with their form access, for the role access table.
     */
    #[Computed]
    public function roles(): Collection
    {
        return $this->company()->roles()->with('permissions')->orderBy('name')->get();
    }

    #[Computed]
    public function chosenRole(): ?Role
    {
        return $this->roles->firstWhere('id', self::idFrom($this->roleId));
    }

    /**
     * Pages the main user role already opens.
     *
     * @return list<string>
     */
    private function rolePages(?Role $role): array
    {
        return $role?->permissions->pluck('page')->all() ?? [];
    }

    public function updatedRoleId(): void
    {
        $this->resetValidation(['roleId', 'specialAccess']);

        if (in_array($this->accessPage, $this->rolePages($this->chosenRole), true)) {
            $this->accessPage = '';
        }
    }

    public function updatedAccessFlags(mixed $checked, ?string $access = null): void
    {
        if ($access !== null) {
            $this->accessFlags = RolesPage::toggleAccess($this->accessFlags, $access, (bool) $checked);
        }
        $this->resetValidation('accessFlags');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['firstName', 'lastName', 'email', 'departmentId', 'accessPage', 'startDate', 'endDate'], true)) {
            $this->resetValidation($property);
        }
    }

    public function addAccess(): void
    {
        $page = Page::tryFrom($this->accessPage);
        $draft = [
            'page' => $this->accessPage,
            'flags' => [...RolesPage::NO_ACCESS, ...array_map('boolval', $this->accessFlags)],
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
        ];

        $errors = SpecialAccess::checkNew($draft, $this->specialAccess, $this->rolePages($this->chosenRole), $page?->label() ?? '', $this->today());
        if ($this->accessPage !== '' && $page === null) {
            $errors['accessPage'] = 'Select a page or form from the list.';
        }

        if ($errors !== []) {
            foreach ($errors as $key => $message) {
                $this->addError($key, $message);
            }

            return;
        }

        $this->specialAccess[] = ['id' => null, ...$draft];
        usort($this->specialAccess, fn (array $a, array $b): int => [$a['startDate'], $a['id'] ?? PHP_INT_MAX] <=> [$b['startDate'], $b['id'] ?? PHP_INT_MAX]);

        $this->resetDraft();
        $this->resetValidation(['accessPage', 'accessFlags', 'startDate', 'endDate', 'specialAccess']);
    }

    public function removeAccess(int $index): void
    {
        $row = $this->specialAccess[$index] ?? null;

        if ($row !== null && ($row['id'] === null || SpecialAccess::status($row, $this->today()) === 'upcoming')) {
            array_splice($this->specialAccess, $index, 1);
            $this->resetValidation('specialAccess');
        }
    }

    /* --------------------------------- Saving -------------------------------- */

    /**
     * Checks the modal's fields and special access list. Saved rows keep their stored values;
     * rows without an id are new, and saved rows missing from the list are being removed.
     *
     * @return array{department: ?int, role: int, added: list<array<string, mixed>>, removedIds: list<int>}|null
     */
    private function validateForm(?User $existing): ?array
    {
        $this->resetValidation();
        $this->firstName = trim($this->firstName);
        $this->lastName = trim($this->lastName);
        $this->email = mb_strtolower(trim($this->email));

        $email = AuthRules::email();
        $validator = validator([
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'email' => $this->email,
            'roleId' => $this->roleId,
        ], [
            'firstName' => ['bail', 'required', 'max:75'],
            'lastName' => ['bail', 'required', 'max:75'],
            'email' => $email['rules'],
            'roleId' => ['required'],
        ], [
            'firstName.required' => 'The first name field is required.',
            'firstName.max' => 'The first name must not be greater than 75 characters.',
            'lastName.required' => 'The last name field is required.',
            'lastName.max' => 'The last name must not be greater than 75 characters.',
            'email.required' => 'The email address field is required.',
            'email.max' => 'The email address must not be greater than 150 characters.',
            'email.regex' => 'Enter a valid email address.',
            'roleId.required' => 'Select a main user role.',
        ]);

        $errors = array_map(fn (array $messages): string => $messages[0], $validator->errors()->messages());
        $company = $this->company();

        $departmentId = $this->departmentId === '' ? null : self::idFrom($this->departmentId);
        if ($this->departmentId !== '') {
            // A removed department stays valid for users who already had it.
            $department = $departmentId === null ? null : Department::withTrashed()
                ->where('company_id', $company->id)
                ->whereKey($departmentId)
                ->where(fn ($query) => $query->whereNull('deleted_at')->orWhere('id', $existing?->department_id ?? 0))
                ->first();
            if ($department === null) {
                $errors['departmentId'] = 'Select a department from the list.';
            }
        }

        $role = $this->roles->firstWhere('id', self::idFrom($this->roleId));
        if ($role === null && ! isset($errors['roleId'])) {
            $errors['roleId'] = 'Select a main user role from the list.';
        }

        $today = $this->today();
        $saved = collect($existing ? self::savedRows($existing) : [])->keyBy('id');
        $keptIds = collect($this->specialAccess)->pluck('id')->filter(fn ($id) => $id !== null && $saved->has($id))->values();
        $rolePages = $this->rolePages($role);
        $added = [];
        $accessError = null;

        foreach ($this->specialAccess as $row) {
            if (($row['id'] ?? null) !== null) {
                continue;
            }

            $page = Page::tryFrom((string) ($row['page'] ?? ''));
            if ($page === null || ! is_array($row['flags'] ?? null)) {
                $accessError = 'Select a page or form from the list.';
                break;
            }

            $flags = [...RolesPage::NO_ACCESS, ...array_map('boolval', array_intersect_key($row['flags'], RolesPage::NO_ACCESS))];
            // Any access implies View, as on the Roles page.
            $flags['view'] = $flags['view'] || $flags['add'] || $flags['edit'] || $flags['delete'];
            $candidate = [
                'page' => $page->value,
                'flags' => $flags,
                'startDate' => (string) ($row['startDate'] ?? ''),
                'endDate' => (string) ($row['endDate'] ?? ''),
            ];

            $others = [...$keptIds->map(fn (int $id): array => $saved[$id])->all(), ...$added];
            $rowErrors = SpecialAccess::checkNew($candidate, $others, $rolePages, $page->label(), $today);
            if ($rowErrors !== []) {
                $accessError = "Special access for {$page->label()}: ".reset($rowErrors);
                break;
            }

            $added[] = $candidate;
        }

        $removedIds = $saved->keys()->diff($keptIds)->values()->all();
        if ($accessError === null) {
            $started = collect($removedIds)->contains(fn (int $id): bool => SpecialAccess::status($saved[$id], $today) !== 'upcoming');
            if ($started) {
                $accessError = "Special access that has already started can't be removed.";
            }
        }
        if ($accessError !== null) {
            $errors['specialAccess'] = $accessError;
        }

        if (! isset($errors['email'])) {
            $ownerId = User::query()->where('email', $this->email)->value('id');
            if ($ownerId !== null && $ownerId !== $existing?->id) {
                $errors['email'] = 'A user with this email address already exists.';
            }
        }

        if ($errors !== []) {
            foreach ($errors as $key => $message) {
                $this->addError($key, $message);
            }
            $this->notice = ['tone' => 'error', 'message' => 'Please correct the highlighted fields.'];

            return null;
        }

        return ['department' => $departmentId, 'role' => $role->id, 'added' => $added, 'removedIds' => $removedIds];
    }

    /**
     * @param  array{page: string, flags: array<string, bool>, startDate: string, endDate: string}  $row
     * @return array<string, mixed>
     */
    private static function accessAttributes(array $row): array
    {
        return [
            'page' => $row['page'],
            'can_add' => $row['flags']['add'],
            'can_edit' => $row['flags']['edit'],
            'can_view' => $row['flags']['view'],
            'can_delete' => $row['flags']['delete'],
            'start_date' => $row['startDate'],
            'end_date' => $row['endDate'],
        ];
    }

    /**
     * Emails an invitation; a failed send is reported but doesn't undo the save.
     *
     * @return array{sent: bool, devLink: ?string}
     */
    private function invite(User $user): array
    {
        $companyName = $this->company()->company_name;
        $sent = AuthEmails::trySend(fn () => AuthEmails::sendInvitation($user, $companyName), 'invitation');

        return ['sent' => $sent, 'devLink' => $sent ? AuthEmails::devLink($user->email, AuthTokenType::Invitation) : null];
    }

    public function save(): void
    {
        $this->notice = null;

        if ($this->adding) {
            $this->inviteUser();
        } elseif ($this->userId !== '') {
            $this->updateUser();
        }
    }

    /**
     * Add a user to the company and email them an invitation to create their login.
     */
    private function inviteUser(): void
    {
        if (! $this->allowed(Access::Add)) {
            return;
        }

        $validated = $this->validateForm(null);
        if ($validated === null) {
            return;
        }

        try {
            $user = DB::transaction(function () use ($validated): User {
                $user = User::query()->create([
                    'company_id' => $this->company()->id,
                    'first_name' => $this->firstName,
                    'last_name' => $this->lastName,
                    'name' => User::fullName($this->firstName, $this->lastName),
                    'email' => $this->email,
                    'role_id' => $validated['role'],
                    'department_id' => $validated['department'],
                    'invited_at' => now(),
                ]);
                $user->specialAccess()->createMany(array_map(self::accessAttributes(...), $validated['added']));

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            $this->addError('email', 'A user with this email address already exists.');

            return;
        }

        $result = $this->invite($user);

        $this->closeModal();
        $this->devLink = $result['devLink'];
        $this->flash($result['sent']
            ? "User added. An invitation was emailed to {$user->email}."
            : "User added, but the invitation email couldn't be sent. Use Resend Email Invitation to try again.");
    }

    /**
     * Save the changes made in the Update User Account modal.
     */
    private function updateUser(): void
    {
        if (! $this->allowed(Access::Edit)) {
            return;
        }

        unset($this->selected);
        $target = $this->selected;
        if ($target === null) {
            $this->closeModal();
            $this->flash('That user no longer exists. The table has been refreshed.', 'error');

            return;
        }

        $validated = $this->validateForm($target);
        if ($validated === null) {
            return;
        }

        // The email address is the login of anyone who has logged in, so it only changes before that.
        $emailChanged = $this->email !== $target->email;
        if ($emailChanged && $target->last_login_at !== null) {
            $this->addError('email', "The email address can't be changed after the user has logged in.");

            return;
        }

        try {
            DB::transaction(function () use ($target, $validated): void {
                $target->update([
                    'first_name' => $this->firstName,
                    'last_name' => $this->lastName,
                    'name' => User::fullName($this->firstName, $this->lastName),
                    'email' => $this->email,
                    'role_id' => $validated['role'],
                    'department_id' => $validated['department'],
                ]);
                // Only rows that haven't started can be removed; validateForm checked that.
                $target->specialAccess()->whereIn('id', $validated['removedIds'])->delete();
                $target->specialAccess()->createMany(array_map(self::accessAttributes(...), $validated['added']));
            });
        } catch (UniqueConstraintViolationException) {
            $this->addError('email', 'A user with this email address already exists.');

            return;
        }

        $message = 'Changes saved.';
        $devLink = null;

        // An invitation sent to the old address must not stay usable.
        if ($emailChanged) {
            $target->authTokens()->where('type', AuthTokenType::Invitation)->delete();

            if (! $target->isDeactivated()) {
                $result = $this->invite($target->fresh());
                $message = $result['sent']
                    ? "Changes saved. A new invitation was emailed to {$this->email}."
                    : "Changes saved, but the new invitation email couldn't be sent.";
                $devLink = $result['devLink'];
            }
        }

        $this->closeModal();
        $this->devLink = $devLink;
        $this->flash($message);
    }

    /**
     * Deactivate (sign out and block login) or reactivate the user in the modal.
     */
    public function setActive(bool $active): void
    {
        $this->notice = null;

        if (! $this->allowed(Access::Edit)) {
            return;
        }

        $target = $this->selected;
        if ($target === null) {
            $this->closeModal();
            $this->flash('That user no longer exists. The table has been refreshed.', 'error');

            return;
        }
        if ($target->id === $this->user()->id) {
            $this->notice = ['tone' => 'error', 'message' => "You can't deactivate your own account."];

            return;
        }

        $target->update(['deactivated_at' => $active ? null : now()]);
        if (! $active) {
            $target->signOutEverywhere();
        }

        $this->closeModal();
        $this->flash($active ? "{$target->name} has been reactivated." : "{$target->name} has been deactivated.");
    }

    /**
     * Email a new invitation link to the user in the modal, who hasn't logged in yet.
     */
    public function resendInvitation(): void
    {
        $this->notice = null;

        if (! $this->allowed(Access::Edit)) {
            return;
        }

        $target = $this->selected;
        if ($target === null) {
            $this->closeModal();
            $this->flash('That user no longer exists. The table has been refreshed.', 'error');

            return;
        }

        $error = match (true) {
            $target->last_login_at !== null => "{$target->name} has already logged in.",
            $target->isDeactivated() => 'Reactivate this user before resending the invitation.',
            default => null,
        };
        $wait = $error === null ? AuthTokens::secondsUntilResendAllowed($target, AuthTokenType::Invitation) : 0;
        if ($wait > 0) {
            $error = "Please wait {$wait} seconds before resending the invitation.";
        }
        if ($error !== null) {
            $this->notice = ['tone' => 'error', 'message' => $error];

            return;
        }

        $result = $this->invite($target);
        $this->notice = $result['sent']
            ? ['tone' => 'success', 'message' => "A new invitation was emailed to {$target->email}.", 'devLink' => $result['devLink']]
            : ['tone' => 'error', 'message' => "We couldn't send the email. Please try again shortly."];
    }

    /* -------------------------------- Render --------------------------------- */

    public function render(): View
    {
        $company = $this->company();
        $search = trim($this->search);

        $query = $company->members()
            ->with('department:id,name')
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('username', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            // The owner first, then in the order they were added.
            ->orderByDesc('is_admin')
            ->orderBy('id');

        $selected = $this->selected;
        $modalOpen = $this->adding || $selected !== null;

        $departments = collect();
        if ($modalOpen) {
            $departments = $company->departments()->orderBy('name')->get(['id', 'name']);
            // A user's department may have been removed since; keep it selectable for them.
            if ($selected?->department_id !== null && ! $departments->contains('id', $selected->department_id)) {
                $removed = Department::withTrashed()->find($selected->department_id, ['id', 'name']);
                if ($removed) {
                    $departments->push($removed);
                }
            }
        }

        $chosenRole = $this->chosenRole;
        $rolePages = $this->rolePages($chosenRole);

        return view('livewire.users.users-page', [
            'users' => $this->paginateClamped($query, self::PER_PAGE),
            'selected' => $selected,
            'modalOpen' => $modalOpen,
            'readOnly' => $this->adding ? ! $this->access['add'] : ! $this->access['edit'],
            'departments' => $departments,
            'chosenRole' => $chosenRole,
            'rolePermissions' => $chosenRole?->permissions->filter(fn (RolePermission $permission): bool => Page::tryFrom($permission->page) !== null)->values() ?? collect(),
            'specialPages' => array_filter(Page::cases(), fn (Page $page): bool => ! in_array($page->value, $rolePages, true)),
            'today' => $this->today(),
            'access' => $this->access,
        ]);
    }
}
