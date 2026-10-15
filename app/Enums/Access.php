<?php

namespace App\Enums;

/**
 * What a role may do on a page, in display order.
 */
enum Access: string
{
    case Add = 'add';
    case Edit = 'edit';
    case View = 'view';
    case Delete = 'delete';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * The Users page calls Add "Create", as in its design.
     */
    public function specialAccessLabel(): string
    {
        return $this === self::Add ? 'Create' : $this->label();
    }

    /**
     * The role_permissions / user_special_access column that stores this access.
     */
    public function column(): string
    {
        return 'can_'.$this->value;
    }

    /**
     * Shown when a user without this access tries the action anyway.
     */
    public function deniedMessage(): string
    {
        return match ($this) {
            self::Add => "You don't have permission to add on this page.",
            self::Edit => "You don't have permission to make changes on this page.",
            self::View => "You don't have permission to view this page.",
            self::Delete => "You don't have permission to delete on this page.",
        };
    }
}
