<?php

namespace App\Models;

use App\Enums\Access;
use App\Enums\Page;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Temporary access to a page outside the user's role, from start_date to end_date inclusive.
 */
#[Fillable(['page', 'can_add', 'can_edit', 'can_view', 'can_delete', 'start_date', 'end_date'])]
class UserSpecialAccess extends Model
{
    protected $table = 'user_special_access';

    public const UPDATED_AT = null;

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
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The row as the Users modal works with it: dates as "YYYY-MM-DD", access as flags.
     *
     * @return array{id: int, page: string, flags: array<string, bool>, startDate: string, endDate: string}|null
     */
    public function toRow(): ?array
    {
        if (Page::tryFrom($this->page) === null) {
            return null;
        }

        $flags = [];
        foreach (Access::cases() as $access) {
            $flags[$access->value] = (bool) $this->{$access->column()};
        }

        return [
            'id' => $this->id,
            'page' => $this->page,
            'flags' => $flags,
            'startDate' => $this->start_date->toDateString(),
            'endDate' => $this->end_date->toDateString(),
        ];
    }
}
