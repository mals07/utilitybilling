<?php

namespace Tests;

use App\Enums\Access;
use App\Enums\Page;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * A company and its owner (an admin).
     *
     * @return array{0: User, 1: Company}
     */
    protected function ownerWithCompany(): array
    {
        $company = Company::factory()->create();

        return [$company->user->fresh(), $company];
    }

    /**
     * A non-admin member of the company whose role grants the given access to a page.
     *
     * @param  list<Access>  $access
     */
    protected function memberWithAccess(Company $company, Page $page, array $access): User
    {
        $role = Role::factory()->for($company)->create();

        if ($access !== []) {
            $flags = [];
            foreach (Access::cases() as $type) {
                $flags[$type->column()] = in_array($type, $access, true);
            }
            $role->permissions()->create(['page' => $page->value, ...$flags]);
        }

        return User::factory()->memberOf($company, $role)->create();
    }
}
