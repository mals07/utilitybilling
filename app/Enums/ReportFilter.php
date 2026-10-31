<?php

namespace App\Enums;

/**
 * The filters a report can offer above its tables.
 */
enum ReportFilter: string
{
    /** A from–to range of days. */
    case Dates = 'dates';

    /** One billing month. */
    case Period = 'period';

    /** A from–to range of billing months. */
    case Periods = 'periods';

    case Zone = 'zone';

    case AccountType = 'account-type';

    /** The staff member who received the payments. */
    case Cashier = 'cashier';
}
