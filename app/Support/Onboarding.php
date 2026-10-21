<?php

namespace App\Support;

use App\Enums\Page;
use App\Enums\WorkOrderStatus;
use App\Enums\WorkOrderType;
use App\Models\Company;
use App\Models\User;

/**
 * Onboarding for new companies: the setup wizard every new sign-up works through before the rest of
 * the app opens, and the tour of the pages a user can open, shown once the wizard is finished.
 *
 * The first steps are required; from "team" on, steps can be skipped. Tour screenshots live in
 * public/images/onboarding/{key}.png and are left out when missing.
 */
final class Onboarding
{
    /**
     * Wizard steps in order; each must be done (or skipped, when optional) before the next.
     */
    public const STEPS = ['profile', 'billing', 'lists', 'rates', 'charges', 'team', 'customer', 'work-order', 'complete-order', 'payment'];

    /**
     * The first step that may be skipped; skipping it moves on, skipping a later one finishes the wizard,
     * since each later step needs the one before it.
     */
    public const FIRST_OPTIONAL_STEP = 'team';

    /**
     * What each wizard step asks for, how to do it, and a worked example.
     *
     * @return array{title: string, nav: string, intro: string, howTo: list<string>, example: list<array{0: string, 1: string}>, exampleButton: string, notDone: string}
     */
    public static function step(string $key): array
    {
        return match ($key) {
            'profile' => [
                'title' => 'Your company profile',
                'nav' => 'Company profile',
                'intro' => 'This is how your company appears on every bill, receipt and notice.',
                'howTo' => [
                    'Enter your company name and address exactly as they should print on bills.',
                    'The point person is who customers and staff contact about billing.',
                    'A logo is optional; you can add it later.',
                    'Click Save Profile; setup moves on to the next step.',
                ],
                'example' => [
                    ['Company name', 'Bayan Water District'],
                    ['Address', '12 Rizal St., Poblacion'],
                    ['City', 'Quezon City, Metro Manila'],
                    ['Point person', 'Maria Santos · 0917 123 4567'],
                ],
                'exampleButton' => 'Use my name as point person',
                'notDone' => 'Save your company profile first.',
            ],
            'billing' => [
                'title' => 'Billing settings',
                'nav' => 'Billing settings',
                'intro' => 'Choose the currency, time zone and due date your bills use, and whether you charge tax.',
                'howTo' => [
                    'Pick your currency and time zone; due dates and penalties follow your local date.',
                    'Due-date days is how long customers have to pay after a bill is issued.',
                    'Turn tax on only if you charge VAT or another tax on water bills.',
                    'Click Save at the bottom of the form, then Continue.',
                ],
                'example' => [
                    ['Currency', 'PHP - Philippine Peso'],
                    ['Time zone', 'Asia/Manila'],
                    ['Due date', '15 days after the bill date'],
                    ['Tax', 'Off'],
                    ['Billing footer', 'Please pay on or before the due date to avoid surcharges.'],
                ],
                'exampleButton' => 'Fill in recommended settings',
                'notDone' => 'Save your billing settings first (choose a currency and click Save).',
            ],
            'lists' => [
                'title' => 'Lookup lists',
                'nav' => 'Lookup lists',
                'intro' => 'Customers and meters are grouped with these lists. Every customer needs an account type and a cluster, and every meter a meter type.',
                'howTo' => [
                    'Type a name in a list and click Add.',
                    'Add at least one account type, meter type and cluster type. Departments are optional.',
                    'You can rename, deactivate or add more later in Company Setup.',
                ],
                'example' => [
                    ['Account types', 'Residential, Commercial, Government'],
                    ['Meter types', '1/2" Brass, 3/4" Brass'],
                    ['Cluster types', 'Zone 1, Zone 2'],
                    ['Departments', 'Billing, Field Operations'],
                ],
                'exampleButton' => 'Add these example lists',
                'notDone' => 'Add at least one account type, meter type and cluster type.',
            ],
            'rates' => [
                'title' => 'Water rates',
                'nav' => 'Water rates',
                'intro' => 'A rate type is a price table: each consumption range has its own price per cubic meter.',
                'howTo' => [
                    'Type a rate type name, e.g. Residential, and click Add Rate Type first. It appears in the table below.',
                    'Click its line in the table. The Rate Setup panel on the right stays locked until a rate type is selected.',
                    'In Rate Setup, enter the minimum consumption (customers using less still pay for it) and click Save.',
                    'Add the consumption ranges one at a time, from 0 up: where the range ends (To) and its price per cu.m., then Add Range.',
                ],
                'example' => [
                    ['Rate type', 'Residential'],
                    ['0 – 10 cu.m.', '₱10.00 per cu.m.'],
                    ['11 – 20 cu.m.', '₱25.00 per cu.m.'],
                    ['21 cu.m. and up', '₱35.00 per cu.m.'],
                    ['Minimum', '10 cu.m.'],
                    ['A 15 cu.m. bill', '10 × ₱10 + 5 × ₱25 = ₱225.00'],
                ],
                'exampleButton' => 'Add the example Residential rate',
                'notDone' => 'Add a rate type with at least one consumption range.',
            ],
            'charges' => [
                'title' => 'Charges and fees',
                'nav' => 'Charges and fees',
                'intro' => 'A charge type is a set of extra charges added to bills, such as a late-payment surcharge. Every customer is given one charge type.',
                'howTo' => [
                    'Add a charge type on the left, e.g. Standard, and click Add Charge Type. Each customer is given one charge type.',
                    'On the right, add each charge element, e.g. Late Payment Surcharge. Tick "after due" for charges only added to late bills, and "Taxable" if tax applies.',
                    "Choose the element's Distribution Type: Credit adds the amount to the bill; Debit deducts it, like a discount. Click Add Charge Element.",
                    "Click the gear icon on your charge type's line to open its setup.",
                    'In that window pick an element, choose Fixed Amount or Percentage, enter the value (e.g. 2 for 2%) and click ADD. Repeat for each element, then close it.',
                ],
                'example' => [
                    ['Charge type', 'Standard'],
                    ['Late Payment Surcharge', '2% of the bill, added after the due date'],
                    ['New Installation fee', '₱1,500.00, billed on its own invoice'],
                ],
                'exampleButton' => 'Add the example charges',
                'notDone' => 'Add at least one charge type.',
            ],
            'team' => [
                'title' => 'Your team',
                'nav' => 'Team (optional)',
                'intro' => 'Create roles for your staff, choose which pages each role can open, then invite them by email. You can skip this and do it later in Settings.',
                'howTo' => [
                    'Add a role and give it access to the pages it needs.',
                    'Invite a user with their email address and pick their role.',
                    'Invited staff can sign in once you finish setup.',
                ],
                'example' => [
                    ['Meter Reader', 'Meter Readings (view, add) · Customers (view)'],
                    ['Cashier', 'Payments (view, add) · Invoices (view) · Customers (view)'],
                ],
                'exampleButton' => 'Add the example roles',
                'notDone' => 'Add a role or invite a user, or skip this step.',
            ],
            'customer' => [
                'title' => 'Your first customer',
                'nav' => 'First customer',
                'intro' => 'Add a real customer who is waiting for a water connection. New customers start as Pending until their meter is installed.',
                'howTo' => [
                    'Click Add Customer.',
                    'Fill in their name and address, and pick their account type, rate, charge type and cluster.',
                    'Leave the status as Pending and click Save.',
                ],
                'example' => [
                    ['Name', 'Maria Santos'],
                    ['Account type', 'Residential · Zone 1'],
                    ['Rate / charges', 'Residential · Standard'],
                    ['Status', 'Pending'],
                ],
                'exampleButton' => 'Open the form with this example',
                'notDone' => 'Add a customer, or skip the rest of setup.',
            ],
            'work-order' => [
                'title' => 'New Installation work order',
                'nav' => 'Work order',
                'intro' => 'A work order sends your field team to do a job. Create a New Installation for the customer you just added.',
                'howTo' => [
                    'Search for the customer and pick them.',
                    'Choose New Installation as the order type and pick the meter type.',
                    'Assign it to whoever installs the meter and click Submit.',
                ],
                'example' => [
                    ['Customer', 'Maria Santos'],
                    ['Order type', 'New Installation'],
                    ['Meter type', '1/2" Brass'],
                    ['Assigned to', 'You'],
                ],
                'exampleButton' => 'Fill the form for my customer',
                'notDone' => 'Submit a New Installation work order, or skip the rest of setup.',
            ],
            'complete-order' => [
                'title' => 'Complete the installation',
                'nav' => 'Complete it',
                'intro' => 'Once the meter is in, complete the work order. The customer becomes Connected and the installation fee is billed.',
                'howTo' => [
                    'Open the New Installation order and click Complete.',
                    'Enter the meter and serial numbers and the initial reading, and tick each checklist item.',
                    'Attach a photo of the installed meter and click Complete.',
                ],
                'example' => [
                    ['Meter no', 'MTR-0001'],
                    ['Serial no', 'SN-0001'],
                    ['Initial reading', '0'],
                    ['Fee', '₱1,500.00'],
                    ['Photo', 'A picture of the installed meter'],
                ],
                'exampleButton' => 'Open the completion form',
                'notDone' => 'Complete the New Installation work order, or skip the rest of setup.',
            ],
            'payment' => [
                'title' => 'Post the fee payment',
                'nav' => 'Payment',
                'intro' => 'Record what the customer paid. Payments go to their oldest bill first, and you can print the official receipt.',
                'howTo' => [
                    'Search for the customer and pick them; their open bills are listed.',
                    'Enter the amount paid and the payment method, then click Post Payment.',
                    'Open the payment to print its receipt.',
                ],
                'example' => [
                    ['Customer', 'Maria Santos'],
                    ['Amount', '₱1,500.00'],
                    ['Method', 'Cash'],
                ],
                'exampleButton' => 'Pick my customer',
                'notDone' => 'Post a payment, or finish setup now.',
            ],
        };
    }

