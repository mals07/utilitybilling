<?php

namespace App\Enums;

/**
 * Pages whose access is controlled by roles, in sidebar order.
 *
 * Each case's value is the name of the page's route and the value stored in
 * role_permissions.page and user_special_access.page.
 */
enum Page: string
{
    case Customers = 'customers';
    case WorkOrders = 'work-orders';
    case CompanySetup = 'company';
    case Rates = 'rates';
    case Charges = 'charges';
    case Users = 'users';
    case Roles = 'roles';

    public function label(): string
    {
        return match ($this) {
            self::Customers => 'Customers',
            self::WorkOrders => 'Work Orders',
            self::CompanySetup => 'Company Setup',
            self::Rates => 'Rates',
            self::Charges => 'Charges',
            self::Users => 'Users',
            self::Roles => 'Roles',
        };
    }

    /**
     * "Main" pages sit at the top level of the sidebar; "settings" pages sit under the Settings group.
     */
    public function group(): string
    {
        return in_array($this, [self::Customers, self::WorkOrders], true) ? 'main' : 'settings';
    }

    /**
     * Pages in the given sidebar group.
     *
     * @return list<self>
     */
    public static function inGroup(string $group): array
    {
        return array_values(array_filter(self::cases(), fn (self $page): bool => $page->group() === $group));
    }
}
