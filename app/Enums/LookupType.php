<?php

namespace App\Enums;

use App\Models\AccountType;
use App\Models\ClusterType;
use App\Models\Department;
use App\Models\MeterType;

/**
 * The company lookup lists on the Operation Setup tab, in display order.
 */
enum LookupType: string
{
    case AccountTypes = 'account-types';
    case MeterTypes = 'meter-types';
    case ClusterTypes = 'cluster-types';
    case Departments = 'departments';

    public function label(): string
    {
        return match ($this) {
            self::AccountTypes => 'Account Type',
            self::MeterTypes => 'Meter Type',
            self::ClusterTypes => 'Cluster Type',
            self::Departments => 'Department',
        };
    }

    public function heading(): string
    {
        return $this === self::Departments ? 'Departments' : $this->label();
    }

    public function plural(): string
    {
        return $this->label().'s';
    }

    /**
     * Whether entries have an Active checkbox. Departments don't show one.
     */
    public function tracksActive(): bool
    {
        return $this !== self::Departments;
    }

    /**
     * Whether removed entries are listed and can be restored.
     */
    public function showsRemoved(): bool
    {
        return $this !== self::Departments;
    }

    /**
     * @return class-string<AccountType|MeterType|ClusterType|Department>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::AccountTypes => AccountType::class,
            self::MeterTypes => MeterType::class,
            self::ClusterTypes => ClusterType::class,
            self::Departments => Department::class,
        };
    }
}
