<?php

namespace Database\Seeders;

use App\Enums\Access;
use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentType;
use App\Enums\ChargeValueType;
use App\Enums\CustomerStatus;
use App\Enums\DisconnectionNoticeStatus;
use App\Enums\DistributionType;
use App\Enums\FeeBilling;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
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
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\Payment;
use App\Models\RateRange;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use SplPriorityQueue;
use Throwable;

/**
 * Five years of a busy water district, for load testing: 500 customers billed monthly from October 2021
 * to September 2026, with October 2026 billed for three zones, read for two and not started for three.
 *
 * Like TestDataSeeder, everything after the setup goes through the app's own services (BillingRun, Ledger,
 * Adjustments, WorkOrderCompletion, Disconnections), replaying the days in order and running the nightly
 * surcharge and disconnection-review jobs on each day. Customers follow payment habits (prompt, late,
 * partial, erratic, paying ahead, chronic arrears, abandoning the account), which drive the notices,
 * disconnections and reconnections. Each simulated day is one transaction; an event that fails is rolled
 * back on its own and listed at the end.
 *
 * Run on its own database (PowerShell); it takes a while:
 *   $env:DB_DATABASE='utility_billing_load'; php artisan migrate:fresh --seeder=LoadTestSeeder
 * The admin (username "admin") keeps the password it has in the self::KEEP_ADMIN_PASSWORD_FROM database
 * when that exists; staff (and the admin otherwise) sign in with self::PASSWORD.
 */
class LoadTestSeeder extends Seeder
{
    public const PASSWORD = 'Test@12345';

    public const ADMIN_EMAIL = 'admin@aquavista.test';

    /** The database whose admin password (and name) is kept, so the admin signs in as before. */
    private const KEEP_ADMIN_PASSWORD_FROM = 'utility_billing_test';

    private const TIMEZONE = 'Asia/Manila';

    private const FIRST_PERIOD = '2021-10';

    private const CURRENT_PERIOD = '2026-10';

    /** The last day replayed; anything planned after it is left undone. */
    private const LAST_DAY = '2026-10-23';

    private const CUSTOMER_COUNT = 500;

    /** Customers carried over from the previous system at go-live; the rest apply over the five years. */
    private const MIGRATED_COUNT = 380;

    private const ZONES = [
        1 => 'Poblacion', 2 => 'Marawoy', 3 => 'Sabang', 4 => 'Tambo',
        5 => 'Antipolo del Norte', 6 => 'Bolbok', 7 => 'Inosloban', 8 => 'Sampaguita',
    ];

    /** Usage through the year: the dry season uses more water. */
    private const SEASON = [1 => 1.0, 2 => 1.0, 3 => 1.1, 4 => 1.2, 5 => 1.2, 6 => 1.05, 7 => 0.95, 8 => 0.9, 9 => 0.9, 10 => 0.95, 11 => 1.0, 12 => 1.05];

    /**
     * Staff: key => [first name, last name, role, department, hired on (null: at go-live), last day].
     */
    private const STAFF = [
        'liza' => ['Liza', 'Reyes', 'Billing Supervisor', 'Billing', null, null],
        'paolo' => ['Paolo', 'Garcia', 'Billing Clerk', 'Billing', null, null],
        'joy' => ['Joy', 'Navarro', 'Billing Clerk', 'Billing', null, null],
        'grace' => ['Grace', 'Lim', 'Cashier', 'Collection', null, null],
        'mark' => ['Mark', 'Torres', 'Cashier', 'Collection', null, null],
        'carmen' => ['Carmen', 'Villareal', 'Cashier', 'Collection', null, '2024-12-31'],
        'ella' => ['Ella', 'Santiago', 'Cashier', 'Collection', '2025-01-06', null],
        'ben' => ['Ben', 'Aquino', 'Meter Reader', 'Field Operations', null, null],
        'rico' => ['Rico', 'Bautista', 'Meter Reader', 'Field Operations', null, null],
        'jun' => ['Jun', 'Mercado', 'Meter Reader', 'Field Operations', null, '2023-05-31'],
        'lito' => ['Lito', 'Panganiban', 'Meter Reader', 'Field Operations', '2023-06-01', null],
        'dan' => ['Dan', 'Ramos', 'Field Technician', 'Field Operations', null, null],
        'noel' => ['Noel', 'Castillo', 'Field Technician', 'Field Operations', null, null],
        'arnel' => ['Arnel', 'Pascual', 'Field Technician', 'Field Operations', null, null],
        'victor' => ['Victor', 'Salazar', 'Field Technician', 'Field Operations', null, '2026-09-30'],
        'kim' => ['Kim', 'Flores', 'Customer Service', 'Customer Service', null, null],
        'sofia' => ['Sofia', 'Mendiola', 'Customer Service', 'Customer Service', '2022-07-01', null],
        'teresa' => ['Teresa', 'Gomez', 'Auditor', 'Administration', null, null],
    ];

    private const TECHNICIANS = ['dan', 'noel', 'arnel', 'victor'];

    private const CASHIERS = ['grace', 'mark', 'carmen', 'ella'];

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
     * Payment habits by account type: profile => weight. See afterBill() for what each one does.
     */
    private const PROFILES = [
        'res' => ['prompt' => 50, 'late' => 14, 'partial' => 7, 'erratic' => 12, 'advance' => 3, 'chronic' => 8, 'abandon' => 6],
        'com' => ['prompt' => 55, 'late' => 20, 'erratic' => 10, 'partial' => 5, 'advance' => 5, 'chronic' => 5],
        'gov' => ['prompt' => 40, 'late' => 50, 'partial' => 10],
        'ind' => ['prompt' => 50, 'late' => 20, 'advance' => 30],
    ];

    /** How likely each profile is to pay up after a notice of disconnection, and to settle and reconnect after being cut off. */
    private const RESPONDS_TO_NOTICE = ['prompt' => 0.95, 'late' => 0.85, 'partial' => 0.8, 'advance' => 0.95, 'erratic' => 0.6, 'chronic' => 0.3, 'abandon' => 0.0];

    private const RECONNECTS = ['prompt' => 1.0, 'late' => 0.9, 'partial' => 0.9, 'advance' => 1.0, 'erratic' => 0.85, 'chronic' => 0.55, 'abandon' => 0.0];

    private const FIRST_NAMES = [
        'Juan', 'Maria', 'Jose', 'Ana', 'Antonio', 'Rosario', 'Eduardo', 'Teresita', 'Ricardo', 'Cristina', 'Danilo', 'Gloria',
        'Fernando', 'Norma', 'Alberto', 'Marites', 'Rogelio', 'Evelyn', 'Virgilio', 'Josefina', 'Arturo', 'Remedios', 'Conrado',
        'Felicidad', 'Rolando', 'Imelda', 'Ernesto', 'Leticia', 'Bonifacio', 'Corazon', 'Herminio', 'Perla', 'Santiago', 'Lorna',
        'Wilfredo', 'Nenita', 'Gregorio', 'Rebecca', 'Alfredo', 'Milagros', 'Kristine', 'Jayson', 'Angelica', 'Jerome', 'Ramil',
        'Shiela Mae', 'John Paul', 'Mary Grace', 'Mark Anthony', 'Princess', 'Rhea', 'Jericho', 'Lovely', 'Christian', 'Joanna',
    ];

    private const LAST_NAMES = [
        'Dela Cruz', 'Santos', 'Reyes', 'Garcia', 'Mendoza', 'Bautista', 'Aquino', 'Flores', 'Villanueva', 'Castro', 'Navarro',
        'Pascual', 'Tan', 'Lopez', 'Gonzales', 'Diaz', 'Cruz', 'Fernandez', 'Morales', 'Ramos', 'Torres', 'Castillo', 'Sison',
        'Villar', 'Manalo', 'Ocampo', 'Mercado', 'Robles', 'Salvador', 'Medina', 'Galang', 'Dizon', 'Lacson', 'Domingo',
        'Katigbak', 'Umali', 'Bernardo', 'Aguilar', 'Valdez', 'Soriano', 'Panganiban', 'Yap', 'Cabrera', 'Estrada', 'Marasigan',
        'Macaraig', 'Dimaculangan', 'Malabanan', 'Atienza', 'Recto', 'Kalaw', 'Silang', 'Mojares', 'Ilagan', 'Magpantay',
    ];

    private const BUSINESSES = [
        'Sari-Sari Store', 'Bakery', 'Carinderia', 'Hardware', 'Laundry Services', 'Dental Clinic', 'Pharmacy', 'Auto Repair Shop',
        'Water Refilling Station', 'Internet Café', 'Beauty Salon', 'Rice Dealer', 'Lechon House', 'Car Wash', 'Boarding House',
    ];

    private const GOVERNMENT = ['Barangay %s Hall', '%s Elementary School', '%s National High School', '%s Health Center', '%s Day Care Center', 'PNP %s Outpost'];

    private const INDUSTRIES = ['Ice Plant Corp.', 'Agro Feeds Inc.', 'Food Processing Corp.', 'Poultry Farms Inc.', 'Bottling Co.', 'Concrete Products Inc.'];

    private const STREETS = ['Rizal St.', 'Mabini St.', 'C.M. Recto Ave.', 'Burgos St.', 'Luna St.', 'Del Pilar St.', 'Bonifacio St.', 'P. Torres St.', 'Aguinaldo Hwy.', 'Sampaguita St.', 'Narra St.', 'Acacia St.', 'Ayala Hwy.', 'B. Morada Ave.'];

