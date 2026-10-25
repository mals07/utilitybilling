<?php

namespace Database\Seeders;

use App\Enums\Access;
use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentType;
use App\Enums\ChargeValueType;
use App\Enums\CustomerStatus;
use App\Enums\DistributionType;
use App\Enums\FeeBilling;
use App\Enums\MeterReadingStatus;
use App\Enums\OverdueHandling;
use App\Enums\Page;
use App\Enums\PaymentMethod;
use App\Enums\ReadingCode;
use App\Enums\ReadingKind;
use App\Enums\WorkOrderStatus;
use App\Enums\WorkOrderType;
use App\Models\City;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DisconnectionNotice;
use App\Models\Payment;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderReading;
use App\Support\AuthEmails;
use App\Support\Billing\Adjustments;
use App\Support\Billing\BillingRun;
use App\Support\Billing\Ledger;
use App\Support\Billing\MeterReadings;
use App\Support\Billing\Surcharges;
use App\Support\Disconnections;
use App\Support\Money;
use App\Support\WorkOrderCompletion;
use App\Support\WorkOrderRules;
use Closure;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A fully set-up water district with 50 customers and three months of billing, for user testing:
 * July–September 2026 billed, October readings partly taken and not billed yet.
 *
 * Everything after the setup goes through the app's own services (BillingRun, Ledger, Adjustments,
 * WorkOrderCompletion, Disconnections), replaying the days in order from June 1 to October 21 and
 * running the nightly surcharge and disconnection-review jobs on each day, so balances, surcharges,
 * tax and notices are what the app itself would have produced.
 *
 * Run on its own database (PowerShell):
 *   $env:DB_DATABASE='utility_billing_test'; php artisan migrate:fresh --seeder=TestDataSeeder
 * Every user signs in with self::PASSWORD; the admin is admin@aquavista.test (username "admin").
 */
class TestDataSeeder extends Seeder
{
    public const PASSWORD = 'Test@12345';

    public const ADMIN_EMAIL = 'admin@aquavista.test';

    private const TIMEZONE = 'Asia/Manila';

    private const BILLED_PERIODS = ['2026-07', '2026-08', '2026-09'];

    /** The last day replayed; payments that would fall after it are left unpaid. */
    private const LAST_DAY = '2026-10-21';

    /**
     * Staff: key => [first name, last name, role, department].
     */
    private const STAFF = [
        'liza' => ['Liza', 'Reyes', 'Billing Supervisor', 'Billing'],
        'paolo' => ['Paolo', 'Garcia', 'Billing Clerk', 'Billing'],
        'joy' => ['Joy', 'Navarro', 'Billing Clerk', 'Billing'],
        'grace' => ['Grace', 'Lim', 'Cashier', 'Collection'],
        'mark' => ['Mark', 'Torres', 'Cashier', 'Collection'],
        'ben' => ['Ben', 'Aquino', 'Meter Reader', 'Field Operations'],
        'rico' => ['Rico', 'Bautista', 'Meter Reader', 'Field Operations'],
        'dan' => ['Dan', 'Ramos', 'Field Technician', 'Field Operations'],
        'noel' => ['Noel', 'Castillo', 'Field Technician', 'Field Operations'],
        'arnel' => ['Arnel', 'Pascual', 'Field Technician', 'Field Operations'],
        'kim' => ['Kim', 'Flores', 'Customer Service', 'Customer Service'],
        'teresa' => ['Teresa', 'Gomez', 'Auditor', 'Administration'],
        'victor' => ['Victor', 'Salazar', 'Field Technician', 'Field Operations'],
    ];

    /**
     * Roles: name => [description, [page => access letters (v)iew (a)dd (e)dit (d)elete]]; "*" is every page.
     */
    private const ROLES = [
        'Billing Supervisor' => ['Oversees billing, collections and disconnections', [
            'customers' => 'vaed', 'work-orders' => 'vae', 'meter-readings' => 'vaed', 'invoices' => 'vaed',
            'payments' => 'vaed', 'disconnections' => 'vaed', 'rates' => 'v', 'charges' => 'v',
        ]],
        'Billing Clerk' => ['Takes readings to bills', [
            'customers' => 'vae', 'meter-readings' => 'vae', 'invoices' => 'va', 'payments' => 'v', 'work-orders' => 'v',
        ]],
        'Cashier' => ['Posts payments at the office', ['payments' => 'va', 'invoices' => 'v', 'customers' => 'v']],
        'Meter Reader' => ['Reads meters in the field', ['meter-readings' => 'va', 'customers' => 'v']],
        'Field Technician' => ['Does the field work on work orders', ['work-orders' => 've', 'customers' => 'v']],
        'Customer Service' => ['Front desk: new applications and complaints', [
            'customers' => 'vae', 'work-orders' => 'va', 'invoices' => 'v', 'payments' => 'v', 'disconnections' => 'v',
        ]],
        'Auditor' => ['Reviews records; view only', ['*' => 'v']],
    ];

    /**
     * key => [first name, last name, company, account type, charge type, rate, zone, meter type, payment profile, usual cu.m. a month, has email].
     *
     * Payment profiles: prompt (pays before the due date), late (pays after it, surcharged), partial (pays 60%),
     * advance (pays ahead; scheduled below), sep_unpaid (September bill not paid), arrears1 (only July paid),
     * arrears2 (nothing paid), new (installed during the period), pending (no meter yet).
     */
    private const CUSTOMERS = [
        // Zone 1 – Poblacion
        'c01' => ['Juan', 'Dela Cruz', null, 'res', 'standard', 'res', 1, 'half', 'prompt', 18, true],
        'c02' => ['Maria', 'Santos', null, 'res', 'standard', 'res', 1, 'half', 'prompt', 15, true],
        'c03' => ['Antonio', 'Reyes', null, 'res', 'standard', 'res', 1, 'half', 'sep_unpaid', 22, true],
        'c04' => ['Rosario', 'Garcia', 'Barangay Poblacion Hall', 'gov', 'government', 'gov', 1, 'threeq', 'prompt', 70, true],
        'c05' => ['Lourdes', 'Mendoza', null, 'res', 'senior', 'res', 1, 'half', 'prompt', 12, true],
        'c06' => ['Eduardo', 'Bautista', null, 'res', 'standard', 'res', 1, 'half', 'prompt', 20, true],
        'c07' => ['Teresita', 'Aquino', null, 'res', 'senior', 'lifeline', 1, 'half', 'prompt', 9, true],
        'c08' => ['Ricardo', 'Flores', null, 'res', 'standard', 'res', 1, 'half', 'prompt', 25, true],
        'c09' => ['Ana', 'Villanueva', 'Villanueva Dental Clinic', 'com', 'commercial', 'com', 1, 'threeq', 'sep_unpaid', 38, true],
        'c10' => ['Danilo', 'Castro', null, 'res', 'standard', 'res', 1, 'half', 'prompt', 19, true],
        'c11' => ['Cristina', 'Navarro', null, 'res', 'standard', 'res', 1, 'half', 'prompt', 17, true],
        'c12' => ['Rodel', 'Pascual', 'Pascual Sari-Sari Store', 'com', 'commercial', 'com', 1, 'threeq', 'prompt', 32, true],
        'c13' => ['Melchor', 'Tan', "Tan's Bakery", 'com', 'commercial', 'com', 1, 'threeq', 'late', 55, true],
        // Zone 2 – Marawoy
        'c14' => ['Gloria', 'Lopez', null, 'res', 'standard', 'res', 2, 'half', 'prompt', 16, true],
        'c15' => ['Fernando', 'Gonzales', null, 'res', 'standard', 'res', 2, 'half', 'prompt', 21, true],
        'c16' => ['Norma', 'Diaz', null, 'res', 'standard', 'res', 2, 'half', 'late', 14, true],
        'c17' => ['Alberto', 'Cruz', null, 'res', 'standard', 'res', 2, 'half', 'late', 23, true],
        'c18' => ['Marites', 'Fernandez', null, 'res', 'standard', 'res', 2, 'half', 'partial', 19, true],
        'c19' => ['Rogelio', 'Morales', 'Batangas Ice Plant Corp.', 'ind', 'industrial', 'ind', 2, 'one', 'late', 320, true],
        'c20' => ['Evelyn', 'Ramos', null, 'res', 'standard', 'res', 2, 'half', 'partial', 18, true],
        'c21' => ['Virgilio', 'Torres', 'Marawoy Elementary School', 'gov', 'government', 'gov', 2, 'threeq', 'partial', 95, true],
        'c22' => ['Josefina', 'Castillo', null, 'res', 'standard', 'res', 2, 'half', 'prompt', 20, true],
        'c23' => ['Arturo', 'Sison', null, 'res', 'standard', 'res', 2, 'half', 'partial', 24, true],
        'c24' => ['Remedios', 'Villar', null, 'res', 'standard', 'res', 2, 'half', 'advance', 14, true],
        'c25' => ['Conrado', 'Manalo', 'Lipa Agro Feeds Inc.', 'ind', 'industrial', 'ind', 2, 'two', 'advance', 380, true],
        'c26' => ['Felicidad', 'Ocampo', null, 'res', 'standard', 'res', 2, 'half', 'arrears1', 17, true],
        // Zone 3 – Sabang
        'c27' => ['Rolando', 'Mercado', null, 'res', 'standard', 'res', 3, 'half', 'arrears1', 19, false],
        'c28' => ['Imelda', 'Robles', null, 'res', 'standard', 'res', 3, 'half', 'arrears1', 16, true],
        'c29' => ['Ernesto', 'Salvador', null, 'res', 'standard', 'res', 3, 'half', 'arrears2', 21, true],
        'c30' => ['Leticia', 'Medina', 'Medina Carinderia', 'com', 'commercial', 'com', 3, 'threeq', 'arrears2', 45, true],
        'c31' => ['Bonifacio', 'Galang', null, 'res', 'standard', 'res', 3, 'half', 'arrears2', 18, true],
        'c32' => ['Corazon', 'Dizon', null, 'res', 'standard', 'res', 3, 'half', 'arrears2', 15, false],
        'c33' => ['Herminio', 'Lacson', null, 'res', 'standard', 'res', 3, 'half', 'arrears2', 22, true],
        'c34' => ['Perla', 'Domingo', null, 'res', 'senior', 'res', 3, 'half', 'arrears1', 13, true],
        'c35' => ['Amelia', 'Katigbak', 'Lipa City District Hospital', 'gov', 'government', 'gov', 3, 'one', 'arrears1', 130, true],
        'c36' => ['Santiago', 'Umali', null, 'res', 'standard', 'res', 3, 'half', 'arrears1', 20, false],
        'c37' => ['Lorna', 'Bernardo', null, 'res', 'standard', 'res', 3, 'half', 'prompt', 24, true],
        'c38' => ['Wilfredo', 'Aguilar', null, 'res', 'standard', 'res', 3, 'half', 'prompt', 20, true],
        // Zone 4 – Tambo
        'c39' => ['Nenita', 'Valdez', null, 'res', 'standard', 'res', 4, 'half', 'prompt', 18, true],
        'c40' => ['Gregorio', 'Soriano', 'Soriano Hardware', 'com', 'commercial', 'com', 4, 'threeq', 'prompt', 40, true],
        'c41' => ['Rebecca', 'Panganiban', null, 'res', 'standard', 'res', 4, 'half', 'prompt', 16, true],
        'c42' => ['Alfredo', 'Yap', 'Yap Laundry Services', 'com', 'commercial', 'com', 4, 'threeq', 'prompt', 60, true],
        'c43' => ['Milagros', 'Cabrera', null, 'res', 'senior', 'res', 4, 'half', 'prompt', 11, false],
        'c44' => ['Danilo', 'Estrada', null, 'res', 'standard', 'res', 4, 'half', 'prompt', 18, true],
        // Added during the period
        'c45' => ['Kristine', 'Marasigan', null, 'res', 'standard', 'res', 4, 'half', 'pending', 15, true],
        'c46' => ['Jayson', 'Macaraig', null, 'res', 'standard', 'res', 3, 'half', 'pending', 15, false],
        'c47' => ['Angelica', 'Dimaculangan', null, 'res', 'standard', 'res', 2, 'half', 'new', 15, true],
        'c48' => ['Jerome', 'Malabanan', 'Kapeng Barako Café', 'com', 'commercial', 'com', 1, 'threeq', 'new', 22, true],
        'c49' => ['Ramil', 'Atienza', null, 'res', 'standard', 'res', 4, 'half', 'pending', 15, true],
        'c50' => ['Shiela Mae', 'Recto', null, 'res', 'standard', 'res', 1, 'half', 'pending', 15, true],
    ];

