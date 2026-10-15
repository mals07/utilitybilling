<?php

namespace App\Models;

use App\Enums\Access;
use App\Enums\Page;
use App\Support\Format;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Fillable([
    'name', 'first_name', 'last_name', 'email', 'username', 'password', 'email_verified_at', 'is_admin',
    'role_id', 'company_id', 'department_id', 'invited_at', 'last_login_at', 'deactivated_at',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * What the user may do on each page, worked out once per request.
     *
     * @var array<string, array<string, bool>>|null
     */
    private ?array $resolvedPermissions = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'invited_at' => 'datetime',
            'last_login_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'is_admin' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * The company the user works in: the one they own, or the one that invited them.
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The company the user created at sign-up, once its profile is saved.
     *
     * @return HasOne<Company, $this>
     */
    public function ownedCompany(): HasOne
    {
        return $this->hasOne(Company::class);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withTrashed();
    }

    /**
     * @return HasMany<UserSpecialAccess, $this>
     */
    public function specialAccess(): HasMany
    {
        return $this->hasMany(UserSpecialAccess::class);
    }

    /**
     * @return HasMany<AuthToken, $this>
     */
    public function authTokens(): HasMany
    {
        return $this->hasMany(AuthToken::class);
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function hasCompany(): bool
    {
        return $this->company_id !== null;
    }

    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    /**
     * Where the user belongs given how far through onboarding they are.
     */
    public function homeRoute(): string
    {
        return match (true) {
            ! $this->hasVerifiedEmail() => 'verify-email',
            ! $this->hasCompany() => 'company',
            default => 'dashboard',
        };
    }

    /**
     * Admins can do everything; other users get what their role allows, plus any special access running today.
     */
    public function canAccessPage(Page $page, Access $access = Access::View): bool
    {
        return $this->is_admin || ($this->permissions()[$page->value][$access->value] ?? false);
    }

    /**
     * Everything the user may do on a page.
     *
     * @return array<string, bool> Keyed by Access value.
     */
    public function pageAccess(Page $page): array
    {
        $flags = [];
        foreach (Access::cases() as $access) {
            $flags[$access->value] = $this->canAccessPage($page, $access);
        }

        return $flags;
    }

    /**
     * Role access plus special access running today (in the company's time zone); where both cover a page, either grants.
     *
     * @return array<string, array<string, bool>>
     */
    public function permissions(): array
    {
        if ($this->resolvedPermissions !== null) {
            return $this->resolvedPermissions;
        }

        $rows = $this->role_id === null
            ? collect()
            : RolePermission::query()->where('role_id', $this->role_id)->get();

        $today = Format::todayIn($this->company?->timezone);
        $rows = $rows->concat(
            $this->specialAccess()->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->get(),
        );

        $permissions = [];
        foreach ($rows as $row) {
            if (Page::tryFrom($row->page) === null) {
                continue;
            }

            foreach (Access::cases() as $access) {
                $permissions[$row->page][$access->value] = ($permissions[$row->page][$access->value] ?? false)
                    || (bool) $row->{$access->column()};
            }
        }

        return $this->resolvedPermissions = $permissions;
    }

    /**
     * Forget the worked-out permissions, e.g. after the user's role changes.
     */
    public function forgetPermissions(): void
    {
        $this->resolvedPermissions = null;
    }

    /**
     * Ends every session of the user, e.g. after a password reset or deactivation, and voids "remember me" cookies.
     */
    public function signOutEverywhere(): void
    {
        DB::table(config('session.table', 'sessions'))->where('user_id', $this->id)->delete();

        $this->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
    }

    public static function fullName(string $firstName, string $lastName): string
    {
        return trim($firstName.' '.$lastName);
    }

    /**
     * "Ana Maria Cruz" → first "Ana", last "Maria Cruz".
     *
     * @return array{first_name: string, last_name: string}
     */
    public static function splitFullName(string $name): array
    {
        $trimmed = trim($name);
        $space = strpos($trimmed, ' ');

        if ($space === false) {
            return ['first_name' => mb_substr($trimmed, 0, 75), 'last_name' => ''];
        }

        return [
            'first_name' => mb_substr(substr($trimmed, 0, $space), 0, 75),
            'last_name' => mb_substr(trim(substr($trimmed, $space + 1)), 0, 75),
        ];
    }
}
