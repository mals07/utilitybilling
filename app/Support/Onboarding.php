<?php

namespace App\Support;

use App\Enums\InvoiceKind;
use App\Enums\Page;
use App\Enums\WorkOrderStatus;
use App\Enums\WorkOrderType;
use App\Models\Company;
use App\Models\User;

/**
 * Onboarding for new users: the Getting Started checklist on the Dashboard, which ticks itself off
 * as the company is set up, and the tour of the pages the user can open.
 *
 * Tour screenshots live in public/images/onboarding/{key}.png and are left out when missing.
 */
final class Onboarding
{
    /**
     * Getting Started steps in the order a new company works through them, each with whether it is done yet.
     *
     * @return list<array{key: string, title: string, description: string, page: Page, url: string, optional: bool, done: bool}>
     */
    public static function checklist(Company $company): array
    {
        return [
            [
                'key' => 'profile',
                'title' => 'Set up your company profile',
                'description' => 'Your company name, address, logo and point person appear on every bill and receipt.',
                'page' => Page::CompanySetup,
                'url' => route('company'),
                'optional' => false,
                'done' => true,
            ],
            [
                'key' => 'operation',
                'title' => 'Choose your billing settings',
                'description' => 'Pick a currency, time zone, due-date days and tax on the Operation Setup tab.',
                'page' => Page::CompanySetup,
                'url' => route('company', ['tab' => 'operation-setup']),
                'optional' => false,
                'done' => $company->currency !== null,
            ],
            [
                'key' => 'lookups',
                'title' => 'Add account and meter types',
                'description' => 'Every customer needs an account type (e.g. Residential), and every meter a meter type.',
                'page' => Page::CompanySetup,
                'url' => route('company', ['tab' => 'operation-setup']),
                'optional' => false,
                'done' => $company->accountTypes()->exists() && $company->meterTypes()->exists(),
            ],
            [
                'key' => 'rates',
                'title' => 'Set up your water rates',
                'description' => 'Rate types and their consumption ranges decide how much each cubic meter costs.',
                'page' => Page::Rates,
                'url' => route('rates'),
                'optional' => false,
                'done' => $company->rateTypes()->exists(),
            ],
            [
                'key' => 'charges',
                'title' => 'Add your fees and charges',
                'description' => 'Installation, reconnection and other fees charged on work orders and bills.',
                'page' => Page::Charges,
                'url' => route('charges'),
                'optional' => false,
                'done' => $company->chargeTypes()->exists(),
            ],
            [
                'key' => 'team',
                'title' => 'Invite your team',
                'description' => 'Create roles for your meter readers and cashiers, then invite them as users.',
                'page' => Page::Users,
                'url' => route('users'),
                'optional' => true,
                'done' => $company->members()->count() > 1,
            ],
            [
                'key' => 'customer',
                'title' => 'Add your first customer',
                'description' => 'New customers start as Pending until their meter is installed.',
                'page' => Page::Customers,
                'url' => route('customers'),
                'optional' => false,
                'done' => $company->customers()->exists(),
            ],
            [
                'key' => 'installation',
                'title' => 'Install a meter',
                'description' => 'Create and complete a New Installation work order to assign a meter and connect the customer.',
                'page' => Page::WorkOrders,
                'url' => route('work-orders'),
                'optional' => false,
                'done' => $company->workOrders()
                    ->where('order_type', WorkOrderType::NewInstallation)
                    ->where('status', WorkOrderStatus::Completed)
                    ->exists(),
            ],
            [
                'key' => 'reading',
                'title' => 'Record a meter reading',
                'description' => 'Enter the present reading; unusual usage is held for review before billing.',
                'page' => Page::MeterReadings,
                'url' => route('meter-readings'),
                'optional' => false,
                'done' => $company->meterReadings()->exists(),
            ],
            [
                'key' => 'bills',
                'title' => 'Generate your first bills',
                'description' => 'Run billing for the period to create and print monthly invoices.',
                'page' => Page::Invoices,
                'url' => route('invoices.generate'),
                'optional' => false,
                'done' => $company->invoices()->where('kind', InvoiceKind::Monthly)->exists(),
            ],
            [
                'key' => 'payment',
                'title' => 'Post a payment',
                'description' => 'Record what a customer paid and print their official receipt.',
                'page' => Page::Payments,
                'url' => route('payments'),
                'optional' => false,
                'done' => $company->payments()->exists(),
            ],
        ];
    }