    /** Monthly usage set by a scenario instead of the usual amount: key => [period => cu.m.]. */
    private const USAGE = [
        'c37' => ['2026-07' => 22, '2026-08' => 23, '2026-09' => 21, '2026-10' => 26],
        'c38' => ['2026-07' => 19, '2026-08' => 22],
        'c41' => ['2026-10' => 50],
        'c42' => ['2026-09' => 180],
        'c43' => ['2026-10' => 0],
        'c44' => ['2026-09' => 4, '2026-10' => 5],
        'c47' => ['2026-08' => 9],
        'c48' => ['2026-10' => 22],
    ];

    /** Readings that aren't normal: key => [period => code]. Rolled-over meters are detected. */
    private const READING_CODES = [
        'c39' => ['2026-08' => ReadingCode::NoAccess],
        'c40' => ['2026-09' => ReadingCode::Defective],
        'c44' => ['2026-09' => ReadingCode::NoAccess],
    ];

    /** Initial readings set by a scenario: meters close to rolling over past 9999. */
    private const INITIAL_READINGS = ['c37' => 9915, 'c38' => 9960];

    /** Bills not paid by the customer's profile, paid by a scenario instead (or not at all). */
    private const SKIP_PAYMENT = [
        'c05' => ['2026-09'], 'c06' => ['2026-07'], 'c17' => ['2026-09'], 'c22' => ['2026-09'], 'c42' => ['2026-09'],
    ];

    private const BARANGAYS = [1 => 'Poblacion', 2 => 'Marawoy', 3 => 'Sabang', 4 => 'Tambo'];

    private const STREETS = ['Rizal St.', 'Mabini St.', 'C.M. Recto Ave.', 'Burgos St.', 'Luna St.', 'Del Pilar St.', 'Bonifacio St.', 'P. Torres St.', 'Aguinaldo Hwy.', 'Sampaguita St.', 'Narra St.', 'Acacia St.'];

    private Carbon $clock;

    private Company $company;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, array<string|int, int>> Lookup ids: accountTypes, chargeTypes, rates, zones, meterTypes, departments, roles. */
    private array $ids = [];

    /** @var array<string, int> Customer key => id. */
    private array $customerIds = [];

    /** @var array<string, int> What each customer's meter shows now, kept as the days are replayed. */
    private array $meters = [];

    /** @var array<string, array<string, int>> Usual usage: key => [period => cu.m.]. */
    private array $usage = [];

    /** @var array<string, PaymentMethod> */
    private array $paymentMethods = [];

    /** @var array<string, WorkOrder> Work orders a later event needs. */
    private array $orders = [];

    /** @var array<string, DisconnectionNotice> */
    private array $notices = [];

    /** @var list<array{0: Carbon, 1: int, 2: Closure}> */
    private array $events = [];

    public function run(): void
    {
        $this->call(LocationSeeder::class);

        if (User::query()->where('email', self::ADMIN_EMAIL)->exists()) {
            $this->command?->warn('The test company already exists. Run this seeder on a fresh database (migrate:fresh).');

            return;
        }

        mt_srand(20261021);
        $this->clock = Carbon::parse('2026-06-01 08:00', self::TIMEZONE);
        Carbon::setTestNow($this->clock);

        try {
            $this->setUpCompany();
            $this->scheduleCustomers();
            $this->scheduleBillingCycles();
            $this->schedulePayments();
            $this->scheduleWorkOrders();
            $this->scheduleBillingCorrections();
            $this->scheduleDisconnections();
            $this->scheduleUsers();
            $this->replay();
        } finally {
            Carbon::setTestNow();
        }

        $this->report();
    }

    /* ------------------------------------------------------------------ Setup ------------------------------------------------------------------ */

    private function setUpCompany(): void
    {
        $lipa = $this->city('Batangas', 'Lipa');

        $admin = User::query()->create([
            'name' => 'Carlo Mendoza', 'first_name' => 'Carlo', 'last_name' => 'Mendoza',
            'email' => self::ADMIN_EMAIL, 'username' => 'admin', 'password' => self::PASSWORD,
            'email_verified_at' => now(), 'is_admin' => true, 'last_login_at' => '2026-10-21 07:45:00', 'tour_completed_at' => now(),
        ]);
        $this->users['admin'] = $admin;

        $this->company = Company::query()->create([
            'user_id' => $admin->id,
            'company_name' => 'Aqua Vista Water District',
            'address' => '123 C.M. Recto Ave., Brgy. Poblacion',
            'city_id' => $lipa->id, 'province_id' => $lipa->province_id, 'country_id' => $lipa->province->country_id,
            'zip_code' => '4217',
            'email' => 'office@aquavista.test',
            'tax_id_number' => '123-456-789-000',
            'phone_number' => '(043) 756-1234',
            'company_email' => 'billing@aquavista.test',
            'website' => 'https://aquavista.test',
            'logo_data' => $this->image(240, 240, [14, 99, 156], 'AQUA VISTA'), 'logo_mime_type' => 'image/png', 'logo_file_name' => 'aqua-vista-logo.png',
            'icon_data' => $this->image(64, 64, [14, 99, 156], 'AV'), 'icon_mime_type' => 'image/png', 'icon_file_name' => 'aqua-vista-icon.png',
            'head_of_company' => 'Engr. Roberto M. Salcedo',
            'point_person_first_name' => 'Carlo', 'point_person_last_name' => 'Mendoza',
            'point_person_contact' => '0917 812 3456', 'point_person_email' => self::ADMIN_EMAIL,
            'employee_number' => 'AV-0001', 'employee_title' => 'Billing Systems Administrator',
            'company_header_line' => 'Serving Lipa City since 1978',
            'billing_header_1' => 'AQUA VISTA WATER DISTRICT',
            'billing_header_2' => 'C.M. Recto Ave., Brgy. Poblacion, Lipa City, Batangas 4217',
            'billing_header_3' => 'Tel. (043) 756-1234 · billing@aquavista.test',
            'billing_footer_1' => 'Please pay on or before the due date to avoid a 10% surcharge.',
            'billing_footer_2' => 'Two unpaid bills past their due date may lead to disconnection.',
            'billing_footer_3' => 'Pay at our office, by GCash/Maya, or by bank transfer to BPI 1234-5678-90.',
            'due_date_days' => 15,
            'currency' => 'PHP',
            'timezone' => self::TIMEZONE,
            'overdue_handling' => OverdueHandling::SameInvoice,
            'tax_enabled' => true, 'tax_name' => 'VAT', 'tax_rate' => '12.00', 'tax_inclusive' => true,
            'tax_on_water' => true, 'tax_on_fees' => true, 'tax_on_surcharges' => false,
            'review_usage_multiplier' => '2.0', 'review_zero_usage' => true,
            'disconnection_overdue_bills' => 2, 'disconnection_notice_days' => 5, 'disconnection_skip_days' => '6,7',
            'setup_step' => null, 'setup_completed_at' => now(),
        ]);
        $admin->update(['company_id' => $this->company->id]);

        $this->setUpLists();
        $this->setUpRates();
        $this->setUpCharges();
        $this->setUpWorkOrderFees();
        $this->setUpRolesAndStaff();
    }

