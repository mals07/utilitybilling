<?php

namespace App\Models;

use App\Enums\Access;
use Database\Factories\RolePermissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a role may do on one page. `page` is a value of App\Enums\Page.
 */
#[Fillable(['role_id', 'page', 'can_add', 'can_edit', 'can_view', 'can_delete'])]
class RolePermission extends Model
{
    /** @use HasFactory<RolePermissionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'can_add' => 'boolean',
            'can_edit' => 'boolean',
            'can_view' => 'boolean',
            'can_delete' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return array<string, bool> Keyed by Access value.
     */
    public function flags(): array
    {
        $flags = [];
        foreach (Access::cases() as $access) {
            $flags[$access->value] = (bool) $this->{$access->column()};
        }

        return $flags;
    }
}