    private Carbon $clock;

    private Company $company;

    /** @var SplPriorityQueue<array{0: int, 1: int}, array{0: Carbon, 1: string, 2: Closure}> */
    private SplPriorityQueue $queue;

    private int $sequence = 0;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, bool> Staff key => still working here. */
    private array $onDuty = [];

    /** @var array<string, array<string|int, int>> Lookup ids: accountTypes, chargeTypes, rates, zones, meterTypes, departments, roles. */
    private array $ids = [];

    /**
     * @var array<int, array{zone: int, account: string, chargeType: string, rate: string, usage: int, meter: string, profile: string, stopAt: ?string, first: string, last: string, company: ?string, email: bool}>
     *                                                                                                                                                                                                               Customer index (1–500) => what they are like.
     */
    private array $specs = [];

    /** @var array<int, int> Customer index => id. */
    private array $customerIds = [];

    /** @var array<int, int> Customer id => index. */
    private array $indexOf = [];

    /** @var array<int, int> What each customer's meter shows now, kept as the days are replayed. */
    private array $meters = [];

    /** @var array<int, array{billed: int, partial: int, reconnect: bool, rollover: bool, replacements: int}> */
    private array $state = [];

    private int $nextAccountNumber = 1;

    /** @var array<string, string> Label => PNG, so each picture is drawn once. */
    private array $images = [];

    /** @var list<string> */
    private array $failures = [];

    private float $startedAt;

    public function run(): void
    {
        $this->call(LocationSeeder::class);

        if (User::query()->where('email', self::ADMIN_EMAIL)->exists()) {
            $this->command?->warn('The test company already exists. Run this seeder on a fresh database (migrate:fresh).');

            return;
        }

        DB::disableQueryLog();
        mt_srand(20211001);
        $this->startedAt = microtime(true);
        $this->queue = new SplPriorityQueue;
        $this->clock = Carbon::parse('2021-10-01 08:00', self::TIMEZONE);
        Carbon::setTestNow($this->clock);

        try {
            $this->setUpCompany();
            $this->planCustomers();
            $this->scheduleCustomers();
            $this->scheduleBillingCycles();
            $this->scheduleCollections();
            $this->scheduleServiceOrders();
            $this->scheduleBillingCorrections();
            $this->scheduleCompanyChanges();
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
            'email_verified_at' => now(), 'is_admin' => true, 'last_login_at' => '2026-10-23 07:45:00', 'tour_completed_at' => now(),
        ]);
        $this->keepAdminPassword($admin);
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

    /**
     * Gives the admin the password (and name) it has in the other test database, when that exists.
     */
    private function keepAdminPassword(User $admin): void
    {
        if (DB::connection()->getDatabaseName() === self::KEEP_ADMIN_PASSWORD_FROM) {
            return;
        }

        try {
            $kept = DB::selectOne('select name, first_name, last_name, password from `'.self::KEEP_ADMIN_PASSWORD_FROM.'`.users where username = ? limit 1', ['admin']);
        } catch (Throwable) {
            $kept = null;
        }

        if ($kept === null || $kept->password === null) {
            $this->command?->warn('No admin found in '.self::KEEP_ADMIN_PASSWORD_FROM.'; the admin signs in with LoadTestSeeder::PASSWORD.');

            return;
        }

        // Straight to the table, so the hash is stored as it is instead of being hashed again.
        DB::table('users')->where('id', $admin->id)->update([
            'password' => $kept->password, 'name' => $kept->name, 'first_name' => $kept->first_name, 'last_name' => $kept->last_name,
        ]);
        $admin->refresh();
        $this->command?->info('The admin keeps its password from '.self::KEEP_ADMIN_PASSWORD_FROM.'.');
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

        foreach (self::ZONES as $zone => $barangay) {
            $this->ids['zones'][$zone] = $company->clusterTypes()->create(['name' => "Zone {$zone} – {$barangay}", 'is_active' => true])->id;
        }
        $company->clusterTypes()->create(['name' => 'Zone 9 – San Carlos (merged into Zone 8)', 'is_active' => false]);

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
        foreach ($elements as $key => [$name, $distribution, $afterDue, $taxable]) {
            $this->ids['elements'][$key] = $this->company->chargeElements()->create([
                'name' => $name, 'distribution_type' => $distribution, 'is_after_due' => $afterDue, 'is_taxable' => $taxable,
            ])->id;
        }

        // [name, description, [element => [percentage?, value]]]; the Septage Fee is added to Commercial in 2025.
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
                    'charge_element_id' => $this->ids['elements'][$element],
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

        foreach (self::STAFF as $key => [, , , , $hiredOn]) {
            if ($hiredOn === null) {
                $this->hire($key);
            }
        }
    }

    private function hire(string $key): void
    {
        [$first, $last, $role, $department, , $lastDay] = self::STAFF[$key];

        $this->users[$key] = User::query()->create([
            'name' => "{$first} {$last}", 'first_name' => $first, 'last_name' => $last,
            'email' => strtolower("{$first}.{$last}@aquavista.test"), 'username' => strtolower("{$first}.{$last}"),
            'password' => self::PASSWORD, 'email_verified_at' => now(), 'is_admin' => false,
            'company_id' => $this->company->id, 'role_id' => $this->ids['roles'][$role], 'department_id' => $this->ids['departments'][$department],
            'last_login_at' => $lastDay === null
                ? Carbon::parse('2026-10-'.mt_rand(12, 23).' '.mt_rand(7, 17).':'.mt_rand(10, 59), self::TIMEZONE)
                : Carbon::parse("{$lastDay} 16:30", self::TIMEZONE),
            'tour_completed_at' => $key === 'ella' ? null : now(),
        ]);
        $this->onDuty[$key] = true;
    }