    private function setUpLists(): void
    {
        $company = $this->company;

        foreach ([
            'res' => ['Residential', true, false], 'com' => ['Commercial', true, false], 'ind' => ['Industrial', true, false],
            'gov' => ['Government', true, true], 'bulk' => ['Bulk Water Buyer (discontinued)', false, false],
        ] as $key => [$name, $active, $exempt]) {
            $this->ids['accountTypes'][$key] = $company->accountTypes()->create(['name' => $name, 'is_active' => $active, 'is_tax_exempt' => $exempt])->id;
        }

        foreach ([
            'half' => ['1/2" Brass Meter', true, 4], 'threeq' => ['3/4" Brass Meter', true, 5], 'one' => ['1" Multi-Jet Meter', true, 6],
            'two' => ['2" Turbine Meter', true, null], 'plastic' => ['1/2" Plastic Meter (phased out)', false, 4],
        ] as $key => [$name, $active, $dials]) {
            $this->ids['meterTypes'][$key] = $company->meterTypes()->create(['name' => $name, 'is_active' => $active, 'dial_digits' => $dials])->id;
        }

        foreach (self::BARANGAYS as $zone => $barangay) {
            $this->ids['zones'][$zone] = $company->clusterTypes()->create(['name' => "Zone {$zone} – {$barangay}", 'is_active' => true])->id;
        }
        $company->clusterTypes()->create(['name' => 'Zone 5 – Antipolo del Norte (merged into Zone 4)', 'is_active' => false]);

        foreach (['Billing', 'Collection', 'Field Operations', 'Customer Service', 'Administration'] as $name) {
            $this->ids['departments'][$name] = $company->departments()->create(['name' => $name])->id;
        }
    }

    private function setUpRates(): void
    {
        // [name, description, minimum, defective uses minimum, defective uses 3-month average, [[from, to, rate, description]]]
        $rates = [
            'res' => ['Residential', 'Households', 10, true, true, [[0, 10, '22.50', 'First 10 cu.m.'], [11, 20, '28.00', '11–20 cu.m.'], [21, 30, '33.50', '21–30 cu.m.'], [31, 99999, '40.00', 'Above 30 cu.m.']]],
            'lifeline' => ['Lifeline (Social)', 'Low-income and senior households; no minimum', null, false, true, [[0, 10, '15.00', 'First 10 cu.m.'], [11, 99999, '28.00', 'Above 10 cu.m.']]],
            'com' => ['Commercial', 'Shops, restaurants and offices', 15, true, false, [[0, 15, '45.00', 'First 15 cu.m.'], [16, 50, '52.00', '16–50 cu.m.'], [51, 99999, '60.00', 'Above 50 cu.m.']]],
            'ind' => ['Industrial', 'Factories and plants; estimated from the 3-month average only', 100, false, true, [[0, 100, '55.00', 'First 100 cu.m.'], [101, 500, '62.00', '101–500 cu.m.'], [501, 999999, '70.00', 'Above 500 cu.m.']]],
            'gov' => ['Government', 'Government offices, schools and hospitals', 20, true, true, [[0, 20, '30.00', 'First 20 cu.m.'], [21, 99999, '36.00', 'Above 20 cu.m.']]],
            'bulk' => ['Bulk Water Sales', 'Tanker deliveries; not used by any customer yet', 50, true, false, [[0, 50, '65.00', 'First 50 cu.m.'], [51, 99999, '75.00', 'Above 50 cu.m.']]],
        ];

        foreach ($rates as $key => [$name, $description, $minimum, $usesMinimum, $usesAverage, $ranges]) {
            $rate = $this->company->rateTypes()->create(['name' => $name, 'description' => $description]);
            $rate->ranges()->createMany(array_map(
                fn (array $range): array => ['range_from' => $range[0], 'range_to' => $range[1], 'rate' => $range[2], 'description' => $range[3]],
                $ranges,
            ));
            $rate->setup()->create([
                'minimum_consumption' => $minimum,
                'defective_uses_minimum_consumption' => $usesMinimum,
                'defective_uses_three_month_average' => $usesAverage,
            ]);
            $this->ids['rates'][$key] = $rate->id;
        }
    }

    private function setUpCharges(): void
    {
        // [name, distribution, after due, taxable]
        $elements = [
            'env' => ['Environmental Fee', DistributionType::Credit, false, true],
            'mmf' => ['Meter Maintenance Fee', DistributionType::Credit, false, false],
            'senior' => ['Senior Citizen Discount', DistributionType::Debit, false, true],
            'surcharge' => ['Late Payment Surcharge', DistributionType::Credit, true, false],
            'penalty' => ['Late Payment Penalty', DistributionType::Credit, true, false],
            'septage' => ['Septage Fee', DistributionType::Credit, false, true],
        ];
        $elementIds = [];
        foreach ($elements as $key => [$name, $distribution, $afterDue, $taxable]) {
            $elementIds[$key] = $this->company->chargeElements()->create([
                'name' => $name, 'distribution_type' => $distribution, 'is_after_due' => $afterDue, 'is_taxable' => $taxable,
            ])->id;
        }

        // [name, description, [element => [percentage?, value]]]; the Septage Fee isn't used yet.
        $types = [
            'standard' => ['Standard', 'Most residential customers', ['env' => [true, '10.00'], 'mmf' => [false, '15.00'], 'surcharge' => [true, '10.00']]],
            'senior' => ['Senior Citizen', 'Residential, with the 5% senior citizen discount', ['env' => [true, '10.00'], 'mmf' => [false, '15.00'], 'senior' => [true, '5.00'], 'surcharge' => [true, '10.00']]],
            'commercial' => ['Commercial', 'Shops and offices; surcharge plus a fixed penalty', ['env' => [true, '10.00'], 'mmf' => [false, '50.00'], 'surcharge' => [true, '10.00'], 'penalty' => [false, '100.00']]],
            'industrial' => ['Industrial', 'Plants and factories', ['env' => [true, '15.00'], 'mmf' => [false, '150.00'], 'surcharge' => [true, '10.00'], 'penalty' => [false, '500.00']]],
            'government' => ['Government', 'No late payment charges', ['env' => [true, '10.00'], 'mmf' => [false, '25.00']]],
        ];
        foreach ($types as $key => [$name, $description, $rows]) {
            $type = $this->company->chargeTypes()->create(['name' => $name, 'description' => $description]);
            foreach ($rows as $element => [$percentage, $value]) {
                $type->elements()->create([
                    'charge_element_id' => $elementIds[$element],
                    'value_type' => $percentage ? ChargeValueType::Percentage : ChargeValueType::Fixed,
                    'value' => $value,
                ]);
            }
            $this->ids['chargeTypes'][$key] = $type->id;
        }
    }

    private function setUpWorkOrderFees(): void
    {
        $fees = [
            [WorkOrderType::NewInstallation, '3500.00', FeeBilling::SeparateInvoice],
            [WorkOrderType::Reconnection, '300.00', FeeBilling::SeparateInvoice],
            [WorkOrderType::Disconnection, null, null],
            [WorkOrderType::MeterReplacement, '1200.00', FeeBilling::NextBill],
            [WorkOrderType::MeterRepair, '500.00', FeeBilling::NextBill],
            [WorkOrderType::MeterRelocation, '800.00', FeeBilling::NextBill],
            [WorkOrderType::MeterReadingVerification, null, null],
            [WorkOrderType::LeakRepair, '400.00', FeeBilling::NextBill],
            [WorkOrderType::PipeRepair, '600.00', FeeBilling::NextBill],
            [WorkOrderType::LowWaterPressure, null, null],
            [WorkOrderType::WaterQualityComplaint, null, null],
            [WorkOrderType::BillingComplaint, null, null],
            // Billed on its own invoice, unlike the type's default.
            [WorkOrderType::ChangeOfOwnership, '250.00', FeeBilling::SeparateInvoice],
        ];

        foreach ($fees as [$type, $amount, $billing]) {
            $this->company->workOrderFees()->create(['order_type' => $type, 'amount' => $amount, 'billing' => $billing]);
        }
    }