    /**
     * The step-by-step guide (App\Support\Guides) for each wizard step.
     */
    public static function guideFor(string $key): string
    {
        return match ($key) {
            'customer' => 'customers',
            'work-order' => 'work-order-create',
            'complete-order' => 'work-order-complete',
            'payment' => 'payments',
            default => $key,
        };
    }

    public static function isRequired(string $key): bool
    {
        return array_search($key, self::STEPS, true) < array_search(self::FIRST_OPTIONAL_STEP, self::STEPS, true);
    }

    /**
     * Whether the company has done what the step asks for.
     */
    public static function isDone(?Company $company, string $key): bool
    {
        if ($company === null) {
            return false;
        }

        return match ($key) {
            'profile' => true,
            'billing' => $company->currency !== null,
            'lists' => $company->accountTypes()->exists() && $company->meterTypes()->exists() && $company->clusterTypes()->exists(),
            'rates' => $company->rateTypes()->whereHas('ranges')->exists(),
            'charges' => $company->chargeTypes()->exists(),
            'team' => $company->roles()->exists() || $company->members()->count() > 1,
            'customer' => $company->customers()->exists(),
            'work-order' => $company->workOrders()->where('order_type', WorkOrderType::NewInstallation)->exists(),
            'complete-order' => $company->workOrders()
                ->where('order_type', WorkOrderType::NewInstallation)
                ->where('status', WorkOrderStatus::Completed)
                ->exists(),
            'payment' => $company->payments()->exists(),
        };
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
                ? 'Your company is set up. This quick tour shows where everything lives, so you can find each page again.'
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