    /**
     * Whether the user sees the Getting Started checklist: the company's admins, until they hide it.
     */
    public static function showsChecklist(User $user): bool
    {
        return $user->is_admin && $user->getting_started_dismissed_at === null;
    }

    /**
     * Tour stops: a welcome, one per page the user can open (in sidebar order), and a closing stop.
     *
     * @return list<array{key: string, title: string, body: string, target: ?string, url: ?string, image: ?string}>
     */
    public static function tour(User $user): array
    {
        $appName = config('app.name');

        $stops = [[
            'key' => 'dashboard',
            'title' => "Welcome to {$appName}",
            'body' => $user->is_admin
                ? 'This quick tour shows where everything lives. Follow the Getting Started checklist on the Dashboard to set up your company, then bill your first customer.'
                : 'This quick tour shows the pages your role gives you. Use the sidebar on the left to move between them.',
            'target' => 'dashboard',
            'url' => route('dashboard'),
        ]];

        foreach (Page::cases() as $page) {
            if ($user->canAccessPage($page)) {
                $stops[] = [
                    'key' => $page->value,
                    'title' => $page->label(),
                    'body' => self::pageSummary($page),
                    'target' => $page->group() === 'settings' ? 'settings' : $page->value,
                    'url' => route($page->value),
                ];
            }
        }

        $stops[] = [
            'key' => 'finish',
            'title' => "You're all set",
            'body' => 'You can take this tour again anytime from "Take the tour" at the bottom of the sidebar.',
            'target' => 'tour',
            'url' => null,
        ];

        return array_map(fn (array $stop): array => [...$stop, 'image' => self::image($stop['key'])], $stops);
    }

    /**
     * What a page is for, as told on its tour stop.
     */
    public static function pageSummary(Page $page): string
    {
        return match ($page) {
            Page::Customers => 'Your customer directory. Add account holders with their address, account type, rate and map location. Open a customer to see their ledger and print a statement of account.',
            Page::WorkOrders => 'Field work such as new installations, disconnections, reconnections, repairs and complaints. Completing a New Installation assigns the meter and connects the customer.',
            Page::MeterReadings => "Enter each connected customer's monthly reading and see the usage before saving. Unusually high or zero usage waits for review before it can be billed.",
            Page::Invoices => 'Generate monthly bills from approved readings, print invoices, and issue debit or credit memos to correct a bill.',
            Page::Payments => 'Post payments against open bills, oldest first, and print the official receipt.',
            Page::Disconnections => 'Customers with too many overdue bills. Print notices of disconnection, and create the Disconnection work order once the deadline passes unpaid.',
            Page::CompanySetup => 'Your company profile and logo, billing headers and footers, due dates, tax, and the lookup lists for account types, meter types, clusters and departments.',
            Page::Rates => 'Rate types and their consumption ranges, which decide how much a bill charges for the water used.',
            Page::Charges => 'Fees and charges, such as installation or reconnection fees, added to work orders and bills.',
            Page::Users => 'Invite staff by email, assign their roles and departments, and grant temporary special access.',
            Page::Roles => 'Decide which pages each role can view, add to, edit or delete.',
        };
    }

    /**
     * Public URL of a tour stop's screenshot, if one has been added.
     */
    private static function image(string $key): ?string
    {
        $path = "images/onboarding/{$key}.png";

        return is_file(public_path($path)) ? asset($path) : null;
    }
}