    private function scheduleUsers(): void
    {
        foreach (self::STAFF as $key => [, , , , $hiredOn, $lastDay]) {
            if ($hiredOn !== null) {
                $this->on("{$hiredOn} 08:00", fn () => $this->hire($key));
            }
            if ($lastDay !== null) {
                $this->on("{$lastDay} 17:00", function () use ($key): void {
                    $this->users[$key]->update(['deactivated_at' => now()]);
                    $this->onDuty[$key] = false;
                });
            }
        }

        // Covering for a cashier on leave; an earlier grant that ended; one that hasn't started.
        $this->on('2026-10-15 08:00', fn () => $this->users['ben']->specialAccess()->create(['page' => Page::Payments->value, 'can_view' => true, 'can_add' => true, 'can_edit' => false, 'can_delete' => false, 'start_date' => '2026-10-15', 'end_date' => '2026-10-31']));
        $this->on('2025-03-01 08:00', fn () => $this->users['joy']->specialAccess()->create(['page' => Page::Disconnections->value, 'can_view' => true, 'can_add' => true, 'can_edit' => true, 'can_delete' => false, 'start_date' => '2025-03-01', 'end_date' => '2025-03-31']));
        $this->on('2026-10-20 08:00', fn () => $this->users['kim']->specialAccess()->create(['page' => Page::MeterReadings->value, 'can_view' => true, 'can_add' => true, 'can_edit' => false, 'can_delete' => false, 'start_date' => '2026-11-01', 'end_date' => '2026-11-15']));

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

    /**
     * Rate increases and a new charge over the years. Bills already issued keep their old amounts.
     */
    private function scheduleCompanyChanges(): void
    {
        foreach (['2024-01-01' => 1.06, '2026-01-01' => 1.05] as $date => $increase) {
            $this->on("{$date} 06:00", function () use ($increase): void {
                $rateIds = array_map(fn (string $key): int => $this->ids['rates'][$key], ['res', 'com', 'ind', 'gov']);
                foreach (RateRange::query()->whereIn('rate_type_id', $rateIds)->get() as $range) {
                    $range->update(['rate' => number_format(round((float) $range->rate * $increase * 2) / 2, 2, '.', '')]);
                }
            });
        }

        $this->on('2025-01-01 06:00', fn () => $this->company->chargeTypes()->findOrFail($this->ids['chargeTypes']['commercial'])->elements()->create([
            'charge_element_id' => $this->ids['elements']['septage'], 'value_type' => ChargeValueType::Fixed, 'value' => '35.00',
        ]));
    }

    /* ---------------------------------------------------------------- Customers ---------------------------------------------------------------- */

    /**
     * Decides up front who the 500 customers are; they are created when they apply (or at go-live).
     */
    private function planCustomers(): void
    {
        $hospitalTaken = false;

        for ($i = 1; $i <= self::CUSTOMER_COUNT; $i++) {
            $account = $this->pick(['res' => 80, 'com' => 12, 'gov' => 4, 'ind' => 2]);
            $zone = mt_rand(1, count(self::ZONES));
            $senior = $account === 'res' && $this->chance(0.12);
            $first = $this->any(self::FIRST_NAMES);
            $last = $this->any(self::LAST_NAMES);

            $companyName = match ($account) {
                'com' => "{$last} ".$this->any(self::BUSINESSES),
                'gov' => ! $hospitalTaken && $zone === 1 ? 'Lipa City District Hospital' : sprintf($this->any(self::GOVERNMENT), self::ZONES[$zone]),
                'ind' => "{$last} ".$this->any(self::INDUSTRIES),
                default => null,
            };
            $hospitalTaken = $hospitalTaken || $companyName === 'Lipa City District Hospital';

            $profile = $this->pick(self::PROFILES[$account]);
            $stopAt = null;
            if ($profile === 'abandon') {
                $from = $i <= self::MIGRATED_COUNT ? Carbon::parse('2022-06-01') : $this->applicationDate($i)->addMonths(12);
                $stopAt = $from->addMonths(mt_rand(0, 48))->format('Y-m');
            }

            $this->specs[$i] = [
                'zone' => $zone,
                'account' => $account,
                'chargeType' => match ($account) {
                    'res' => $senior ? 'senior' : 'standard', 'com' => 'commercial', 'gov' => 'government', 'ind' => 'industrial'
                },
                'rate' => match ($account) {
                    'res' => $senior && $this->chance(0.4) ? 'lifeline' : 'res', default => $account
                },
                'usage' => match ($account) {
                    'res' => mt_rand(8, 30), 'com' => mt_rand(20, 90), 'gov' => mt_rand(40, 160), 'ind' => mt_rand(180, 600)
                },
                'meter' => match ($account) {
                    'res' => $i <= self::MIGRATED_COUNT && $this->chance(0.07) ? 'plastic' : 'half',
                    'com' => 'threeq',
                    'gov' => $this->chance(0.5) ? 'threeq' : 'one',
                    'ind' => $this->chance(0.5) ? 'one' : 'two',
                },
                'profile' => $profile,
                'stopAt' => $stopAt,
                'first' => $first,
                'last' => $last,
                'company' => $companyName,
                'email' => $this->chance(0.8),
            ];
            $this->state[$i] = ['billed' => 0, 'partial' => 0, 'reconnect' => false, 'rollover' => false, 'replacements' => 0];
        }
    }

    /**
     * When a customer who wasn't migrated applies: spread from November 2021 to the last days replayed.
     */
    private function applicationDate(int $i): Carbon
    {
        $late = self::CUSTOMER_COUNT - self::MIGRATED_COUNT;
        $start = Carbon::parse('2021-11-03 09:00', self::TIMEZONE);
        $days = $start->diffInDays(Carbon::parse('2026-10-21', self::TIMEZONE));
        $offset = (int) round(($i - self::MIGRATED_COUNT - 1) / max($late - 1, 1) * $days);

        return $this->workday($start->copy()->addDays($offset));
    }

    private function scheduleCustomers(): void
    {
        // Carried over from the previous system at go-live, with some exempt from disconnection.
        $this->on('2021-10-15 09:00', function (): void {
            for ($i = 1; $i <= self::MIGRATED_COUNT; $i++) {
                $this->migrateCustomer($i);
            }

            foreach ($this->specs as $i => $spec) {
                if (isset($this->customerIds[$i]) && $spec['company'] !== null && preg_match('/Hospital|Health Center/', $spec['company'])) {
                    Disconnections::exempt($this->customer($i), 'Health facility: essential service', null, $this->users['admin']);
                }
            }
        });

        // Hardship exemptions that run out, and one taken back early.
        foreach (['2022-03-07', '2023-08-14', '2025-02-10', '2026-07-06'] as $n => $date) {
            $this->on("{$date} 10:00", function () use ($n): void {
                $i = $this->randomCustomer(fn (Customer $customer): bool => $customer->disconnection_exempt_reason === null);
                if ($i === null) {
                    return;
                }
                Disconnections::exempt($this->customer($i), 'Medical hardship, approved by the General Manager', $this->clock->copy()->addMonths(6)->toDateString(), $this->users['liza']);
                if ($n === 1) {
                    $this->on($this->clock->copy()->addMonths(2), fn () => Disconnections::removeExemption($this->customer($i)));
                }
            });
        }

        // New applications over the five years.
        for ($i = self::MIGRATED_COUNT + 1; $i <= self::CUSTOMER_COUNT; $i++) {
            $appliedAt = $this->applicationDate($i);
            $this->on($appliedAt, function () use ($i): void {
                $this->addCustomer($i);
                // The last few applied this week and have no installation order yet.
                if ($i > self::CUSTOMER_COUNT - 3) {
                    return;
                }
                $this->applyForInstallation($i);
            });
        }
    }

    private function addCustomer(int $i): Customer
    {
        $spec = $this->specs[$i];
        $lipa = $this->city('Batangas', 'Lipa');
        $mapped = $this->chance(0.6);

        $attributes = [
            'account_number' => str_pad((string) $this->nextAccountNumber++, 6, '0', STR_PAD_LEFT),
            'first_name' => $spec['first'],
            'last_name' => $spec['last'],
            'company_name' => $spec['company'],
            'email' => $spec['email'] ? Str::slug("{$spec['first']} {$spec['last']} {$i}", '.').'@mail.test' : null,
            'phone_number' => $this->chance(0.8) ? sprintf('09%02d %03d %04d', mt_rand(15, 99), mt_rand(100, 999), mt_rand(0, 9999)) : null,
            'status' => CustomerStatus::Pending,
            'account_type_id' => $this->ids['accountTypes'][$spec['account']],
            'charge_type_id' => $this->ids['chargeTypes'][$spec['chargeType']],
            'rate_type_id' => $this->ids['rates'][$spec['rate']],
            'cluster_type_id' => $this->ids['zones'][$spec['zone']],
            'home_address' => mt_rand(1, 400).' '.$this->any(self::STREETS).', Brgy. '.self::ZONES[$spec['zone']],
            'home_city_id' => $lipa->id, 'home_province_id' => $lipa->province_id, 'home_country_id' => $lipa->province->country_id,
            'home_zip_code' => '4217',
            'latitude' => $mapped ? round(13.9411 + mt_rand(-400, 400) / 10000, 6) : null,
            'longitude' => $mapped ? round(121.1631 + mt_rand(-400, 400) / 10000, 6) : null,
        ];

        // Businesses and plants billed at a head office.
        if (in_array($spec['account'], ['com', 'ind'], true) && $this->chance(0.25)) {
            [$address, $province, $cityName, $zip] = $this->any([
                ['8F Sterling Tower, Ayala Ave.', 'Metro Manila', 'Makati', '1226'],
                ['21F Agro Center, Ortigas Ave.', 'Metro Manila', 'Pasig', '1605'],
                ['45 J.P. Laurel Highway', 'Batangas', 'Tanauan', '4232'],
                ['Unit 12B, The Residences, Katipunan Ave.', 'Metro Manila', 'Quezon City', '1108'],
            ]);
            $city = $this->city($province, $cityName);
            $attributes += ['billing_address' => $address, 'billing_city_id' => $city->id, 'billing_province_id' => $city->province_id, 'billing_country_id' => $city->province->country_id, 'billing_zip_code' => $zip];
        }

        if ($spec['account'] === 'com' && $this->chance(0.1)) {
            $attributes += ['photo_data' => $this->cachedImage(strtoupper((string) $spec['company']), [176, 112, 52]), 'photo_mime_type' => 'image/png', 'photo_file_name' => Str::slug((string) $spec['company']).'.png'];
        }

        $customer = $this->company->customers()->create($attributes);
        $this->customerIds[$i] = $customer->id;
        $this->indexOf[$customer->id] = $i;

        return $customer;
    }

    /**
     * A customer connected years ago, with the installation record and the meter's first reading.
     */
    private function migrateCustomer(int $i): void
    {
        $customer = $this->addCustomer($i);
        $spec = $this->specs[$i];

        $installedOn = Carbon::create(mt_rand(2005, 2021), mt_rand(1, 9), mt_rand(1, 28), 0, 0, 0, self::TIMEZONE);
        // Half-inch meters start high enough that many roll over past 9999 during the five years.
        $initial = match ($spec['meter']) {
            'half', 'plastic' => mt_rand(300, 9700),
            'threeq' => mt_rand(5000, 90000),
            'one' => mt_rand(50000, 900000),
            default => mt_rand(200000, 900000),
        };
        $meterNumber = sprintf('MTR-%d%04d', $spec['zone'], $i);
        $serial = 'SN-'.mt_rand(10000000, 99999999);
        $technician = $this->users[$this->any(['dan', 'noel', 'arnel', 'victor'])];

        $customer->update([
            'status' => CustomerStatus::Connected,
            'meter_type_id' => $this->ids['meterTypes'][$spec['meter']],
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

        $this->meters[$i] = $initial;
    }

    /**
     * New installation: most are done in a few days; some are cancelled, some wait on hold, and the
     * latest are still open.
     */
    private function applyForInstallation(int $i): void
    {
        $technician = $this->technician();
        $order = $this->submit($i, WorkOrderType::NewInstallation, $this->frontDesk(), $technician, $this->any([
            'New 1/2" service connection. Applicant paid the application fee.',
            'New service connection. Tapping point at the left side of the gate.',
            'New connection for a newly built house.',
            'New service connection; applicant transferred from a deep well.',
        ]));

        $roll = mt_rand(1, 100);
        if ($roll <= 5) {
            $this->on($this->later(3, 20), fn () => $this->cancel($order, $this->any(['Applicant withdrew the application.', 'Property is outside the service area.', 'Applicant could not provide proof of ownership.'])));

            return;
        }
        if ($roll <= 10) {
            $this->on($this->later(2, 6), fn () => $this->hold($order, $this->any(['Waiting for 1/2" meter stock from the supplier.', 'Road crossing permit pending with the city.', 'Applicant asked to schedule after the house is finished.'])));
            $this->on($this->later(25, 50), fn () => $this->completeInstallation($order, $i, $technician));

            return;
        }

        $this->on($this->later(4, 20), fn () => $this->completeInstallation($order, $i, $technician));
    }

    private function completeInstallation(WorkOrder $order, int $i, string $technician): void
    {
        $spec = $this->specs[$i];
        $this->completeOrder($order, [
            'meter_number' => sprintf('MTR-%d%04d', $spec['zone'], $i),
            'serial_number' => 'SN-'.mt_rand(10000000, 99999999),
            'meter_type_id' => (string) $this->ids['meterTypes'][$spec['meter']],
            'initial_reading' => '0',
        ], $this->onDutyOr($technician));
        $this->meters[$i] = 0;

        // The installation fee is on its own invoice: paid at once, or in two parts.
        if ($this->chance(0.6)) {
            $this->on($this->later(0, 7), fn () => $this->payAll($i, PaymentMethod::Cash, 'Installation fee.'));
        } else {
            $this->on($this->later(0, 3), fn () => $this->pay($i, '2000.00', PaymentMethod::Cash, 'Partial payment of the installation fee.'));
            $this->on($this->later(10, 30), fn () => $this->payAll($i, PaymentMethod::Cash, 'Balance of the installation fee.'));
        }
    }

    /* ----------------------------------------------------------------- Billing ----------------------------------------------------------------- */

    /**
     * Each zone is read and billed on its own day near the end of the month. October 2026: zones 1–3
     * billed, 4–5 read but not billed (some meters not reached yet), 6–8 not started.
     */
    private function scheduleBillingCycles(): void
    {
        foreach ($this->periods(self::FIRST_PERIOD, self::CURRENT_PERIOD) as $period) {
            foreach (array_keys(self::ZONES) as $zone) {
                $current = $period === self::CURRENT_PERIOD;
                if ($current && $zone > 5) {
                    continue;
                }
                $bill = ! $current || $zone <= 3;
                $this->on($this->readDate($period, $zone).' 08:30', fn () => $this->readZone($zone, $period, $bill, skipShare: $bill ? 0.0 : 0.08));
            }
        }
    }

    private function readDate(string $period, int $zone): string
    {
        return $period.'-'.(18 + $zone);
    }

    private function readZone(int $zone, string $period, bool $bill, float $skipShare): void
    {
        $reader = $this->users[$this->readerFor($zone)];
        $clerk = $this->users[$zone <= 4 ? 'paolo' : 'joy'];

        $customers = MeterReadings::customers($this->company, $period, $this->ids['zones'][$zone], '')->with('meterType')->orderBy('account_number')->get();
        foreach ($customers as $customer) {
            if ($skipShare > 0 && $this->chance($skipShare)) {
                continue;
            }
            $i = $this->indexOf[$customer->id];
            $this->attempt("{$period} reading of {$customer->account_number}", fn () => $this->readMeter($i, $customer, $period, $bill, $reader, $clerk));
        }
    }

    /**
     * Reads one meter as the reader would, then (when $bill) confirms a reading that needs review and
     * issues the bill, as the supervisor and clerk would.
     */
    private function readMeter(int $i, Customer $customer, string $period, bool $bill, User $reader, User $clerk): void
    {
        $state = $this->state[$i];
        $code = ReadingCode::Normal;

        if ($customer->status === CustomerStatus::Connected) {
            $usage = $this->usageFor($i, $period, $customer);
            if ($state['billed'] >= 3) {
                $usage = match (true) {
                    $this->chance(0.01) => $usage * 3,   // a leak on the customer's side
                    $this->chance(0.005) => 0,           // house empty for the month
                    default => $usage,
                };
                $code = match (true) {
                    $this->chance(0.015) => ReadingCode::NoAccess,
                    $this->chance(0.003) => ReadingCode::Defective,
                    default => ReadingCode::Normal,
                };
            }
            $this->meters[$i] += $usage;
        }

        $highest = $customer->meterType?->highestReading();
        if ($highest !== null && $this->meters[$i] > $highest) {
            $this->meters[$i] -= $highest + 1;
            $this->state[$i]['rollover'] = true;
        }
        if ($this->state[$i]['rollover'] && ! $code->isEstimated()) {
            $code = ReadingCode::Rollover;
            $this->state[$i]['rollover'] = false;
        }

        // Now and then a reading is keyed in 100 too high; the bill is voided and issued again the next day.
        $typo = $bill && $code === ReadingCode::Normal && $customer->status === CustomerStatus::Connected && $this->chance(0.0015)
            && ($highest === null || $highest >= $this->meters[$i] + 100) ? 100 : 0;

        $reading = MeterReadings::save($customer, $period, $code->isEstimated() ? '' : (string) ($this->meters[$i] + $typo), $code, $this->today(), $reader);

        if (! $bill) {
            return;
        }

        if ($reading->status === MeterReadingStatus::Review) {
            MeterReadings::confirm($reading, $this->users['liza']);
        }
        $invoice = BillingRun::generateFor($reading->fresh(), $this->today(), $clerk);
        $this->state[$i]['billed']++;
        $this->afterBill($i, $invoice);

        if ($typo > 0) {
            $this->on($this->later(1, 1)->setTime(9, 30), fn () => $this->correctReading($i, $period, $reader));
        }
        if ($code === ReadingCode::Defective) {
            $this->on($this->workday($this->later(2, 6)), function () use ($i): void {
                if (WorkOrderRules::whyNot(WorkOrderType::MeterReplacement, $this->customer($i)) !== null) {
                    return;
                }
                $order = $this->submit($i, WorkOrderType::MeterReplacement, $this->frontDesk(), $this->technician(), 'Meter found defective during the monthly reading.');
                $this->on($this->later(2, 8), fn () => $this->completeReplacement($order, $i, 'defective'));
            });
        }
    }

    /**
     * Voids the bill with the mistyped reading and issues it again with the right one.
     */
    private function correctReading(int $i, string $period, User $reader): void
    {
        $customer = $this->customer($i);
        $wrong = BillingRun::existingBill($customer, $period);
        if ($wrong === null) {
            return;
        }

        Ledger::voidInvoice($wrong, 'Present reading keyed in 100 cu.m. too high. Bill issued again with the corrected reading.', $this->users['liza']);
        $reading = MeterReadings::save($customer, $period, (string) $this->meters[$i], ReadingCode::Normal, $this->today(), $reader, confirmed: true);
        $this->afterBill($i, BillingRun::generateFor($reading, $this->today(), $this->users['paolo']));
    }

    private function usageFor(int $i, string $period, Customer $customer): int
    {
        $years = (int) substr($period, 0, 4) - 2021;
        $usage = $this->specs[$i]['usage'] * self::SEASON[(int) substr($period, 5, 2)] * (1 + 0.015 * $years) * mt_rand(82, 118) / 100;

        // Installed during the month: only part of a month's water.
        if ($customer->meter_installed_on?->format('Y-m') === $period) {
            $usage *= mt_rand(25, 75) / 100;
        }

        return max((int) round($usage), 0);
    }

    /* ---------------------------------------------------------------- Payments ----------------------------------------------------------------- */

    /**
     * Plans how the customer pays the bill just issued, by their payment habit:
     * prompt (before the due date), late (after it, surcharged), partial (60%, catching up every fourth bill),
     * erratic (on time, late, catching up or not at all), advance (six months ahead whenever the credit runs out),
     * chronic (now and then pays everything owed), abandon (pays promptly until the month they walk away).
     */
    private function afterBill(int $i, Invoice $invoice): void
    {
        $spec = $this->specs[$i];
        $profile = $spec['profile'];
        if ($profile === 'abandon') {
            if ($invoice->billing_period >= $spec['stopAt']) {
                return;
            }
            $profile = 'prompt';
        }

        $id = $invoice->id;
        $payBill = fn (int $from, int $to, float $share = 1.0) => $this->on($this->later($from, $to), fn () => $this->payInvoice($i, $id, $share));
        $payAll = fn (int $from, int $to) => $this->on($this->later($from, $to), fn () => $this->payAll($i, planned: true));

        match ($profile) {
            'prompt' => $this->chance(0.96) ? $payBill(1, 12) : $payBill(16, 25),
            'late' => $this->chance(0.88) ? $payBill(16, 32) : null,
            'partial' => ++$this->state[$i]['partial'] % 4 === 0 ? $payAll(5, 12) : $payBill(4, 12, 0.6),
            'erratic' => match (true) {
                ($roll = mt_rand(1, 100)) <= 60 => $payBill(2, 14),
                $roll <= 75 => $payBill(16, 28),
                $roll <= 85 => $payAll(5, 20),
                default => null,
            },
            'advance' => Money::toCents($invoice->balance) > 0
                ? $this->on($this->later(1, 5), fn () => $this->pay($i, Money::fromCents((int) ceil(Money::toCents($invoice->balance) * 6 / 10000) * 10000), notes: 'Advance payment for the next six months.'))
                : null,
            'chronic' => $this->chance(0.3) ? $payAll(3, 25) : null,
            default => null,
        };
    }

    /**
     * Pays the share of the bill still owed (rounded down to whole pesos for partial payments).
     */
    private function payInvoice(int $i, int $invoiceId, float $share = 1.0): void
    {
        $invoice = Invoice::query()->find($invoiceId);
        if ($invoice === null || ! $invoice->status->isOpen() || $this->customer($i)->status === CustomerStatus::Disconnected) {
            return;
        }

        $cents = Money::toCents($invoice->balance);
        if ($share < 1) {
            $cents = intdiv((int) round($cents * $share), 100) * 100;
        }
        if ($cents > 0) {
            $this->pay($i, Money::fromCents($cents));
        }
    }

    /**
     * Pays everything the customer owes on open bills. A planned (habitual) payment is skipped while
     * the customer is disconnected; settling to be reconnected is planned separately.
     */
    private function payAll(int $i, ?PaymentMethod $method = null, ?string $notes = null, bool $planned = false): void
    {
        $customer = $this->customer($i);
        if ($planned && $customer->status === CustomerStatus::Disconnected) {
            return;
        }

        $owed = Ledger::summary($customer)['owed'];
        if ($owed > 0) {
            $this->pay($i, Money::fromCents($owed), $method, $notes);
        }
    }

    private function pay(int $i, string $amount, ?PaymentMethod $method = null, ?string $notes = null): Payment
    {
        $method ??= $this->methodFor($i);
        $attributes = [
            'paid_on' => $this->today(),
            'amount' => $amount,
            'method' => $method->value,
            'reference_number' => match ($method) {
                PaymentMethod::Cash => null,
                PaymentMethod::Check => $this->any(['BPI', 'BDO', 'Metrobank', 'Landbank']).' check no. '.mt_rand(100000, 999999),
                PaymentMethod::BankTransfer => 'TRX-'.mt_rand(10000000, 99999999),
                PaymentMethod::EWallet => $this->any(['GCash', 'Maya']).' ref. '.mt_rand(1000000000, 2000000000),
            },
            'notes' => $notes,
        ];
        $payment = Ledger::recordPayment($this->customer($i), $attributes, $this->users[$this->cashier()]);

        if ($method === PaymentMethod::Check && $this->chance(0.02)) {
            // A bounced check: the bills are open again until paid in cash.
            $this->on($this->later(3, 6), fn () => Ledger::voidPayment($payment->fresh(), 'Check returned by the bank: drawn against insufficient funds.', $this->users['liza']));
            $this->on($this->later(8, 12), fn () => $this->payAll($i, PaymentMethod::Cash, 'Replaces the returned check.'));
        } elseif ($this->chance(0.003)) {
            // Posted twice at the counter; the second receipt is voided.
            $duplicate = Ledger::recordPayment($this->customer($i), $attributes, $this->users[$this->cashier()]);
            $this->on($this->later(0, 2), fn () => Ledger::voidPayment($duplicate->fresh(), 'Posted twice by mistake; the customer paid once.', $this->users['liza']));
        }

        $this->afterPayment($i);

        return $payment;
    }

    /**
     * A disconnected customer who has settled what was overdue is reconnected.
     */
    private function afterPayment(int $i): void
    {
        if (! $this->state[$i]['reconnect']) {
            return;
        }

        $customer = $this->customer($i);
        if ($customer->status !== CustomerStatus::Disconnected || Disconnections::overdueCount($customer, $this->today()) > 0) {
            return;
        }

        $this->state[$i]['reconnect'] = false;
        $this->on($this->workday($this->later(1, 2))->setTime(9, 0), function () use ($i): void {
            if (WorkOrderRules::whyNot(WorkOrderType::Reconnection, $this->customer($i)) !== null) {
                return;
            }
            $technician = $this->technician();
            $order = $this->submit($i, WorkOrderType::Reconnection, $this->frontDesk(), $technician, 'Customer settled all overdue bills and asked to restore service.');
            $this->on($this->later(1, 3), function () use ($order, $i, $technician): void {
                // Now and then the meter moved while it was sealed (flagged for tampering).
                $reading = $this->chance(0.08) ? $this->advanceMeter($i, mt_rand(3, 15)) : $this->meters[$i];
                $this->completeOrder($order, ['reconnection_reading' => (string) $reading, 'seal_removed' => 'Seal from the disconnection'], $this->onDutyOr($technician));
                $this->on($this->later(0, 10), fn () => $this->payAll($i, notes: 'Reconnection fee.'));
            });
        });
    }

    private function methodFor(int $i): PaymentMethod
    {
        if ($this->specs[$i]['account'] !== 'res') {
            return $this->chance(0.6) ? PaymentMethod::BankTransfer : PaymentMethod::Check;
        }

        // E-wallets catch on over the years.
        $years = (int) $this->clock->format('Y') - 2021;

        return PaymentMethod::from($this->pick([
            PaymentMethod::Cash->value => 60 - 7 * $years,
            PaymentMethod::EWallet->value => 12 + 7 * $years,
            PaymentMethod::BankTransfer->value => 18,
            PaymentMethod::Check->value => 6,
        ]));
    }

    /* ------------------------------------------------------------- Disconnections -------------------------------------------------------------- */

    /**
     * Each month: notices to everyone with two bills overdue (around the 10th), disconnection orders
     * once the deadline has passed (around the 19th), and the technicians' visits a day or two later.
     */
    private function scheduleCollections(): void
    {
        foreach ($this->periods('2021-12', self::CURRENT_PERIOD) as $period) {
            $this->on($this->workday(Carbon::parse("{$period}-10 10:00", self::TIMEZONE)), fn () => $this->issueNotices());
            $this->on($this->workday(Carbon::parse("{$period}-19 08:00", self::TIMEZONE)), fn () => $this->createDisconnectionOrders());
        }
    }

    private function issueNotices(): void
    {
        $ids = Disconnections::eligible($this->company, $this->today())->pluck('customers.id')->all();
        if ($ids === []) {
            return;
        }

        $result = Disconnections::issue($this->company, $ids, $this->users['liza'], email: true);
        foreach ($result['issued'] as $notice) {
            $i = $this->indexOf[$notice->customer_id];
            if ($this->chance(0.2)) {
                $notice->update(['printed_at' => now()]);
            }

            $responds = $this->chance(self::RESPONDS_TO_NOTICE[$this->currentProfile($i)]);
            if ($this->chance(0.04)) {
                // The supervisor withdraws the notice on a promise to pay.
                $this->on($this->later(1, 3), fn () => Disconnections::cancel($notice->fresh(), 'Customer promised to pay within the week; approved by the Billing Supervisor.', $this->users['liza']));
            }
            if ($responds) {
                // Some pay only after the disconnection order was created, which cancels it.
                $this->on($this->later(1, 10), fn () => $this->payAll($i, notes: 'Paid after receiving the notice of disconnection.'));
            }
        }
    }

    private function createDisconnectionOrders(): void
    {
        $noticeIds = $this->company->disconnectionNotices()
            ->where('status', DisconnectionNoticeStatus::Pending)
            ->where('deadline', '<', $this->today())
            ->orderBy('notice_number')
            ->pluck('id')
            ->all();

        $technicians = array_values(array_filter(self::TECHNICIANS, fn (string $key): bool => $this->onDuty[$key] ?? false));
        foreach (array_chunk($noticeIds, max((int) ceil(count($noticeIds) / count($technicians)), 1)) as $n => $chunk) {
            $technician = $technicians[$n % count($technicians)];
            $created = Disconnections::createWorkOrders($this->company, $chunk, $this->users[$technician]->id, $this->users['liza']);
            foreach ($created['created'] as $order) {
                $this->on($this->workday($this->later(1, 2))->setTime(mt_rand(9, 15), mt_rand(0, 59)), fn () => $this->disconnect($order, $technician));
            }
        }
    }

    private function disconnect(WorkOrder $order, string $technician): void
    {
        $order = $order->fresh();
        if (! $order->status->isOpen()) {
            return;
        }

        $customer = $order->customer;
        if ($reason = Disconnections::whyNotComplete($order, $customer)) {
            // Paid in the meantime: the nightly review settles the notice. On hold: staff cancel the order.
            if (Disconnections::holdReason($customer, $this->today()) !== null) {
                $this->cancel($order, "Customer is on hold: {$reason}.");
                Disconnections::workOrderCancelled($order, "Customer is on hold: {$reason}.", $this->users['liza']);
            }

            return;
        }

        $i = $this->indexOf[$customer->id];
        $this->completeOrder($order, [
            'reason' => 'non_payment',
            'final_reading' => (string) $this->advanceMeter($i, mt_rand(2, 10)),
            'seal_number' => 'SEAL-'.mt_rand(10000, 99999),
        ], $this->onDutyOr($technician));

        if ($this->chance(self::RECONNECTS[$this->currentProfile($i)])) {
            $this->state[$i]['reconnect'] = true;
            $this->on($this->later(3, 90), fn () => $this->payAll($i, notes: 'Full settlement for reconnection.'));
        }
    }

    /**
     * The customer's habit now: someone who walked away no longer pays.
     */
    private function currentProfile(int $i): string
    {
        $spec = $this->specs[$i];

        return $spec['profile'] === 'abandon' && $this->clock->format('Y-m') < $spec['stopAt'] ? 'prompt' : $spec['profile'];
    }

    /* -------------------------------------------------------------- Work orders ---------------------------------------------------------------- */

    /**
     * Service requests through the five years, and the 2024 programme replacing the phased-out plastic meters.
     */
    private function scheduleServiceOrders(): void
    {
        foreach ($this->periods('2021-11', self::CURRENT_PERIOD) as $period) {
            for ($n = mt_rand(10, 18); $n > 0; $n--) {
                $at = $this->workday(Carbon::parse("{$period}-".str_pad((string) mt_rand(1, 28), 2, '0', STR_PAD_LEFT), self::TIMEZONE))->setTime(mt_rand(8, 15), mt_rand(0, 59));
                $this->on($at, fn () => $this->serviceOrder());
            }
        }

        $this->on('2024-02-01 08:00', function (): void {
            foreach ($this->specs as $i => $spec) {
                if ($spec['meter'] === 'plastic' && isset($this->customerIds[$i])) {
                    $this->on($this->workday($this->later(0, 120)), function () use ($i): void {
                        $customer = $this->customer($i);
                        if ($customer->status !== CustomerStatus::Connected || WorkOrderRules::whyNot(WorkOrderType::MeterReplacement, $customer) !== null) {
                            return;
                        }
                        $order = $this->submit($i, WorkOrderType::MeterReplacement, 'liza', $this->technician(), 'Replacement programme: phased-out 1/2" plastic meter.');
                        $this->on($this->later(1, 10), fn () => $this->completeReplacement($order, $i, 'end_of_life'));
                    });
                }
            }
        });
    }

    private function serviceOrder(): void
    {
        $type = WorkOrderType::from($this->pick([
            WorkOrderType::PipeRepair->value => 14, WorkOrderType::LeakRepair->value => 14, WorkOrderType::LowWaterPressure->value => 10,
            WorkOrderType::WaterQualityComplaint->value => 8, WorkOrderType::MeterRepair->value => 8, WorkOrderType::MeterRelocation->value => 6,
            WorkOrderType::MeterReadingVerification->value => 10, WorkOrderType::BillingComplaint->value => 14,
            WorkOrderType::ChangeOfOwnership->value => 5, WorkOrderType::Disconnection->value => 2, WorkOrderType::MeterReplacement->value => 3,
        ]));

        $i = $this->randomCustomer(fn (Customer $customer): bool => WorkOrderRules::whyNot($type, $customer) === null
            && $this->state[$this->indexOf[$customer->id]]['billed'] >= 2);
        if ($i === null) {
            return;
        }

        $technician = $type === WorkOrderType::BillingComplaint ? 'liza' : $this->technician();
        $order = $this->submit($i, $type, $this->frontDesk(), $technician, $this->describe($type));

        $roll = mt_rand(1, 100);
        $complete = $type === WorkOrderType::MeterReplacement
            ? fn () => $this->completeReplacement($order, $i, $this->any(['stuck', 'damaged', 'tampered', 'upgrade']))
            : fn () => $this->completeService($order);

        match (true) {
            $roll <= 3 => $this->on($this->later(1, 5), fn () => $this->cancel($order, $this->any(['Customer withdrew the request.', 'Duplicate of an earlier request.', 'Problem is inside the property; the customer\'s own plumber will fix it.']))),
            $roll <= 8 => [
                $this->on($this->later(1, 3), fn () => $this->hold($order, $this->any(['Customer not home; to be rescheduled.', 'Waiting for materials from the warehouse.', 'Needs a road excavation permit.']))),
                $this->on($this->later(15, 40), $complete),
            ],
            default => $this->on($this->later(1, 10), $complete),
        };
    }

    /**
     * Completes a service order with answers that fit its type, then follows up on its outcome:
     * follow-up orders are worked on, and adjustment flags are mostly resolved with a credit memo.
     */
    private function completeService(WorkOrder $order): void
    {
        $order = $order->fresh();
        if (! $order->status->isOpen()) {
            return;
        }
        if ($order->order_type === WorkOrderType::MeterReplacement) {
            $this->completeReplacement($order, $this->indexOf[$order->customer_id], 'defective');

            return;
        }

        $customer = $order->customer;
        $i = $this->indexOf[$customer->id];
        $fields = $this->serviceFields($order, $customer, $i);
        $this->completeOrder($order, $fields, $this->onDutyOr($this->staffKey($order->assignedTo) ?? $this->technician()));
        $order = $order->fresh();

        foreach ($order->followUps()->get() as $followUp) {
            $this->on($this->later(2, 10), fn () => $this->completeService($followUp));
        }

        if (in_array($order->review_flag, Adjustments::RESOLVABLE_FLAGS, true) && $this->chance(0.85)) {
            $this->on($this->later(1, 5), fn () => $this->resolveFlag($order));
        }

        if ($order->order_type === WorkOrderType::ChangeOfOwnership) {
            $this->on($this->later(0, 7), fn () => $this->payAll($i, notes: 'Change of ownership fee.'));
        }
        if ($order->order_type === WorkOrderType::Disconnection && $this->chance(0.5)) {
            // Asked to be cut off (vacant, moving out); half come back later.
            $this->state[$i]['reconnect'] = true;
            $this->on($this->later(30, 240), function () use ($i): void {
                $this->payAll($i, notes: 'Settled before asking to restore service.');
                $this->afterPayment($i);
            });
        }
    }

    /**
     * @return array<string, string>
     */
    private function serviceFields(WorkOrder $order, Customer $customer, int $i): array
    {
        $reading = (string) $this->meters[$i];
        $lastBilled = $customer->meterReadings()->whereNotNull('invoice_id')->whereNotNull('present_reading')->latest('billing_period')->first();

        return match ($order->order_type) {
            WorkOrderType::PipeRepair => [
                'pipe_size' => $this->any(['1/2"', '3/4"', '1"', '2"']), 'length_replaced' => (string) (mt_rand(5, 60) / 10),
                'cause' => $this->any(['Corroded GI pipe', 'Pipe crushed by a truck', 'Old PVC pipe cracked', 'Tree roots']),
                'materials' => $this->any(['2.5 m 1/2" PE pipe, 2 couplings', '1 m 3/4" PVC pipe, 1 elbow', '3 m 1" HDPE pipe, 2 couplings']),
            ],
            WorkOrderType::LeakRepair => [
                'leak_location' => $this->pick(['before_meter' => 45, 'after_meter' => 35, 'main_line' => 20]),
                'cause' => $this->any(['Loose union at the service connection', 'Burst pipe under the kitchen (customer side)', 'Cracked main line valve', 'Worn gasket at the meter']),
                'action_taken' => $this->any(['Replaced the union and gasket.', 'Shut the valve; the customer\'s plumber replaced the pipe.', 'Replaced the valve and tested the line.']),
                'materials' => $this->any(['1 pc union, 1 gasket', '1 gate valve', '']),
                'reading_before' => $reading, 'reading_after' => $reading,
            ],
            WorkOrderType::LowWaterPressure => ($resolved = $this->chance(0.75)) ? [
                'measured_pressure' => (string) mt_rand(6, 15), 'cause' => 'Clogged strainer at the meter',
                'action_taken' => 'Cleaned the strainer and flushed the service line.', 'resolved' => 'yes',
            ] : [
                'measured_pressure' => (string) mt_rand(2, 6), 'cause' => 'Leak on the service line before the meter',
                'action_taken' => 'Isolated the line; the pipe needs work.', 'resolved' => 'no',
                'follow_up' => $this->any([WorkOrderType::LeakRepair->value, WorkOrderType::PipeRepair->value]),
            ],
            WorkOrderType::WaterQualityComplaint => [
                'complaint_type' => $this->any(['color', 'odor', 'taste', 'particles']),
                ...($this->chance(0.8) ? ['sample_taken' => 'yes', 'sample_id' => 'WQ-'.$this->clock->format('Ymd').'-'.mt_rand(10, 99)] : ['sample_taken' => 'no']),
                'result' => $this->pick(['pass' => 70, 'fail' => 15, 'pending_lab' => 15]),
                'action_taken' => $this->any(['Flushed the line; water clear after 10 minutes.', 'Advised the household to boil water; main line flushing scheduled.', 'Flushed the line; sample sent to the lab.']),
            ],
            WorkOrderType::MeterRepair => ($outcome = $this->pick(['repaired' => 75, 'not_repairable' => 25])) === 'repaired' ? [
                'findings' => $this->any(['Register stuck intermittently.', 'Worn gears in the register.', 'Fogged meter glass.']),
                'action_taken' => 'Cleaned and re-calibrated the register.', 'outcome' => 'repaired', 'reading_before' => $reading, 'reading_after' => $reading,
            ] : [
                'findings' => 'Cracked meter body; register fogged.', 'action_taken' => 'Tested the meter; recommended replacement.',
                'outcome' => 'not_repairable', 'reading_before' => $reading,
            ],
            WorkOrderType::MeterRelocation => [
                'new_location' => $this->any(['Sidewalk, right of the gate.', 'Left of the front gate.', 'Moved outside the new fence.']),
                ...($this->chance(0.6) ? ['latitude' => (string) round(13.9411 + mt_rand(-400, 400) / 10000, 4), 'longitude' => (string) round(121.1631 + mt_rand(-400, 400) / 10000, 4)] : []),
                'reading_before' => $reading, 'reading_after' => $reading,
            ],
            WorkOrderType::MeterReadingVerification => $this->verificationFields($lastBilled === null ? null : (int) $lastBilled->present_reading, $reading),
            WorkOrderType::BillingComplaint => [
                'bill_period' => (string) $customer->meterReadings()->whereNotNull('invoice_id')->max('billing_period'),
                'issue' => $this->any(['wrong_reading', 'wrong_rate', 'wrong_charges', 'payment_not_posted', 'other']),
                'findings' => $this->any(['Charges match the customer\'s charge type; explained the bill.', 'Reader misread a digit; over-billed.', 'Usage is within the usual range, but the customer asked for a re-read.']),
                ...(($outcome = $this->pick(['no_error' => 50, 'adjustment_needed' => 30, 'reread_needed' => 20])) === 'adjustment_needed'
                    ? ['outcome' => $outcome, 'adjustment_amount' => mt_rand(5, 40).'0.00']
                    : ['outcome' => $outcome]),
            ],
            WorkOrderType::ChangeOfOwnership => [
                'new_first_name' => $this->any(self::FIRST_NAMES), 'new_last_name' => $this->any(self::LAST_NAMES),
                'new_email' => $this->chance(0.7) ? 'new.owner.'.$i.'.'.mt_rand(100, 999).'@mail.test' : '',
                'new_phone' => sprintf('09%02d %03d %04d', mt_rand(15, 99), mt_rand(100, 999), mt_rand(0, 9999)),
                'effective_date' => $this->today(), 'final_reading' => $reading,
            ],
            WorkOrderType::Disconnection => [
                'reason' => $this->any(['customer_request', 'vacant_property']),
                'final_reading' => (string) $this->advanceMeter($i, mt_rand(0, 6)), 'seal_number' => 'SEAL-'.mt_rand(10000, 99999),
            ],
            default => throw new RuntimeException("No completion answers for {$order->order_type->label()}."),
        };
    }

    /**
     * @return array<string, string>
     */
    private function verificationFields(?int $billed, string $now): array
    {
        $outcome = $billed === null ? 'correct' : $this->pick(['correct' => 60, 'wrong' => 25, 'meter_faulty' => 15]);
        $billed ??= (int) $now;

        return [
            'disputed_reading' => (string) $billed,
            'verified_reading' => match ($outcome) {
                'wrong' => (string) max($billed - mt_rand(3, 15), 0),
                'correct' => (string) $billed,
                default => $now,
            },
            'outcome' => $outcome,
        ];
    }

    /**
     * Resolves a billing or leak adjustment flag with a credit memo on the bill it concerns, or on the
     * latest open bill. Left open when nothing is owed.
     */
    private function resolveFlag(WorkOrder $order): void
    {
        $order = $order->fresh();
        $customer = $order->customer;
        $fields = $order->completion_data['fields'] ?? [];

        $invoice = isset($fields['bill_period']) ? BillingRun::existingBill($customer, $fields['bill_period']) : null;
        if ($invoice === null || ! $invoice->status->isOpen()) {
            $invoice = $customer->invoices()->where('kind', InvoiceKind::Monthly)->whereIn('status', InvoiceStatus::openValues())->latest('invoice_date')->first();
        }
        if ($invoice === null) {
            return;
        }

        $balance = Money::toCents($invoice->balance);
        $suggestion = Adjustments::suggestionFor($order);
        $cents = match (true) {
            $suggestion['amount'] !== null => Money::toCents($suggestion['amount']),
            $order->order_type === WorkOrderType::MeterReadingVerification => max((int) $fields['disputed_reading'] - (int) $fields['verified_reading'], 1) * 3500,
            default => (int) round($balance * 0.4),
        };
        $cents = intdiv(min($cents, $balance), 100) * 100;
        if ($cents <= 0) {
            return;
        }

        Adjustments::issue($invoice, $suggestion['type'], $suggestion['category'], $suggestion['reason'], $cents, $this->today(), $order, $this->users['liza']);
    }

    private function completeReplacement(WorkOrder $order, int $i, string $reason): void
    {
        $order = $order->fresh();
        if (! $order->status->isOpen()) {
            return;
        }

        $spec = $this->specs[$i];
        $newType = in_array($spec['meter'], ['half', 'plastic'], true) ? 'half' : $spec['meter'];
        $replacement = ++$this->state[$i]['replacements'];

        $this->completeOrder($order, [
            'reason' => $reason,
            'old_final_reading' => (string) $this->advanceMeter($i, mt_rand(1, 8)),
            'new_meter_number' => sprintf('MTR-%d%04d-%s', $spec['zone'], $i, chr(64 + $replacement)),
            'new_serial_number' => 'SN-'.mt_rand(10000000, 99999999),
            'new_meter_type_id' => (string) $this->ids['meterTypes'][$newType],
            'new_initial_reading' => '0',
        ], $this->onDutyOr($this->staffKey($order->assignedTo) ?? $this->technician()));

        $this->specs[$i]['meter'] = $newType;
        $this->meters[$i] = 0;
        $this->state[$i]['rollover'] = false;
    }

    /**
     * Submits a work order, like the Work Orders page does.
     */
    private function submit(int $i, WorkOrderType $type, string $requestedBy, ?string $assignee, string $description): WorkOrder
    {
        $customer = $this->customer($i);
        if ($reason = WorkOrderRules::whyNot($type, $customer)) {
            throw new RuntimeException("{$type->label()} for {$customer->account_number} can't be submitted: {$reason}.");
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

        return $order;
    }

    /**
     * Completes a work order through the same checks as the completion form, with the type's usual fee.
     *
     * @param  array<string, string>  $fields
     */
    private function completeOrder(WorkOrder $order, array $fields, string $completedBy, ?string $remarks = null): void
    {
        $order = $order->fresh();
        if (! $order->status->isOpen()) {
            return;
        }

        $type = $order->order_type;
        $user = $this->users[$completedBy];
        $fee = $this->company->workOrderFees()->where('order_type', $type)->value('amount');
        $photos = $type->allowsPhotos() && ($type->requiresPhoto() || $this->chance(0.4))
            ? [['data' => $this->cachedImage(strtoupper($type->label()), [52, 120, 88]), 'mime' => 'image/png', 'name' => Str::slug($type->label()).'-'.$order->order_number.'.png']]
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
            throw new RuntimeException("Work order {$order->order_number} can't be completed: ".json_encode($checked['errors']));
        }

        WorkOrderCompletion::complete($order, $checked['data'], $user, $photos, self::TIMEZONE);
    }

    private function hold(WorkOrder $order, string $reason): void
    {
        $order = $order->fresh();
        if (! $order->status->isOpen()) {
            return;
        }
        $order->update(['status' => WorkOrderStatus::OnHold, 'hold_reason' => $reason]);
        $order->log($this->users['kim'], 'on_hold', "Put on hold: {$reason}");
    }

    private function cancel(WorkOrder $order, string $reason): void
    {
        $order = $order->fresh();
        if (! $order->status->isOpen()) {
            return;
        }
        $order->update(['status' => WorkOrderStatus::Cancelled, 'hold_reason' => null, 'cancel_reason' => $reason]);
        $order->log($this->users['kim'], 'cancelled', "Cancelled: {$reason}");
    }

    private function describe(WorkOrderType $type): string
    {
        return $this->any(match ($type) {
            WorkOrderType::PipeRepair => ['Leaking service pipe along the sidewalk.', 'Water seeping from the road near the meter box.', 'Pipe exposed and cracked after the road works.'],
            WorkOrderType::LeakRepair => ['Water leaking at the service connection.', 'Bill went up; customer suspects a hidden leak.', 'Wet spot on the lawn near the meter.'],
            WorkOrderType::LowWaterPressure => ['Weak water flow in the mornings.', 'Almost no water on the second floor.', 'Pressure dropped since last week.'],
            WorkOrderType::WaterQualityComplaint => ['Brownish water after the road works.', 'Water smells of chlorine.', 'Small particles in the water.'],
            WorkOrderType::MeterRepair => ['Meter register stops now and then.', 'Meter glass fogged; dials hard to read.', 'Meter makes a grinding noise.'],
            WorkOrderType::MeterRelocation => ['Move the meter out of the garage to the sidewalk.', 'Meter is behind a new fence; move it outside.'],
            WorkOrderType::MeterReadingVerification => ['Customer says the last bill is too high.', 'Customer disputes the last reading.'],
            WorkOrderType::BillingComplaint => ['Customer asks about the charges on the last bill.', 'Customer says the last reading is wrong.', 'Customer says a payment is not on the bill.'],
            WorkOrderType::ChangeOfOwnership => ['Property sold; new owner to take over the account.', 'Account to be transferred to the heirs.'],
            WorkOrderType::Disconnection => ['Customer is moving out and asked to close the service.', 'House is vacant; owner asked to disconnect.'],
            WorkOrderType::MeterReplacement => ['Customer asked for a meter upgrade.', 'Meter body damaged by a vehicle.', 'Seal tampered; replace the meter.'],
            default => ['Service request.'],
        });
    }

    /* ---------------------------------------------------------- Billing corrections ------------------------------------------------------------ */

    /**
     * Each month a goodwill credit memo (a quarter of them voided later) and a debit memo, on open bills.
     */
    private function scheduleBillingCorrections(): void
    {
        foreach ($this->periods('2021-11', self::CURRENT_PERIOD) as $period) {
            $this->on($this->workday(Carbon::parse("{$period}-0".mt_rand(3, 8).' 10:00', self::TIMEZONE)), function (): void {
                $invoice = $this->randomOpenInvoice();
                if ($invoice === null) {
                    return;
                }
                $cents = intdiv(min(mt_rand(5, 30) * 1000, Money::toCents($invoice->balance)), 100) * 100;
                if ($cents <= 0) {
                    return;
                }
                $memo = Adjustments::issue($invoice, AdjustmentType::Credit, AdjustmentReason::Rebate, $this->any([
                    'Goodwill credit for the service interruption.', 'Rebate for the water interruption during the main line repair.', 'Senior citizen discount not applied last month.',
                ]), $cents, $this->today(), null, $this->users['liza']);

                if ($this->chance(0.25)) {
                    $this->on($this->later(2, 5), fn () => $this->voidMemo($memo, 'Interruption credits are given only for outages over 24 hours.'));
                }
            });

            $this->on($this->workday(Carbon::parse("{$period}-1".mt_rand(2, 6).' 11:00', self::TIMEZONE)), function (): void {
                $invoice = $this->randomOpenInvoice();
                if ($invoice === null) {
                    return;
                }
                [$reason, $description] = $this->any([
                    [AdjustmentReason::AdditionalCharge, 'Replacement of a meter seal broken by the customer.'],
                    [AdjustmentReason::AdditionalCharge, 'Excavation restoration charged to the customer.'],
                    [AdjustmentReason::Undercharge, 'Previous bill used the residential rate instead of commercial.'],
                ]);
                Adjustments::issue($invoice, AdjustmentType::Debit, $reason, $description, mt_rand(10, 50) * 1000, $this->today(), null, $this->users['liza']);
            });
        }
    }

    private function voidMemo(InvoiceAdjustment $memo, string $reason): void
    {
        $memo = $memo->fresh();
        if ($memo->isVoid() || $memo->invoice->status === InvoiceStatus::Void) {
            return;
        }
        Adjustments::void($memo, $reason, $this->users['liza']);
    }

    private function randomOpenInvoice(): ?Invoice
    {
        $ids = $this->company->invoices()->where('kind', InvoiceKind::Monthly)->whereIn('status', InvoiceStatus::openValues())->latest('id')->limit(300)->pluck('id')->all();

        return $ids === [] ? null : Invoice::query()->find($this->any($ids));
    }

    /* ----------------------------------------------------------------- Replay ------------------------------------------------------------------ */

    private function on(Carbon|string $at, Closure $event): void
    {
        $at = $at instanceof Carbon ? $at->copy() : Carbon::parse($at, self::TIMEZONE);
        if (isset($this->clock) && $at->lessThan($this->clock)) {
            $at = $this->clock->copy();
        }
        if ($at->toDateString() > self::LAST_DAY) {
            return;
        }

        $sequence = $this->sequence++;
        $this->queue->insert([$at, $event], [-$at->getTimestamp(), -$sequence]);
    }

    /**
     * Runs the events in date order, one transaction a day. Each night the scheduled jobs run as they
     * would in production: overdue invoices are surcharged (00:30) and open notices re-checked (00:45).
     */
    private function replay(): void
    {
        // So the nightly jobs run up to the last day.
        $this->on(self::LAST_DAY.' 18:00', fn () => null);
        $month = $this->clock->format('Y-m');
        DB::beginTransaction();

        while (! $this->queue->isEmpty()) {
            [$at, $event] = $this->queue->extract();

            while ($this->clock->toDateString() < $at->toDateString()) {
                DB::commit();
                DB::beginTransaction();

                $this->clock = $this->clock->copy()->addDay()->setTime(0, 30);
                Carbon::setTestNow($this->clock);
                $this->attempt('nightly surcharges', fn () => Surcharges::applyToOverdueInvoices($this->company->fresh(), $this->today()));
                Carbon::setTestNow($this->clock->copy()->setTime(0, 45));
                $this->attempt('nightly notice review', fn () => Disconnections::reviewAll($this->company));

                if ($this->clock->format('Y-m') !== $month) {
                    $this->progress($month);
                    $month = $this->clock->format('Y-m');
                }
            }

            $this->clock = $at->copy();
            Carbon::setTestNow($this->clock);
            $this->attempt('event', $event);
        }

        DB::commit();
        $this->progress($month);
    }

    /**
     * Runs a step in its own savepoint; when it fails, only it is rolled back, and it is listed at the end.
     */
    private function attempt(string $what, Closure $step): void
    {
        try {
            DB::transaction($step);
        } catch (Throwable $exception) {
            $this->failures[] = "{$this->clock->format('Y-m-d H:i')} {$what}: ".Str::limit($exception->getMessage(), 300);
        }
    }

    private function progress(string $month): void
    {
        $this->command?->line(sprintf(
            '%s  customers %d · invoices %d · payments %d · work orders %d · failures %d · %s',
            $month,
            $this->company->customers()->count(),
            $this->company->invoices()->count(),
            $this->company->payments()->count(),
            $this->company->workOrders()->count(),
            count($this->failures),
            gmdate('H:i:s', (int) (microtime(true) - $this->startedAt)),
        ));
    }

    /* ---------------------------------------------------------------- Helpers ------------------------------------------------------------------ */

    /**
     * @return list<string> "2021-10", "2021-11", … up to and including $to.
     */
    private function periods(string $from, string $to): array
    {
        $periods = [];
        for ($date = Carbon::parse("{$from}-01"); $date->format('Y-m') <= $to; $date->addMonth()) {
            $periods[] = $date->format('Y-m');
        }

        return $periods;
    }

    /**
     * A random time $from to $to days from now, during office hours.
     */
    private function later(int $from, int $to): Carbon
    {
        return $this->clock->copy()->addDays(mt_rand($from, $to))->setTime(mt_rand(8, 16), mt_rand(0, 59));
    }

    private function workday(Carbon $date): Carbon
    {
        while ($date->isWeekend()) {
            $date = $date->addDay();
        }

        return $date;
    }

    private function today(): string
    {
        return $this->clock->toDateString();
    }

    private function customer(int $i): Customer
    {
        return Customer::query()->findOrFail($this->customerIds[$i]);
    }

    /**
     * A random connected customer that passes $accepts, or null after a few tries.
     *
     * @param  Closure(Customer): bool  $accepts
     */
    private function randomCustomer(Closure $accepts): ?int
    {
        $ids = $this->company->customers()->where('status', CustomerStatus::Connected)->pluck('id')->all();
        for ($try = 0; $ids !== [] && $try < 15; $try++) {
            $customer = Customer::query()->find($this->any($ids));
            if ($customer !== null && $accepts($customer)) {
                return $this->indexOf[$customer->id];
            }
        }

        return null;
    }

    /**
     * Adds water used since the last reading, unless that would roll the meter over.
     */
    private function advanceMeter(int $i, int $add): int
    {
        $highest = $this->customer($i)->meterType?->highestReading();
        if ($highest === null || $highest >= $this->meters[$i] + $add) {
            $this->meters[$i] += $add;
        }

        return $this->meters[$i];
    }

    private function readerFor(int $zone): string
    {
        return match (true) {
            $zone <= 2 => 'ben',
            $zone <= 5 => 'rico',
            default => ($this->onDuty['jun'] ?? false) ? 'jun' : 'lito',
        };
    }

    private function technician(): string
    {
        return $this->any(array_values(array_filter(self::TECHNICIANS, fn (string $key): bool => $this->onDuty[$key] ?? false)));
    }

    private function onDutyOr(string $key): string
    {
        return ($this->onDuty[$key] ?? false) ? $key : $this->technician();
    }

    private function frontDesk(): string
    {
        return ($this->onDuty['sofia'] ?? false) && $this->chance(0.5) ? 'sofia' : 'kim';
    }

    private function cashier(): string
    {
        $cashiers = array_values(array_filter(self::CASHIERS, fn (string $key): bool => $this->onDuty[$key] ?? false));

        return $cashiers[(int) $this->clock->format('j') % count($cashiers)];
    }

    private function staffKey(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }
        $key = array_search($user->id, array_map(fn (User $staff): int => $staff->id, $this->users), true);

        return $key === false ? null : $key;
    }

    private function chance(float $probability): bool
    {
        return mt_rand() / mt_getrandmax() < $probability;
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return T
     */
    private function any(array $items): mixed
    {
        return $items[mt_rand(0, count($items) - 1)];
    }

    /**
     * @param  array<string, int>  $weights
     */
    private function pick(array $weights): string
    {
        $roll = mt_rand(1, max(array_sum($weights), 1));
        foreach ($weights as $key => $weight) {
            if (($roll -= $weight) <= 0) {
                return (string) $key;
            }
        }

        return (string) array_key_last($weights);
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
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    private function cachedImage(string $label, array $rgb): string
    {
        return $this->images[$label] ??= $this->image(320, 240, $rgb, $label);
    }

    /**
     * A plain PNG with a centered label, for logos and photos.
     *
     * @param  array{0: int, 1: int, 2: int}  $rgb
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
        $table = fn (string $name): int => DB::table($name)->count();

        $rows = [
            ['Customers', $count('customers')],
            ['Invoices (incl. void)', $count('invoices')],
            ['Invoice lines', $table('invoice_lines')],
            ['Payments (incl. void)', $count('payments')],
            ['Payment allocations', $table('payment_allocations')],
            ['Credit and debit memos', $table('invoice_adjustments')],
            ['Meter readings', $count('meterReadings')],
            ['Work orders', $count('workOrders')],
            ['Work order activity', $table('work_order_activities')],
            ['Work order readings', $table('work_order_readings')],
            ['Disconnection notices', $count('disconnectionNotices')],
            ['Emails in the outbox', $table('email_outbox')],
        ];
        $rows[] = ['Total', array_sum(array_column($rows, 1))];

        $this->command?->info('Aqua Vista Water District: five years of data. Today in the data is '.self::LAST_DAY.'.');
        $this->command?->table(['', 'Rows'], $rows);

        if ($this->failures !== []) {
            $this->command?->warn(count($this->failures).' event(s) failed and were skipped. The first ones:');
            foreach (array_slice($this->failures, 0, 40) as $failure) {
                $this->command?->line("  {$failure}");
            }
        }

        $this->command?->info('Sign in as admin (or a staff username such as liza.reyes, password in LoadTestSeeder::PASSWORD).');
    }
}
