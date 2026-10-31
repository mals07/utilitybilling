<?php

namespace App\Enums;

use App\Models\AccountType;
use App\Models\ClusterType;
use App\Models\Department;
use App\Models\MeterType;

/**
 * The company lookup lists on the Operation Setup tab, in display order. Cluster types are shown as Zones.
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
            self::ClusterTypes => 'Zone',
            self::Departments => 'Department',
        };
    }

    public function heading(): string
    {
        return in_array($this, [self::Departments, self::ClusterTypes], true) ? $this->plural() : $this->label();
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
     * Account types have a Tax exempt checkbox (e.g. Government accounts are billed without tax).
     */
    public function tracksTaxExempt(): bool
    {
        return $this === self::AccountTypes;
    }

    /**
     * Meter types have a number of dials, so a meter that rolls over past 9999 can be billed.
     */
    public function tracksDialDigits(): bool
    {
        return $this === self::MeterTypes;
    }

    /**
     * Number of columns of the lookup table, the actions column included.
     */
    public function columnCount(): int
    {
        return 2 + (int) $this->tracksActive() + (int) $this->tracksTaxExempt() + (int) $this->tracksDialDigits();
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