    private function setUpRolesAndStaff(): void
    {
        foreach (self::ROLES as $name => [$description, $pages]) {
            $role = $this->company->roles()->create(['name' => $name, 'description' => $description]);
            $grants = isset($pages['*'])
                ? array_fill_keys(array_map(fn (Page $page): string => $page->value, Page::cases()), $pages['*'])
                : $pages;

            foreach ($grants as $page => $letters) {
                $flags = [];
                foreach (Access::cases() as $access) {
                    $flags[$access->column()] = str_contains($letters, $access->value[0]);
                }
                $role->permissions()->create(['page' => $page, ...$flags]);
            }
            $this->ids['roles'][$name] = $role->id;
        }

        $this->company->update(['work_order_assignee_role_id' => $this->ids['roles']['Field Technician']]);

        foreach (self::STAFF as $key => [$first, $last, $role, $department]) {
            $this->users[$key] = User::query()->create([
                'name' => "{$first} {$last}", 'first_name' => $first, 'last_name' => $last,
                'email' => strtolower("{$first}.{$last}@aquavista.test"), 'username' => strtolower("{$first}.{$last}"),
                'password' => self::PASSWORD, 'email_verified_at' => now(), 'is_admin' => false,
                'company_id' => $this->company->id, 'role_id' => $this->ids['roles'][$role], 'department_id' => $this->ids['departments'][$department],
                'last_login_at' => Carbon::parse('2026-10-'.mt_rand(10, 20).' '.mt_rand(7, 17).':'.mt_rand(10, 59), self::TIMEZONE),
                // Kim hasn't taken the tour yet, so it shows on her first sign-in.
                'tour_completed_at' => $key === 'kim' ? null : now(),
            ]);
        }

        // Covering for a cashier on leave; an earlier grant that ended; one that hasn't started.
        $this->users['ben']->specialAccess()->create(['page' => Page::Payments->value, 'can_view' => true, 'can_add' => true, 'can_edit' => false, 'can_delete' => false, 'start_date' => '2026-10-15', 'end_date' => '2026-10-31']);
        $this->users['joy']->specialAccess()->create(['page' => Page::Disconnections->value, 'can_view' => true, 'can_add' => true, 'can_edit' => true, 'can_delete' => false, 'start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
        $this->users['kim']->specialAccess()->create(['page' => Page::MeterReadings->value, 'can_view' => true, 'can_add' => true, 'can_edit' => false, 'can_delete' => false, 'start_date' => '2026-11-01', 'end_date' => '2026-11-15']);
    }

    private function scheduleUsers(): void
    {
        $this->on('2026-09-30 17:00', fn () => $this->users['victor']->update(['deactivated_at' => now()]));

        // Invited but hasn't accepted: the invitation link is in the email outbox.
        $this->on('2026-10-18 10:00', function (): void {
            $ivy = User::query()->create([
                'name' => 'Ivy Morales', 'first_name' => 'Ivy', 'last_name' => 'Morales', 'email' => 'ivy.morales@aquavista.test',
                'is_admin' => false, 'company_id' => $this->company->id, 'role_id' => $this->ids['roles']['Cashier'],
                'department_id' => $this->ids['departments']['Collection'], 'invited_at' => now(),
            ]);
            AuthEmails::sendInvitation($ivy, $this->company->company_name);
        });
    }

    /* ---------------------------------------------------------------- Customers ---------------------------------------------------------------- */

    private function scheduleCustomers(): void
    {
        $methods = [PaymentMethod::Cash, PaymentMethod::Cash, PaymentMethod::EWallet, PaymentMethod::BankTransfer, PaymentMethod::Cash, PaymentMethod::Check];
        foreach (self::CUSTOMERS as $key => $spec) {
            foreach ([...self::BILLED_PERIODS, '2026-10'] as $period) {
                $this->usage[$key][$period] = self::USAGE[$key][$period] ?? (int) round($spec[9] * mt_rand(80, 122) / 100);
            }
            $this->paymentMethods[$key] = in_array($spec[3], ['com', 'ind', 'gov'], true)
                ? [PaymentMethod::BankTransfer, PaymentMethod::Check][mt_rand(0, 1)]
                : $methods[mt_rand(0, count($methods) - 1)];
        }

        // Customers carried over from the previous system when the company went live.
        $this->on('2026-06-15 09:00', function (): void {
            foreach (self::CUSTOMERS as $key => $spec) {
                if (! in_array($spec[8], ['pending', 'new'], true)) {
                    $this->migrateCustomer($key);
                }
            }

            Disconnections::exempt($this->customer('c35'), 'Public hospital: essential service', null, $this->users['admin']);
            Disconnections::exempt($this->customer('c26'), 'Medical hardship, approved by the General Manager', '2026-09-30', $this->users['liza']);
        });

        // New applications during the period.
        $this->on('2026-07-20 10:00', fn () => $this->addCustomer('c47'));
        $this->on('2026-08-20 11:00', fn () => $this->addCustomer('c49'));
        $this->on('2026-09-28 10:00', fn () => $this->addCustomer('c48'));
        $this->on('2026-10-05 13:00', fn () => $this->addCustomer('c46'));
        $this->on('2026-10-12 09:30', fn () => $this->addCustomer('c45'));
        $this->on('2026-10-21 08:45', fn () => $this->addCustomer('c50'));
    }

    private function addCustomer(string $key): Customer
    {
        [$first, $last, $companyName, $account, $chargeType, $rate, $zone, , , , $hasEmail] = self::CUSTOMERS[$key];
        $lipa = $this->city('Batangas', 'Lipa');
        $mapped = mt_rand(1, 10) <= 6;

        $highest = $this->company->customers()->pluck('account_number')->reduce(fn (int $max, string $number): int => max($max, (int) $number), 0);

        $attributes = [
            'account_number' => str_pad((string) ($highest + 1), 6, '0', STR_PAD_LEFT),
            'first_name' => $first,
            'last_name' => $last,
            'company_name' => $companyName,
            'email' => $hasEmail ? Str::slug("{$first} {$last}", '.').'@mail.test' : null,
            'phone_number' => mt_rand(1, 10) <= 8 ? sprintf('09%02d %03d %04d', mt_rand(15, 99), mt_rand(100, 999), mt_rand(0, 9999)) : null,
            'status' => CustomerStatus::Pending,
            'account_type_id' => $this->ids['accountTypes'][$account],
            'charge_type_id' => $this->ids['chargeTypes'][$chargeType],
            'rate_type_id' => $this->ids['rates'][$rate],
            'cluster_type_id' => $this->ids['zones'][$zone],
            'home_address' => mt_rand(1, 250).' '.self::STREETS[mt_rand(0, count(self::STREETS) - 1)].', Brgy. '.self::BARANGAYS[$zone],
            'home_city_id' => $lipa->id, 'home_province_id' => $lipa->province_id, 'home_country_id' => $lipa->province->country_id,
            'home_zip_code' => '4217',
            'latitude' => $mapped ? round(13.9411 + mt_rand(-300, 300) / 10000, 6) : null,
            'longitude' => $mapped ? round(121.1631 + mt_rand(-300, 300) / 10000, 6) : null,
        ];

        // Billed at a head office or the owner's other address.
        $billingAddresses = [
            'c14' => ['Unit 12B, The Residences, Katipunan Ave.', 'Metro Manila', 'Quezon City', '1108'],
            'c19' => ['8F Sterling Tower, Ayala Ave.', 'Metro Manila', 'Makati', '1226'],
            'c25' => ['21F Agro Center, Ortigas Ave.', 'Metro Manila', 'Pasig', '1605'],
            'c40' => ['45 J.P. Laurel Highway', 'Batangas', 'Tanauan', '4232'],
        ];
        if (isset($billingAddresses[$key])) {
            [$address, $province, $cityName, $zip] = $billingAddresses[$key];
            $city = $this->city($province, $cityName);
            $attributes += ['billing_address' => $address, 'billing_city_id' => $city->id, 'billing_province_id' => $city->province_id, 'billing_country_id' => $city->province->country_id, 'billing_zip_code' => $zip];
        }

        if (in_array($key, ['c09', 'c12', 'c42', 'c48'], true)) {
            $attributes += ['photo_data' => $this->image(320, 240, [176, 112, 52], strtoupper($companyName ?? $last)), 'photo_mime_type' => 'image/png', 'photo_file_name' => Str::slug($companyName ?? $last).'.png'];
        }

        $customer = $this->company->customers()->create($attributes);
        $this->customerIds[$key] = $customer->id;

        return $customer;
    }

    /**
     * A customer connected years ago, with the installation record and the meter's first reading.
     */
    private function migrateCustomer(string $key): void
    {
        $customer = $this->addCustomer($key);
        [, , , , , , $zone, $meter] = self::CUSTOMERS[$key];

        $installedOn = Carbon::create(mt_rand(2012, 2025), mt_rand(1, 12), mt_rand(1, 28), 0, 0, 0, self::TIMEZONE);
        $initial = self::INITIAL_READINGS[$key] ?? match ($meter) {
            'half' => mt_rand(300, 7500),
            'threeq' => mt_rand(5000, 60000),
            'one' => mt_rand(100000, 600000),
            default => mt_rand(200000, 900000),
        };
        $meterNumber = sprintf('MTR-%d%04d', $zone, (int) substr($key, 1));
        $serial = 'SN-'.mt_rand(10000000, 99999999);
        $technician = $this->users[['dan', 'noel', 'arnel', 'victor'][mt_rand(0, 3)]];

        $customer->update([
            'status' => CustomerStatus::Connected,
            'meter_type_id' => $this->ids['meterTypes'][$meter],
            'meter_number' => $meterNumber,
            'meter_serial_number' => $serial,
            'meter_installed_on' => $installedOn->toDateString(),
        ]);

        $order = WorkOrder::createNumbered($this->company, [
            'customer_id' => $customer->id,
            'order_type' => WorkOrderType::NewInstallation,
            'meter_type_id' => $customer->meter_type_id,
            'meter_number' => $meterNumber,
            'serial_number' => $serial,
            'description' => 'New service connection (migrated from the previous billing system).',
            'assigned_to_id' => $technician->id,
            'status' => WorkOrderStatus::Completed,
            'requested_by_id' => $this->users['admin']->id,
            'requested_at' => $installedOn->copy()->setTime(8, 0)->subDays(7),
            'completed_at' => $installedOn->copy()->setTime(14, 0),
            'completed_by_id' => $technician->id,
            'completion_data' => [
                'fields' => ['meter_number' => $meterNumber, 'serial_number' => $serial, 'meter_type_id' => (string) $customer->meter_type_id, 'initial_reading' => (string) $initial],
                'checklist' => WorkOrderType::NewInstallation->checklist(),
                'remarks' => 'Migrated record.',
            ],
        ]);
        WorkOrderReading::query()->create([
            'company_id' => $this->company->id, 'customer_id' => $customer->id, 'work_order_id' => $order->id,
            'meter_number' => $meterNumber, 'kind' => ReadingKind::Initial, 'reading' => $initial, 'read_on' => $installedOn->toDateString(),
        ]);
        $order->log($this->users['admin'], 'completed', "Migrated from the previous billing system. Installed on {$installedOn->format('m/d/Y')}, initial reading {$initial}.");

        $this->meters[$key] = $initial;
    }

    /* ----------------------------------------------------------------- Billing ----------------------------------------------------------------- */

    /**
     * Each zone is read and billed on its own day near the end of the month; October is read but not billed.
     */
    private function scheduleBillingCycles(): void
    {
        foreach (self::BILLED_PERIODS as $period) {
            foreach (array_keys(self::BARANGAYS) as $zone) {
                $this->on($this->billDate($period, $zone).' 09:00', fn () => $this->readZone($zone, $period, bill: true));
            }
        }

        // Zone 1 and 2 read (two meters not reached yet), Zone 4 read this morning, Zone 3 not started except two meters.
        $this->on('2026-10-19 08:30', fn () => $this->readZone(1, '2026-10', bill: false, skip: ['c04', 'c06']));
        $this->on('2026-10-20 08:30', fn () => $this->readZone(2, '2026-10', bill: false));
        $this->on('2026-10-21 07:30', fn () => $this->readZone(4, '2026-10', bill: false));
        $this->on('2026-10-21 08:00', fn () => $this->readZone(3, '2026-10', bill: false, only: ['c31', 'c37']));
    }

    private function billDate(string $period, int $zone): string
    {
        return $period.'-'.(24 + $zone);
    }

    /**
     * Reads the zone's meters as listed on the Meter Readings page, then (when $bill) confirms the
     * readings that need review and issues the bills, as a supervisor and clerk would.
     *
     * @param  list<string>  $only
     * @param  list<string>  $skip
     */
    private function readZone(int $zone, string $period, bool $bill, array $only = [], array $skip = []): void
    {
        $keys = array_flip($this->customerIds);
        $reader = $this->users[$zone <= 2 ? 'ben' : 'rico'];
        $clerk = $this->users[$zone <= 2 ? 'paolo' : 'joy'];

        $customers = MeterReadings::customers($this->company, $period, $this->ids['zones'][$zone], '')->orderBy('account_number')->get();
        foreach ($customers as $customer) {
            $key = $keys[$customer->id];
            if (($only !== [] && ! in_array($key, $only, true)) || in_array($key, $skip, true)) {
                continue;
            }

            if ($customer->status === CustomerStatus::Connected) {
                $this->meters[$key] += $this->usage[$key][$period];
            }

            $code = self::READING_CODES[$key][$period] ?? ReadingCode::Normal;
            $highest = $customer->meterType?->highestReading();
            if ($highest !== null && $this->meters[$key] > $highest) {
                $this->meters[$key] -= $highest + 1;
                $code = $code === ReadingCode::Normal ? ReadingCode::Rollover : $code;
            }

            // The August reading of c11 is keyed in 100 too high; the bill is voided and issued again later.
            $present = $this->meters[$key] + ($key === 'c11' && $period === '2026-08' ? 100 : 0);
            $reading = MeterReadings::save($customer, $period, $code->isEstimated() ? '' : (string) $present, $code, $this->today(), $reader);

            if ($bill) {
                if ($reading->status === MeterReadingStatus::Review) {
                    MeterReadings::confirm($reading, $this->users['liza']);
                }
                BillingRun::generateFor($reading->fresh(), $this->today(), $clerk);
            }
        }
    }

    /* ---------------------------------------------------------------- Payments ----------------------------------------------------------------- */

    private function schedulePayments(): void
    {
        foreach (self::CUSTOMERS as $key => $spec) {
            [, , , , , , $zone, , $profile] = $spec;

            foreach (self::BILLED_PERIODS as $period) {
                if (in_array($period, self::SKIP_PAYMENT[$key] ?? [], true)) {
                    continue;
                }

                // [days after the bill, share of the bill paid]
                $plan = match ($profile) {
                    'prompt', 'new' => [mt_rand(2, 12), 1.0],
                    'sep_unpaid' => $period === '2026-09' ? null : [mt_rand(2, 12), 1.0],
                    'late' => [mt_rand(17, 24), 1.0],
                    'partial' => [mt_rand(4, 12), 0.6],
                    'arrears1' => $period === '2026-07' ? [mt_rand(2, 12), 1.0] : null,
                    default => null,
                };
                if ($plan === null) {
                    continue;
                }

                $paidOn = Carbon::parse($this->billDate($period, $zone), self::TIMEZONE)->addDays($plan[0]);
                if ($paidOn->toDateString() >= self::LAST_DAY) {
                    continue;
                }
                $this->on($paidOn->toDateString().sprintf(' %02d:%02d', mt_rand(9, 16), mt_rand(0, 59)), fn () => $this->payBill($key, $period, $plan[1]));
            }
        }

        // Paid ahead: the credit goes to each new bill as it is issued.
        $this->on('2026-07-20 14:00', fn () => $this->pay('c24', '3000.00', notes: 'Advance payment for the next few months.'));
        $this->on('2026-08-01 10:30', fn () => $this->pay('c25', '90000.00', PaymentMethod::BankTransfer, 'Quarterly advance per purchase order PO-2026-0712.'));
    }

    /**
     * Pays the share of the period's bill still owed (rounded down to whole pesos for partial payments).
     */
    private function payBill(string $key, string $period, float $share = 1.0): void
    {
        if (! isset($this->customerIds[$key])) {
            return;
        }

        $invoice = BillingRun::existingBill($this->customer($key), $period);
        if ($invoice === null || ! $invoice->status->isOpen()) {
            return;
        }

        $cents = Money::toCents($invoice->balance);
        if ($share < 1) {
            $cents = intdiv((int) round($cents * $share), 100) * 100;
        }
        if ($cents > 0) {
            $this->pay($key, Money::fromCents($cents));
        }
    }

    /**
     * Pays everything the customer owes on open bills.
     */
    private function payAll(string $key, ?PaymentMethod $method = null, ?string $notes = null): void
    {
        $owed = Ledger::summary($this->customer($key))['owed'];
        if ($owed > 0) {
            $this->pay($key, Money::fromCents($owed), $method, $notes);
        }
    }

    private function pay(string $key, string $amount, ?PaymentMethod $method = null, ?string $notes = null): Payment
    {
        $method ??= $this->paymentMethods[$key];
        $reference = match ($method) {
            PaymentMethod::Cash => null,
            PaymentMethod::Check => ['BPI', 'BDO', 'Metrobank', 'Landbank'][mt_rand(0, 3)].' check no. '.mt_rand(100000, 999999),
            PaymentMethod::BankTransfer => 'TRX-'.mt_rand(10000000, 99999999),
            PaymentMethod::EWallet => ['GCash', 'Maya'][mt_rand(0, 1)].' ref. '.mt_rand(1000000000, 2000000000),
        };

        // Grace is on leave from October 15; Ben covers the counter with temporary access.
        $day = (int) $this->clock->format('j');
        $cashier = $this->today() >= '2026-10-15' ? ($day % 2 === 0 ? 'ben' : 'mark') : ($day % 2 === 0 ? 'grace' : 'mark');

        return Ledger::recordPayment($this->customer($key), [
            'paid_on' => $this->today(),
            'amount' => $amount,
            'method' => $method->value,
            'reference_number' => $reference,
            'notes' => $notes,
        ], $this->users[$cashier]);
    }

    /**
     * Voided payments and invoices, and credit and debit memos.
     */
    private function scheduleBillingCorrections(): void
    {
        // A bounced check: the July bill is open again until it is paid in cash.
        $this->on('2026-07-31 11:00', fn () => $this->pay('c06', $this->balance('c06', '2026-07'), PaymentMethod::Check));
        $this->on('2026-08-04 15:00', function (): void {
            $payment = $this->customer('c06')->payments()->latest('id')->firstOrFail();
            Ledger::voidPayment($payment, 'Check returned by the bank: drawn against insufficient funds.', $this->users['liza']);
        });
        $this->on('2026-08-07 10:00', fn () => $this->payAll('c06', PaymentMethod::Cash, 'Replaces the returned check.'));

        // A mistyped reading: the August bill is voided and issued again with the right reading.
        $this->on('2026-08-26 09:30', function (): void {
            $customer = $this->customer('c11');
            $wrong = BillingRun::existingBill($customer, '2026-08');
            Ledger::voidInvoice($wrong, 'Present reading keyed in 100 cu.m. too high. Bill issued again with the corrected reading.', $this->users['liza']);
            $reading = MeterReadings::save($customer, '2026-08', (string) $this->meters['c11'], ReadingCode::Normal, '2026-08-25', $this->users['ben']);
            BillingRun::generateFor($reading, $this->today(), $this->users['paolo']);
        });

        // A goodwill credit memo, voided two days later.
        $this->on('2026-08-31 10:00', function (): void {
            Adjustments::issue(
                BillingRun::existingBill($this->customer('c20'), '2026-08'), AdjustmentType::Credit, AdjustmentReason::Rebate,
                'Goodwill credit for the service interruption on 08/20/2026.', 5000, $this->today(), null, $this->users['liza'],
            );
        });
        $this->on('2026-09-02 14:00', function (): void {
            $memo = $this->customer('c20')->invoices()->firstWhere('billing_period', '2026-08')->adjustments()->firstOrFail();
            Adjustments::void($memo, 'Interruption credits are given only for outages over 24 hours.', $this->users['liza']);
        });

        // A debit memo added to the September bill before the customer paid it.
        $this->on('2026-09-29 11:00', function (): void {
            Adjustments::issue(
                BillingRun::existingBill($this->customer('c05'), '2026-09'), AdjustmentType::Debit, AdjustmentReason::AdditionalCharge,
                'Replacement of a meter seal broken by the customer.', 25000, $this->today(), null, $this->users['liza'],
            );
        });
        $this->on('2026-10-05 09:40', fn () => $this->payAll('c05'));
    }

    private function balance(string $key, string $period): string
    {
        return (string) BillingRun::existingBill($this->customer($key), $period)->balance;
    }

    /* -------------------------------------------------------------- Work orders ---------------------------------------------------------------- */

    private function scheduleWorkOrders(): void
    {
        // New installations: completed in August (fee on its own invoice, paid in two parts), completed in
        // October (fee unpaid), in progress, on hold, cancelled. c50 was just added and has no order yet.
        $this->on('2026-07-20 10:05', fn () => $this->order('install-c47', 'c47', WorkOrderType::NewInstallation, 'kim', 'dan', 'New 1/2" service connection. Applicant paid the application fee.'));
        $this->on('2026-08-03 14:00', fn () => $this->completeInstallation('install-c47', 'c47', 'half', 'dan'));
        $this->on('2026-08-05 10:00', fn () => $this->pay('c47', '2000.00', PaymentMethod::Cash, 'Partial payment of the installation fee.'));
        $this->on('2026-08-21 11:00', fn () => $this->payAll('c47', PaymentMethod::Cash, 'Balance of the installation fee.'));

        $this->on('2026-08-20 11:05', fn () => $this->order('install-c49', 'c49', WorkOrderType::NewInstallation, 'kim', 'dan', 'New 1/2" service connection.'));
        $this->on('2026-09-10 15:00', fn () => $this->cancel('install-c49', 'Applicant withdrew the application.'));

        $this->on('2026-09-28 10:05', fn () => $this->order('install-c48', 'c48', WorkOrderType::NewInstallation, 'kim', 'arnel', 'New 3/4" commercial connection for a coffee shop.'));
        $this->on('2026-10-02 15:00', fn () => $this->completeInstallation('install-c48', 'c48', 'threeq', 'arnel'));

        $this->on('2026-10-05 13:05', fn () => $this->order('install-c46', 'c46', WorkOrderType::NewInstallation, 'kim', 'arnel', 'New 1/2" service connection.'));
        $this->on('2026-10-07 16:00', fn () => $this->hold('install-c46', 'Waiting for 1/2" meter stock from the supplier.'));

        $this->on('2026-10-12 09:35', fn () => $this->order('install-c45', 'c45', WorkOrderType::NewInstallation, 'kim', 'dan', 'New 1/2" service connection. Tapping point at the left side of the gate.'));

        // Pipe repair: completed (fee on the September bill), open past its 5 days, cancelled.
        $this->on('2026-08-10 09:00', fn () => $this->order('pipe-c01', 'c01', WorkOrderType::PipeRepair, 'kim', 'noel', 'Leaking service pipe along the sidewalk.'));
        $this->on('2026-08-14 15:00', fn () => $this->complete('pipe-c01', ['pipe_size' => '1/2"', 'length_replaced' => '2.5', 'cause' => 'Corroded GI pipe', 'materials' => '2.5 m 1/2" PE pipe, 2 couplings'], 'noel'));
        $this->on('2026-10-09 10:00', fn () => $this->order('pipe-c03', 'c03', WorkOrderType::PipeRepair, 'kim', 'arnel', 'Water seeping from the road near the meter box.'));
        $this->on('2026-08-29 10:00', fn () => $this->order('pipe-c04', 'c04', WorkOrderType::PipeRepair, 'kim', 'noel', 'Leak at the barangay hall comfort room line.'));
        $this->on('2026-09-02 09:00', fn () => $this->cancel('pipe-c04', 'Leak is inside the building; the barangay\'s own plumber fixed it.'));

        // Low water pressure: resolved, and not resolved (creates a Pipe Repair follow-up).
        $this->on('2026-09-01 09:00', fn () => $this->order('pressure-c07', 'c07', WorkOrderType::LowWaterPressure, 'kim', 'dan', 'Weak water flow in the mornings.'));
        $this->on('2026-09-08 14:00', fn () => $this->complete('pressure-c07', ['measured_pressure' => '8', 'cause' => 'Clogged strainer at the meter', 'action_taken' => 'Cleaned the strainer and flushed the service line.', 'resolved' => 'yes'], 'dan'));
        $this->on('2026-10-05 09:00', fn () => $this->order('pressure-c08', 'c08', WorkOrderType::LowWaterPressure, 'kim', 'arnel', 'Almost no water on the second floor.'));
        $this->on('2026-10-12 15:00', fn () => $this->complete('pressure-c08', ['measured_pressure' => '5', 'cause' => 'Leak on the service line before the meter', 'action_taken' => 'Isolated the line; the pipe needs replacing.', 'resolved' => 'no', 'follow_up' => WorkOrderType::PipeRepair->value], 'arnel'));

        // Water quality: passed, failed (flag), and a sample still at the lab.
        $this->on('2026-08-17 10:00', fn () => $this->order('quality-c09', 'c09', WorkOrderType::WaterQualityComplaint, 'kim', 'noel', 'Brownish water after the road works.'));
        $this->on('2026-08-20 11:00', fn () => $this->complete('quality-c09', ['complaint_type' => 'color', 'sample_taken' => 'yes', 'sample_id' => 'WQ-2026-0817', 'result' => 'pass', 'action_taken' => 'Flushed the line; water clear after 10 minutes.'], 'noel'));
        $this->on('2026-10-05 10:00', fn () => $this->order('quality-c10', 'c10', WorkOrderType::WaterQualityComplaint, 'kim', 'noel', 'Water smells of chlorine and rotten eggs.'));
        $this->on('2026-10-07 14:00', fn () => $this->complete('quality-c10', ['complaint_type' => 'odor', 'sample_taken' => 'yes', 'sample_id' => 'WQ-2026-1005', 'result' => 'fail', 'action_taken' => 'Advised the household to boil water; main line flushing scheduled.'], 'noel'));
        $this->on('2026-10-13 09:00', fn () => $this->order('quality-c02', 'c02', WorkOrderType::WaterQualityComplaint, 'kim', 'noel', 'Small particles in the water.'));
        $this->on('2026-10-15 11:00', fn () => $this->complete('quality-c02', ['complaint_type' => 'particles', 'sample_taken' => 'yes', 'sample_id' => 'WQ-2026-1013', 'result' => 'pending_lab', 'action_taken' => 'Flushed the line; sample sent to the lab.'], 'noel'));

        // Meter repair: repaired (fee on the September bill), not repairable (creates a Meter Replacement).
        $this->on('2026-09-04 09:00', fn () => $this->order('repair-c12', 'c12', WorkOrderType::MeterRepair, 'kim', 'dan', 'Meter register stops now and then.'));
        $this->on('2026-09-10 13:00', fn () => $this->complete('repair-c12', ['findings' => 'Register stuck intermittently.', 'action_taken' => 'Cleaned and re-calibrated the register.', 'outcome' => 'repaired', 'reading_before' => $this->meterReading('c12'), 'reading_after' => $this->meterReading('c12', 1)], 'dan'));
        $this->on('2026-10-01 09:00', fn () => $this->order('repair-c13', 'c13', WorkOrderType::MeterRepair, 'kim', 'dan', 'Meter glass fogged; dials hard to read.'));
        $this->on('2026-10-05 14:00', fn () => $this->complete('repair-c13', ['findings' => 'Cracked meter body; register fogged.', 'action_taken' => 'Tested the meter; recommended replacement.', 'outcome' => 'not_repairable', 'reading_before' => $this->meterReading('c13')], 'dan'));

        // Meter relocation: completed with a new map pin (fee on the September bill), and on hold.
        $this->on('2026-09-08 09:00', fn () => $this->order('relocate-c14', 'c14', WorkOrderType::MeterRelocation, 'kim', 'arnel', 'Move the meter out of the garage to the sidewalk.'));
        $this->on('2026-09-15 14:00', fn () => $this->complete('relocate-c14', ['new_location' => 'Sidewalk, right of the gate.', 'latitude' => '13.9468', 'longitude' => '121.1702', 'reading_before' => $this->meterReading('c14'), 'reading_after' => $this->meterReading('c14')], 'arnel'));
        $this->on('2026-10-02 09:00', fn () => $this->order('relocate-c15', 'c15', WorkOrderType::MeterRelocation, 'kim', 'arnel', 'Meter is behind a new fence; move it outside.'));
        $this->on('2026-10-06 16:00', fn () => $this->hold('relocate-c15', 'Customer not home; to be rescheduled.'));

        // Meter re-reading: correct, wrong (billing adjustment flag left open), meter faulty (creates a Meter Repair, then completed).
        $this->on('2026-08-27 09:00', fn () => $this->order('verify-c16', 'c16', WorkOrderType::MeterReadingVerification, 'kim', 'noel', 'Customer says the August bill is too high.'));
        $this->on('2026-08-30 10:00', fn () => $this->complete('verify-c16', ['disputed_reading' => $this->lastReading('c16'), 'verified_reading' => $this->lastReading('c16'), 'outcome' => 'correct'], 'noel'));
        $this->on('2026-10-05 09:30', fn () => $this->order('verify-c17', 'c17', WorkOrderType::MeterReadingVerification, 'kim', 'noel', 'Customer disputes the September reading.'));
        $this->on('2026-10-08 10:00', fn () => $this->complete('verify-c17', ['disputed_reading' => $this->lastReading('c17'), 'verified_reading' => $this->lastReading('c17', -12), 'outcome' => 'wrong'], 'noel', 'September reading was over-read by 12 cu.m.'));
        $this->on('2026-10-06 09:00', fn () => $this->order('verify-c18', 'c18', WorkOrderType::MeterReadingVerification, 'kim', 'noel', 'Bill seems high for a two-person household.'));
        $this->on('2026-10-09 11:00', function (): void {
            $this->complete('verify-c18', ['disputed_reading' => $this->lastReading('c18'), 'verified_reading' => $this->meterReading('c18'), 'outcome' => 'meter_faulty'], 'noel');
            $this->orders['repair-c18'] = $this->orders['verify-c18']->followUps()->firstOrFail();
        });
        $this->on('2026-10-14 13:00', fn () => $this->complete('repair-c18', ['findings' => 'Worn gears in the register.', 'action_taken' => 'Replaced the register gears.', 'outcome' => 'repaired', 'reading_before' => $this->meterReading('c18'), 'reading_after' => $this->meterReading('c18')], 'noel'));

        // Leak repair: before the meter (fee on the September bill), after the meter (leak adjustment, resolved by a credit memo), and open.
        $this->on('2026-08-31 09:00', fn () => $this->order('leak-c19', 'c19', WorkOrderType::LeakRepair, 'kim', 'arnel', 'Water leaking at the plant\'s service connection.'));
        $this->on('2026-09-03 15:00', fn () => $this->complete('leak-c19', ['leak_location' => 'before_meter', 'cause' => 'Loose union at the service connection', 'action_taken' => 'Replaced the union and gasket.', 'materials' => '1 pc 2" union, 1 gasket', 'reading_before' => $this->meterReading('c19'), 'reading_after' => $this->meterReading('c19')], 'arnel'));
        $this->on('2026-09-29 09:00', fn () => $this->order('leak-c42', 'c42', WorkOrderType::LeakRepair, 'kim', 'arnel', 'September bill tripled; customer suspects a hidden leak.'));
        $this->on('2026-10-02 10:00', fn () => $this->complete('leak-c42', ['leak_location' => 'after_meter', 'cause' => 'Burst pipe under the washing area (customer side)', 'action_taken' => 'Shut the valve; the customer\'s plumber replaced the pipe.', 'reading_before' => $this->meterReading('c42'), 'reading_after' => $this->meterReading('c42')], 'arnel'));
        $this->on('2026-10-05 10:00', function (): void {
            $order = $this->orders['leak-c42']->fresh();
            $invoice = BillingRun::existingBill($this->customer('c42'), '2026-09');
            $suggestion = Adjustments::suggestionFor($order);
            $cents = intdiv((int) round(Money::toCents($invoice->balance) * 0.4), 100) * 100;
            Adjustments::issue($invoice, $suggestion['type'], $suggestion['category'], $suggestion['reason'], $cents, $this->today(), $order, $this->users['liza']);
        });
        $this->on('2026-10-08 14:00', fn () => $this->payAll('c42'));
        $this->on('2026-10-17 10:00', fn () => $this->order('leak-c20', 'c20', WorkOrderType::LeakRepair, 'kim', 'arnel', 'Wet spot on the lawn near the meter.'));

        // Billing complaints: no error, adjustment needed (resolved by a credit memo), re-read needed (creates a Meter Re-reading).
        $this->on('2026-08-27 10:00', fn () => $this->order('complaint-c21', 'c21', WorkOrderType::BillingComplaint, 'kim', 'liza', 'School asks about the environmental fee on its July bill.'));
        $this->on('2026-08-31 16:00', fn () => $this->complete('complaint-c21', ['bill_period' => '2026-07', 'issue' => 'wrong_charges', 'findings' => 'Charges match the Government charge type; explained the environmental fee.', 'outcome' => 'no_error'], 'liza'));
        $this->on('2026-10-02 10:00', fn () => $this->order('complaint-c22', 'c22', WorkOrderType::BillingComplaint, 'kim', 'liza', 'Customer says the September reading is wrong.'));
        $this->on('2026-10-06 15:00', fn () => $this->complete('complaint-c22', ['bill_period' => '2026-09', 'issue' => 'wrong_reading', 'findings' => 'Reader misread a digit; over-billed by about 5 cu.m.', 'outcome' => 'adjustment_needed', 'adjustment_amount' => '150.00'], 'liza'));
        $this->on('2026-10-07 09:00', function (): void {
            $order = $this->orders['complaint-c22']->fresh();
            $suggestion = Adjustments::suggestionFor($order);
            Adjustments::issue(BillingRun::existingBill($this->customer('c22'), '2026-09'), $suggestion['type'], $suggestion['category'], $suggestion['reason'], Money::toCents($suggestion['amount']), $this->today(), $order, $this->users['liza']);
        });
        $this->on('2026-10-09 10:00', fn () => $this->payAll('c22'));
        $this->on('2026-10-08 11:00', fn () => $this->order('complaint-c23', 'c23', WorkOrderType::BillingComplaint, 'kim', 'liza', 'Customer says usage is too high for the household.'));
        $this->on('2026-10-10 15:00', fn () => $this->complete('complaint-c23', ['bill_period' => '2026-09', 'issue' => 'wrong_reading', 'findings' => 'Usage is within the usual range, but the customer asked for a re-read.', 'outcome' => 'reread_needed'], 'liza'));
        // Open complaint: puts an overdue customer on hold for disconnection.
        $this->on('2026-10-15 14:00', fn () => $this->order('complaint-c36', 'c36', WorkOrderType::BillingComplaint, 'kim', 'liza', 'Customer disputes the August and September bills and refuses to pay until checked.'));

        // Change of ownership: completed (fee on its own invoice, paid from advance credit), and open.
        $this->on('2026-09-01 10:00', fn () => $this->order('owner-c24', 'c24', WorkOrderType::ChangeOfOwnership, 'kim', 'dan', 'Property sold; new owner to take over the account.'));
        $this->on('2026-09-05 11:00', fn () => $this->complete('owner-c24', ['new_first_name' => 'Patricia', 'new_last_name' => 'Uy', 'new_email' => 'patricia.uy@mail.test', 'new_phone' => '0917 555 0142', 'effective_date' => '2026-09-01', 'final_reading' => $this->meterReading('c24')], 'dan'));
        $this->on('2026-10-15 10:00', fn () => $this->order('owner-c25', 'c25', WorkOrderType::ChangeOfOwnership, 'kim', 'dan', 'Plant acquired by Southern Tagalog Feeds Corp.; account to be transferred.'));

        // Meter replacement of a defective meter: the October reading includes the old meter's usage. Fee goes on the October bill.
        $this->on('2026-09-29 10:00', fn () => $this->order('replace-c40', 'c40', WorkOrderType::MeterReplacement, 'kim', 'dan', 'Meter found defective during the September reading.'));
        $this->on('2026-10-03 11:00', function (): void {
            $this->meters['c40'] += 6;
            $this->complete('replace-c40', [
                'reason' => 'defective', 'old_final_reading' => $this->meterReading('c40'),
                'new_meter_number' => 'MTR-40040B', 'new_serial_number' => 'SN-'.mt_rand(10000000, 99999999),
                'new_meter_type_id' => (string) $this->ids['meterTypes']['threeq'], 'new_initial_reading' => '0',
            ], 'dan');
            $this->meters['c40'] = 0;
        });
    }

    /**
     * Submits a work order, like the Work Orders page does.
     */
    private function order(string $name, string $key, WorkOrderType $type, string $requestedBy, ?string $assignee, string $description): WorkOrder
    {
        $customer = $this->customer($key);
        if ($reason = WorkOrderRules::whyNot($type, $customer)) {
            throw new RuntimeException("{$name}: {$type->label()} can't be submitted: {$reason}.");
        }

        $order = WorkOrder::createNumbered($this->company, [
            'customer_id' => $customer->id,
            'order_type' => $type,
            'meter_type_id' => $type->isOfficeWork() ? null : $customer->meter_type_id,
            'meter_number' => $type->isOfficeWork() ? null : $customer->meter_number,
            'serial_number' => $type->isOfficeWork() ? null : $customer->meter_serial_number,
            'description' => $description,
            'assigned_to_id' => $assignee === null ? null : $this->users[$assignee]->id,
            'status' => WorkOrderStatus::InProgress,
            'requested_by_id' => $this->users[$requestedBy]->id,
            'requested_at' => now(),
        ]);
        $order->log($this->users[$requestedBy], 'submitted', 'Submitted and assigned to '.($order->assignedTo?->name ?? 'nobody').'.');

        return $this->orders[$name] = $order;
    }

    /**
     * Completes a work order through the same checks as the completion form, with the type's usual fee.
     *
     * @param  array<string, string>  $fields
     */
    private function complete(string $name, array $fields, string $completedBy, ?string $remarks = null): void
    {
        $order = $this->orders[$name]->fresh();
        $type = $order->order_type;
        $user = $this->users[$completedBy];
        $fee = $this->company->workOrderFees()->where('order_type', $type)->value('amount');
        $photos = $type->allowsPhotos() && ($type->requiresPhoto() || mt_rand(0, 1) === 1)
            ? [['data' => $this->image(320, 240, [52, 120, 88], strtoupper($type->label())), 'mime' => 'image/png', 'name' => Str::slug($type->label()).'-'.$order->order_number.'.png']]
            : [];

        $checked = WorkOrderCompletion::check($order, [
            'fields' => $fields,
            'checked' => array_fill_keys(array_keys(WorkOrderCompletion::visibleChecklist($type, $fields)), true),
            'completedOn' => $this->today(),
            'completedById' => (string) $user->id,
            'remarks' => $remarks ?? '',
            'fee' => $fee === null ? '' : (string) $fee,
        ], count($photos), [$user->id => $user->name], self::TIMEZONE);

        if ($checked['errors'] !== []) {
            throw new RuntimeException("{$name} can't be completed: ".json_encode($checked['errors']));
        }

        WorkOrderCompletion::complete($order, $checked['data'], $user, $photos, self::TIMEZONE);
    }

    private function completeInstallation(string $name, string $key, string $meterType, string $technician): void
    {
        $this->complete($name, [
            'meter_number' => sprintf('MTR-%d%04d', self::CUSTOMERS[$key][6], (int) substr($key, 1)),
            'serial_number' => 'SN-'.mt_rand(10000000, 99999999),
            'meter_type_id' => (string) $this->ids['meterTypes'][$meterType],
            'initial_reading' => '0',
        ], $technician);
        $this->meters[$key] = 0;
    }

    private function hold(string $name, string $reason): void
    {
        $order = $this->orders[$name]->fresh();
        $order->update(['status' => WorkOrderStatus::OnHold, 'hold_reason' => $reason]);
        $order->log($this->users['kim'], 'on_hold', "Put on hold: {$reason}");
    }

    private function cancel(string $name, string $reason): void
    {
        $order = $this->orders[$name]->fresh();
        $order->update(['status' => WorkOrderStatus::Cancelled, 'hold_reason' => null, 'cancel_reason' => $reason]);
        $order->log($this->users['kim'], 'cancelled', "Cancelled: {$reason}");
    }

    /**
     * What the meter shows now, plus any water used since the last reading.
     */
    private function meterReading(string $key, int $add = 0): string
    {
        $this->meters[$key] += $add;

        return (string) $this->meters[$key];
    }

    /**
     * The present reading on the customer's last bill.
     */
    private function lastReading(string $key, int $add = 0): string
    {
        $reading = $this->customer($key)->meterReadings()->whereNotNull('invoice_id')->latest('billing_period')->firstOrFail();

        return (string) ((int) $reading->present_reading + $add);
    }

    /* ------------------------------------------------------------- Disconnections -------------------------------------------------------------- */

    private function scheduleDisconnections(): void
    {
        // Two bills overdue since September 13: notices for everyone who paid nothing.
        $this->on('2026-09-15 10:00', function (): void {
            $this->issueNotices(['c29', 'c30', 'c31', 'c32', 'c33']);
            $this->notices['c29']->update(['printed_at' => now()]);
        });
        // Paid the overdue bills: the notice is settled.
        $this->on('2026-09-18 15:00', fn () => $this->payAll('c33', PaymentMethod::Cash, 'Paid after receiving the notice of disconnection.'));
        // Deadline (September 22) passed: disconnection orders for three; c29's notice is left waiting for one.
        $this->on('2026-09-23 09:00', function (): void {
            foreach (['c30' => 'noel', 'c31' => 'dan', 'c32' => 'dan'] as $key => $technician) {
                $created = Disconnections::createWorkOrders($this->company, [$this->notices[$key]->id], $this->users[$technician]->id, $this->users['liza']);
                $this->orders["disconnect-{$key}"] = $created['created'][0] ?? throw new RuntimeException("No disconnection order for {$key}: ".implode(' ', $created['skipped']));
            }
        });
        $this->on('2026-09-24 14:00', function (): void {
            foreach (['c31' => 9, 'c32' => 7] as $key => $usedSinceLastReading) {
                $this->meters[$key] += $usedSinceLastReading;
                $this->complete("disconnect-{$key}", ['reason' => 'non_payment', 'final_reading' => $this->meterReading($key), 'seal_number' => 'SEAL-'.mt_rand(1000, 9999)], 'dan');
            }
        });

        // c31 pays everything and is reconnected; the reading at reconnection doesn't match the final one (flag).
        $this->on('2026-10-02 10:00', function (): void {
            $this->payAll('c31', PaymentMethod::Cash, 'Full settlement for reconnection.');
            $this->order('reconnect-c31', 'c31', WorkOrderType::Reconnection, 'kim', 'dan', 'Customer settled all overdue bills.');
        });
        $this->on('2026-10-05 10:00', function (): void {
            $this->complete('reconnect-c31', ['reconnection_reading' => $this->meterReading('c31', 3), 'seal_removed' => 'Seal from the disconnection'], 'dan');
        });
        $this->on('2026-10-06 11:00', fn () => $this->payAll('c31', PaymentMethod::Cash, 'Reconnection fee.'));

        // Two bills overdue since mid-October: one notice still within its deadline, one withdrawn.
        $this->on('2026-10-14 09:00', fn () => $this->issueNotices(['c28', 'c34']));
        $this->on('2026-10-16 10:00', fn () => Disconnections::cancel($this->notices['c34'], 'Customer promised to pay on 10/23/2026; approved by the Billing Supervisor.', $this->users['liza']));
    }

    /**
     * @param  list<string>  $keys
     */
    private function issueNotices(array $keys): void
    {
        $result = Disconnections::issue($this->company, array_map(fn (string $key): int => $this->customerIds[$key], $keys), $this->users['liza'], email: true);
        if ($result['skipped'] > 0) {
            throw new RuntimeException("{$result['skipped']} of the notices for ".implode(', ', $keys).' were not issued.');
        }

        $keysById = array_flip($this->customerIds);
        foreach ($result['issued'] as $notice) {
            $this->notices[$keysById[$notice->customer_id]] = $notice;
        }
    }

    /* ----------------------------------------------------------------- Replay ------------------------------------------------------------------ */

    private function on(string $at, Closure $event): void
    {
        $this->events[] = [Carbon::parse($at, self::TIMEZONE), count($this->events), $event];
    }

    /**
     * Runs the events in date order. Each night the scheduled jobs run as they would in production:
     * overdue invoices are surcharged (00:30) and open disconnection notices are re-checked (00:45).
     */
    private function replay(): void
    {
        usort($this->events, fn (array $a, array $b): int => [$a[0]->getTimestamp(), $a[1]] <=> [$b[0]->getTimestamp(), $b[1]]);

        foreach ($this->events as [$at, , $event]) {
            while ($this->clock->toDateString() < $at->toDateString()) {
                $this->clock = $this->clock->copy()->addDay()->setTime(0, 30);
                Carbon::setTestNow($this->clock);
                Surcharges::applyToOverdueInvoices($this->company->fresh(), $this->today());
                Carbon::setTestNow($this->clock->copy()->setTime(0, 45));
                Disconnections::reviewAll($this->company);
            }

            $this->clock = $at->copy();
            Carbon::setTestNow($this->clock);
            $event();
        }
    }

    private function today(): string
    {
        return $this->clock->toDateString();
    }

    private function customer(string $key): Customer
    {
        return Customer::query()->findOrFail($this->customerIds[$key]);
    }

    private function city(string $province, string $name): City
    {
        return City::query()
            ->with('province')
            ->where('name', $name)
            ->whereHas('province', fn ($query) => $query->where('name', $province))
            ->firstOrFail();
    }

    /**
     * A plain PNG with a centered label, for logos and photos.
     */
    private function image(int $width, int $height, array $rgb, string $label): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        }

        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));
        $font = 5;
        $label = mb_strimwidth($label, 0, intdiv($width, imagefontwidth($font)) - 1);
        imagestring(
            $image, $font,
            (int) max(($width - imagefontwidth($font) * strlen($label)) / 2, 0),
            (int) (($height - imagefontheight($font)) / 2),
            $label, imagecolorallocate($image, 255, 255, 255),
        );

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function report(): void
    {
        $company = $this->company->fresh();
        $count = fn (string $relation): int => $company->{$relation}()->count();

        $this->command?->info('Aqua Vista Water District is ready. Today in the data is '.self::LAST_DAY.'.');
        $this->command?->table(['', 'Count'], [
            ['Customers', $count('customers')],
            ['Invoices (incl. void)', $count('invoices')],
            ['Payments (incl. void)', $count('payments')],
            ['Work orders', $count('workOrders')],
            ['Meter readings', $count('meterReadings')],
            ['Disconnection notices', $count('disconnectionNotices')],
        ]);
        $this->command?->info('Sign in as '.self::ADMIN_EMAIL.' (or a staff username such as liza.reyes) with the password in TestDataSeeder::PASSWORD.');
    }
}
