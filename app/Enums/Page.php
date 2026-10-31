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
    case MeterReadings = 'meter-readings';
    case Invoices = 'invoices';
    case Payments = 'payments';
    case WorkOrders = 'work-orders';
    case Disconnections = 'disconnections';
    case CollectionsReport = 'reports.collections';
    case AgingReport = 'reports.aging';
    case BillingReport = 'reports.billing';
    case AdjustmentsReport = 'reports.adjustments';
    case EfficiencyReport = 'reports.collection-efficiency';
    case ConsumptionReport = 'reports.consumption';
    case ReadingsReport = 'reports.readings';
    case DisconnectionsReport = 'reports.disconnections';
    case WorkOrdersReport = 'reports.work-orders';
    case CompanySetup = 'company';
    case Users = 'users';
    case Rates = 'rates';
    case Charges = 'charges';
    case Roles = 'roles';

    public function label(): string
    {
        return match ($this) {
            self::Customers => 'Customers',
            self::WorkOrders => 'Work Orders',
            self::MeterReadings => 'Meter Readings',
            self::Invoices => 'Invoices',
            self::Payments => 'Payments',
            self::Disconnections => 'Disconnections',
            self::CollectionsReport => 'Daily Collections',
            self::AgingReport => 'Receivables Aging',
            self::BillingReport => 'Billing Register',
            self::AdjustmentsReport => 'Voids & Adjustments',
            self::EfficiencyReport => 'Collection Efficiency',
            self::ConsumptionReport => 'Water Consumption',
            self::ReadingsReport => 'Reading Progress',
            self::DisconnectionsReport => 'Disconnection Report',
            self::WorkOrdersReport => 'Work Order Report',
            self::CompanySetup => 'Company Setup',
            self::Rates => 'Rates',
            self::Charges => 'Charges',
            self::Users => 'Users',
            self::Roles => 'Roles',
        };
    }

    /**
     * The heading this page sits under in the sidebar.
     */
    public function section(): string
    {
        return match ($this) {
            self::Customers => 'Customer Management',
            self::MeterReadings, self::Invoices, self::Payments => 'Readings & Payments',
            self::WorkOrders, self::Disconnections => 'Field Operations',
            self::CollectionsReport, self::AgingReport, self::BillingReport, self::AdjustmentsReport,
            self::EfficiencyReport, self::ConsumptionReport, self::ReadingsReport, self::DisconnectionsReport,
            self::WorkOrdersReport => 'Reports',
            self::CompanySetup, self::Users, self::Rates, self::Charges, self::Roles => 'System Administration',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Customers => 'user',
            self::MeterReadings => 'gauge',
            self::Invoices => 'receipt',
            self::Payments => 'cash',
            self::WorkOrders => 'stack',
            self::Disconnections => 'warning',
            self::CollectionsReport => 'cash',
            self::AgingReport, self::EfficiencyReport => 'chart',
            self::BillingReport => 'receipt',
            self::AdjustmentsReport => 'shield',
            self::ConsumptionReport => 'gauge',
            self::ReadingsReport => 'check',
            self::DisconnectionsReport => 'warning',
            self::WorkOrdersReport => 'stack',
            self::CompanySetup => 'building',
            self::Users => 'users',
            self::Rates => 'tag',
            self::Charges => 'document',
            self::Roles => 'shield',
        };
    }

    public function isReport(): bool
    {
        return $this->section() === 'Reports';
    }

    /**
     * Pages grouped under their sidebar heading, in sidebar order.
     *
     * @return array<string, list<self>>
     */
    public static function sections(): array
    {
        $sections = [];

        foreach (self::cases() as $page) {
            $sections[$page->section()][] = $page;
        }

        return $sections;
    }

    /**
     * The report pages, in sidebar order.
     *
     * @return list<self>
     */
    public static function reports(): array
    {
        return array_values(array_filter(self::cases(), fn (self $page): bool => $page->isReport()));
    }
}
