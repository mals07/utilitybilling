<?php

namespace App\Support;

/**
 * Step-by-step guides that spotlight one part of a page at a time and say what to do with it.
 * The setup wizard starts them on each step; app pages start them from "Guide me".
 *
 * Each step's target is a CSS selector, or a data-guide name. A step moves on by itself when
 * "grows" matches more elements than when the step began (e.g. a new table row), or when "until"
 * matches anything (e.g. a form that opens); otherwise the user clicks Next. A step whose target
 * isn't on the page shows its text without a spotlight.
 */
final class Guides
{
    /**
     * @return array<string, array{title: string, steps: list<array{target: ?string, title: string, body: string, grows?: string, until?: string}>}>
     */
    public static function all(): array
    {
        $workOrderCreate = [
            [
                'target' => '#work-order-account',
                'title' => 'Pick the customer',
                'body' => "Type the customer's account number or name, then choose them from the list. Their name and current meter fill in below.",
            ],
            [
                'target' => '#work-order-values-orderType',
                'title' => 'Choose the order type',
                'body' => "For a new connection choose New Installation. Types that don't fit this customer are greyed out with the reason, e.g. Reconnection for a customer who isn't disconnected.",
            ],
            [
                'target' => '#work-order-values-meterTypeId',
                'title' => 'Meter details',
                'body' => 'Pick the meter type to install. Meter and serial numbers are optional here; they are entered when the work is completed.',
            ],
            [
                'target' => '#work-order-values-assignedToId',
                'title' => 'Assign the job',
                'body' => 'Choose who will do the work. Tip: set a default role (e.g. Field Staff) so only field staff appear in this list.',
            ],
            [
                'target' => '[wire\:submit="submit"] [type="submit"]',
                'title' => 'Submit the work order',
                'body' => 'Click Submit Work Order. It appears in the table below as In Progress, with its own order number.',
                'grows' => '[wire\:key^="order-"]',
            ],
        ];

        $roleSteps = [
            [
                'target' => '[wire\:submit="addRole"]',
                'title' => 'Add a role',
                'body' => 'A role is a job, e.g. Meter Reader or Cashier. Type its name (and a description if you like), then click Add Role.',
                'grows' => '[wire\:key^="role-"]',
            ],
            [
                'target' => '[wire\:key^="role-"]',
                'title' => "Click the role's row",
                'body' => 'Select the role in the table. The Role Access Forms panel on the right stays locked until a role is selected.',
                'until' => '[wire\:submit="saveFormAccess"] fieldset:not([disabled])',
            ],
            [
                'target' => '[wire\:submit="saveFormAccess"]',
                'title' => 'Give it page access',
                'body' => 'Pick a page and tick what this role may do there: View, Add, Edit or Delete. Click Add Form Access, then repeat for each page. Example: Meter Reader gets Meter Readings (View, Add) and Customers (View).',
            ],
        ];

        return [
            'profile' => [
                'title' => 'Company profile',
                'steps' => [
                    [
                        'target' => '#company-name',
                        'title' => 'Company name',
                        'body' => 'Type the name exactly as it should print on bills, receipts and notices, e.g. Bayan Water District.',
                    ],
                    [
                        'target' => 'section[aria-label="Company details"]',
                        'title' => 'Address and contact details',
                        'body' => 'Fill in the street address, then pick the country, state/province and city. The email and phone are printed on bills for customers to contact you. Upload your logo below (optional).',
                    ],
                    [
                        'target' => 'section[aria-labelledby="point-person-heading"]',
                        'title' => 'Point person',
                        'body' => 'Who customers and staff contact about billing. Tip: the "Use my name as point person" button in the guide panel fills this in from your account.',
                    ],
                    [
                        'target' => '[wire\:submit="save"] [type="submit"]',
                        'title' => 'Save your profile',
                        'body' => 'Click Save Profile. Fields with a problem are marked in red; once it saves, setup moves on to Billing settings by itself.',
                    ],
                ],
            ],
            'billing' => [
                'title' => 'Billing settings',
                'steps' => [
                    [
                        'target' => '[wire\:model="companyHeaderLine"]',
                        'title' => 'Bill headers and footers',
                        'body' => 'Lines printed at the top and bottom of every bill, e.g. office hours, payment centers or "Please pay on or before the due date". All of them are optional.',
                    ],
                    [
                        'target' => '[wire\:model="dueDateDays"]',
                        'title' => 'Due date',
                        'body' => 'How many days customers have to pay after the bill date. With 15, a bill dated October 1 is due October 16.',
                    ],
                    [
                        'target' => '[wire\:model="currency"]',
                        'title' => 'Currency and time zone',
                        'body' => 'The currency shown on bills, and your time zone (just below) so due dates and penalties follow your local date.',
                    ],
                    [
                        'target' => 'fieldset:has([wire\:model="taxEnabled"])',
                        'title' => 'Tax',
                        'body' => 'Leave this off unless you charge VAT or another tax. When it is on, say whether your rates already include the tax and what it applies to.',
                    ],
                    [
                        'target' => 'fieldset:has([wire\:model="reviewZeroUsage"])',
                        'title' => 'Meter reading review',
                        'body' => 'Readings that look wrong, e.g. more than double the usual usage or zero, wait for someone to confirm them before they can be billed.',
                    ],
                    [
                        'target' => 'fieldset:has([wire\:model="disconnectionOverdueBills"])',
                        'title' => 'Disconnection rules',
                        'body' => 'How many unpaid bills before a customer can be disconnected, and how many working days their notice gives them to pay.',
                    ],
                    [
                        'target' => 'form[aria-label="Operation settings"] [type="submit"]',
                        'title' => 'Save',
                        'body' => 'Click Save. Tip: "Fill in recommended settings" in the guide panel fills the main fields for you first.',
                    ],
                ],
            ],
            'lists' => [
                'title' => 'Lookup lists',
                'steps' => [
                    [
                        'target' => 'section[aria-label="Account Types"]',
                        'title' => 'Account types',
                        'body' => 'Type a name such as Residential and click Add. Every customer gets one account type; tick Tax exempt on types billed without tax, e.g. Government.',
                    ],
                    [
                        'target' => 'section[aria-label="Meter Types"]',
                        'title' => 'Meter types',
                        'body' => 'The kinds of meters you install, e.g. 1/2" Brass. Set the number of dials so the system knows when a meter rolls over to zero.',
                    ],
                    [
                        'target' => 'section[aria-label="Cluster Types"]',
                        'title' => 'Cluster types',
                        'body' => 'Areas or routes, e.g. Zone 1. Meters can be read and billed one cluster at a time.',
                    ],
                    [
                        'target' => 'section[aria-label="Departments"]',
                        'title' => 'Departments (optional)',
                        'body' => 'Departments your staff belong to, e.g. Billing or Field Operations.',
                    ],
                ],
            ],
            'rates' => [
                'title' => 'Water rates',
                'steps' => [
                    [
                        'target' => '[wire\:submit="saveRateType"]',
                        'title' => 'Add a rate type first',
                        'body' => 'Type a name such as Residential (the description is optional), then click Add Rate Type. It appears in the table below.',
                        'grows' => '[wire\:key^="rate-type-"]',
                    ],
                    [
                        'target' => '[wire\:key^="rate-type-"]',
                        'title' => "Click your rate type's row",
                        'body' => 'Click its row in the table to select it. The Rate Setup panel on the right stays locked until a rate type is selected.',
                        'until' => '#minimum-consumption:not([disabled])',
                    ],
                    [
                        'target' => '[wire\:submit="saveSetup"]',
                        'title' => 'Minimum consumption',
                        'body' => 'Customers who use less than this are still billed for it, e.g. 10 cu.m. Under Defective Computation choose how to bill when a meter is broken. Click Save.',
                    ],
                    [
                        'target' => '[wire\:submit="addRange"]',
                        'title' => 'Add consumption ranges',
                        'body' => 'Add one range at a time, starting from the lowest usage. Enter where it ends in To (e.g. 10) and the price per cu.m. in Rate per count (e.g. 10.00), then click Add Range. The next range starts right after the last one.',
                        'grows' => '[wire\:key^="range-"]',
                    ],
                    [
                        'target' => 'section[aria-labelledby="rate-setup-heading"] table',
                        'title' => 'Check your price table',
                        'body' => 'Keep adding ranges, e.g. 11–20 at 25.00 and 21–99999 at 35.00; end the last range high enough for any usage. A 15 cu.m. bill is then 10 × 10.00 + 5 × 25.00 = 225.00.',
                    ],
                ],
            ],
            'charges' => [
                'title' => 'Charges and fees',
                'steps' => [
                    [
                        'target' => '[wire\:submit="saveChargeType"]',
                        'title' => 'Add a charge type',
                        'body' => 'A charge type is a bundle of extra charges added to bills, and every customer is given one. Type a name such as Standard and click Add Charge Type.',
                        'grows' => '[wire\:key^="charge-type-"]',
                    ],
                    [
                        'target' => '[wire\:submit="addElement"]',
                        'title' => 'Add charge elements',
                        'body' => 'Each element is one charge, e.g. Late Payment Surcharge. Tick "after due" if it only applies to late bills, and "Taxable" if tax applies to it. Distribution Type: Credit adds to the bill, Debit deducts from it (e.g. a senior discount). Click Add Charge Element.',
                        'grows' => '[wire\:key^="element-"]',
                    ],
                    [
                        'target' => '[aria-label^="Set up charge elements for"]',
                        'title' => 'Open the charge type setup',
                        'body' => "Click the gear icon on your charge type's row to choose which elements it includes.",
                        'until' => '[wire\:submit="addSetupElement"]',
                    ],
                    [
                        'target' => '[wire\:submit="addSetupElement"]',
                        'title' => 'Add an element to the charge type',
                        'body' => 'Pick a charge element, choose Fixed Amount or Percentage, and enter the value, e.g. 2 for 2%. Its distribution type shows by itself. Click ADD, and repeat for each element.',
                        'grows' => '[wire\:key^="setup-row-"]',
                    ],
                    [
                        'target' => '.modal-dialog table',
                        'title' => 'Elements in this charge type',
                        'body' => 'Everything added is listed here; use the icons to change a value or remove an element, then close the window. Customers with this charge type get these charges on their bills. Work order fees, like the installation fee, are set on the Work Orders page under Fees.',
                    ],
                ],
            ],
            'team' => [
                'title' => 'Your team',
                'steps' => [
                    ...$roleSteps,
                    [
                        'target' => '[wire\:click="openAdd"]',
                        'title' => 'Invite a user',
                        'body' => 'Click Add User, enter their name and email, and pick their role. They get an email to set their password, and can sign in once setup is finished.',
                    ],
                ],
            ],
            'roles' => [
                'title' => 'Roles',
                'steps' => [
                    ...$roleSteps,
                    [
                        'target' => '[aria-label^="Change access to"]',
                        'title' => 'Access list',
                        'body' => 'Each page the role can open is listed with what it may do there. Use the icons to change or remove access.',
                    ],
                ],
            ],
            'users' => [
                'title' => 'Users',
                'steps' => [
                    [
                        'target' => '[wire\:click="openAdd"]',
                        'title' => 'Invite a user',
                        'body' => 'Click Add User, enter their name and email, and pick their department and role. They get an email invitation to set a password.',
                    ],
                    [
                        'target' => '[wire\:click^="openUser("]',
                        'title' => 'Manage a user',
                        'body' => 'Click the pencil on a user to change their role, deactivate them, resend their invitation, or give temporary special access to a page.',
                    ],
                ],
            ],
            'customers' => [
                'title' => 'Customers',
                'steps' => [
                    [
                        'target' => '[wire\:click="openAdd"]',
                        'title' => 'Add a customer',
                        'body' => 'Click Add Customer to open the form.',
                        'until' => '#customer-firstName',
                    ],
                    [
                        'target' => '#customer-firstName',
                        'title' => 'Name and contact',
                        'body' => "Enter the customer's first and last name. Company, email and phone are optional; the email receives notices.",
                    ],
                    [
                        'target' => ':has(> div > #customer-accountTypeId)',
                        'title' => 'How they are billed',
                        'body' => 'Account type, rate and charge type decide how the customer is billed; the cluster groups them by area. A new customer stays Pending until their meter is installed.',
                    ],
                    [
                        'target' => 'section[aria-labelledby="customer-home-heading"]',
                        'title' => 'Home address',
                        'body' => 'Where the meter is. If bills go to the same place, click "Same as Home Address" in the billing address.',
                    ],
                    [
                        'target' => '[aria-labelledby="customer-location-label"]',
                        'title' => 'Map location (optional)',
                        'body' => 'Search the map or drag the pin onto the house, so field staff can find the meter.',
                    ],
                    [
                        'target' => '[wire\:submit="save"] [type="submit"]',
                        'title' => 'Save the customer',
                        'body' => 'Click Submit. The customer appears in the table with an account number. Click the pencil on their row later to update them.',
                    ],
                ],
            ],
            'work-order-create' => [
                'title' => 'New work order',
                'steps' => $workOrderCreate,
            ],
            'work-order-complete' => [
                'title' => 'Complete a work order',
                'steps' => [
                    [
                        'target' => '[aria-label^="Update work order"]',
                        'title' => 'Open the work order',
                        'body' => "Click the pencil icon on the work order's row.",
                        'until' => '[wire\:click^="openComplete("], [wire\:submit="complete"]',
                    ],
                    [
                        'target' => '[wire\:click^="openComplete("]',
                        'title' => 'Click Complete',
                        'body' => 'Use Complete once the job is done in the field.',
                        'until' => '[wire\:submit="complete"]',
                    ],
                    [
                        'target' => '[wire\:model="completion.fields.meter_number"]',
                        'title' => 'What was done',
                        'body' => 'Enter the meter number and serial number of the meter installed, its type, and the reading on its dials now (usually 0). The fee is filled in from your work order fees.',
                    ],
                    [
                        'target' => 'fieldset fieldset:has([wire\:model^="completion.checked"])',
                        'title' => 'Checklist',
                        'body' => 'Tick every item to confirm the work was done properly.',
                    ],
                    [
                        'target' => 'fieldset fieldset:has([wire\:model="newPhotos"])',
                        'title' => 'Photo',
                        'body' => 'Attach a photo of the installed meter; New Installations need one.',
                    ],
                    [
                        'target' => '[wire\:submit="complete"] [type="submit"]',
                        'title' => 'Complete it',
                        'body' => 'Click Complete Work Order. The customer becomes Connected, and the installation fee is billed on its own invoice.',
                    ],
                ],
            ],
            'work-orders' => [
                'title' => 'Work orders',
                'steps' => [
                    ...$workOrderCreate,
                    [
                        'target' => '[aria-label^="Update work order"]',
                        'title' => 'Completing a job',
                        'body' => 'When the work is done, click the pencil on its row and then Complete. Use the Fees link above the form to set default fees per order type, e.g. ₱1,500 for New Installation.',
                    ],
                ],
            ],
            'payments' => [
                'title' => 'Payments',
                'steps' => [
                    [
                        'target' => '#payment-customer',
                        'title' => 'Find the customer',
                        'body' => 'Type the account number or name and pick the customer. Their unpaid bills appear on the right.',
                    ],
                    [
                        'target' => '[aria-label="What the customer owes"]',
                        'title' => 'What they owe',
                        'body' => 'Open bills, oldest due first, and how this payment will be applied to them.',
                    ],
                    [
                        'target' => '#payment-amount',
                        'title' => 'Amount and date',
                        'body' => 'What they owe is filled in; change it if they paid less or more. Anything extra is kept as advance credit for their next bill.',
                    ],
                    [
                        'target' => '#payment-method',
                        'title' => 'How they paid',
                        'body' => 'Cash, check, bank transfer and so on. Add the reference number for non-cash payments.',
                    ],
                    [
                        'target' => '[wire\:submit="record"] [type="submit"]',
                        'title' => 'Record the payment',
                        'body' => 'Click Record Payment, then click its receipt in the table below to print the official receipt.',
                        'grows' => '[aria-label^="View receipt"]',
                    ],
                ],
            ],
            'meter-readings' => [
                'title' => 'Meter readings',
                'steps' => [
                    [
                        'target' => '#mr-period',
                        'title' => 'Billing month',
                        'body' => "Choose the month you're reading meters for.",
                    ],
                    [
                        'target' => '#mr-read-on',
                        'title' => 'Read on',
                        'body' => 'The date the meters were read in the field.',
                    ],
                    [
                        'target' => '#mr-cluster',
                        'title' => 'Cluster',
                        'body' => 'Narrow the list to one area or route, the way your meter readers walk it.',
                    ],
                    [
                        'target' => '[wire\:click^="select("]',
                        'title' => 'Pick a meter',
                        'body' => "Click a customer's row to open their reading panel.",
                        'until' => '#mr-present',
                    ],
                    [
                        'target' => '#mr-present',
                        'title' => 'Enter the present reading',
                        'body' => 'Type the number on the meter. The usage and bill preview appear below it, and unusual usage is flagged for review. Click Save and next to move to the next meter.',
                    ],
                    [
                        'target' => 'a[href*="/invoices/generate"]',
                        'title' => 'Generate the bills',
                        'body' => "When the month's readings are in, generate the bills from here.",
                    ],
                ],
            ],
            'invoices' => [
                'title' => 'Invoices',
                'steps' => [
                    [
                        'target' => 'a[href*="/invoices/generate"]',
                        'title' => 'Generate bills',
                        'body' => 'Bills everyone with an approved reading for a month.',
                    ],
                    [
                        'target' => '#invoice-status-filter',
                        'title' => 'Find invoices',
                        'body' => 'Filter by status or billing month, or search by customer.',
                    ],
                    [
                        'target' => '[aria-label^="View invoice"]',
                        'title' => 'Open an invoice',
                        'body' => 'Click the icon to view or print an invoice, or correct it with a debit or credit memo.',
                    ],
                    [
                        'target' => '[wire\:click="openSettings"]',
                        'title' => 'Billing settings',
                        'body' => 'Choose how unpaid balances carry over to the next bill.',
                    ],
                ],
            ],
            'generate-bills' => [
                'title' => 'Generate bills',
                'steps' => [
                    [
                        'target' => '#bill-period',
                        'title' => 'Billing month',
                        'body' => 'The month whose readings you are billing.',
                    ],
                    [
                        'target' => '#bill-invoice-date',
                        'title' => 'Invoice date',
                        'body' => 'Printed on the bills; the due date counts from it.',
                    ],
                    [
                        'target' => '#bill-cluster',
                        'title' => 'Cluster',
                        'body' => 'Bill one area at a time, or all of them.',
                    ],
                    [
                        'target' => '[wire\:click="generate"]',
                        'title' => 'Generate',
                        'body' => 'Check the counts first: only meters with approved readings are billed, and readings that are unread or waiting for review are listed with links. Then click Generate.',
                    ],
                ],
            ],
            'disconnections' => [
                'title' => 'Disconnections',
                'steps' => [
                    [
                        'target' => '[wire\:click^="$set(\'tab\'"]',
                        'title' => 'Tabs',
                        'body' => 'Eligible lists customers with too many overdue bills; Notices shows notices issued and their deadlines; Exempt lists customers on hold.',
                    ],
                    [
                        'target' => '#dc-cluster',
                        'title' => 'Cluster',
                        'body' => 'Work one area at a time.',
                    ],
                    [
                        'target' => '[wire\:click="issueNotices"]',
                        'title' => 'Issue notices',
                        'body' => 'Tick customers (or Select all) and click Issue notices. Each gets a notice of disconnection with a deadline; print them to deliver.',
                    ],
                    [
                        'target' => '[wire\:click="createWorkOrders"]',
                        'title' => 'After the deadline',
                        'body' => 'On the Notices tab, customers still unpaid after their deadline can be given a Disconnection work order, assigned to your field staff.',
                    ],
                ],
            ],
            'company' => [
                'title' => 'Company setup',
                'steps' => [
                    [
                        'target' => '[role="tablist"]',
                        'title' => 'Company setup tabs',
                        'body' => 'Company Profile holds your details and logo. Operation Setup holds billing settings, tax, review and disconnection rules, and the lookup lists.',
                    ],
                    [
                        'target' => '#company-name',
                        'title' => 'Edit and save',
                        'body' => 'Change any field and click Save Profile. Bills printed from now on show the new details.',
                    ],
                ],
            ],
        ];
    }
}
