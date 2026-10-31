<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the database refuse a record that points at another company's record, e.g. an invoice for
 * another company's customer: each link below becomes a foreign key on (company_id, <column>) to the
 * parent's (company_id, id), in place of the key on <column> alone.
 *
 * Only links that cascade or restrict on delete can be keyed this way. An "on delete set null" key
 * would also null company_id, so the optional links (who created a record, which work order it came
 * from, ...) keep their single-column keys and rely on the app's own company checks.
 */
return new class extends Migration
{
    /**
     * Each child table's links: column => [parent table, on delete].
     *
     * @var array<string, array<string, array{0: string, 1: string}>>
     */
    private const LINKS = [
        'customers' => [
            'account_type_id' => ['account_types', 'no action'],
            'charge_type_id' => ['charge_types', 'no action'],
            'meter_type_id' => ['meter_types', 'restrict'],
            'rate_type_id' => ['rate_types', 'no action'],
        ],
        'devices' => ['user_id' => ['users', 'cascade']],
        'disconnection_notices' => ['customer_id' => ['customers', 'cascade']],
        'invoice_adjustments' => [
            'customer_id' => ['customers', 'cascade'],
            'invoice_id' => ['invoices', 'cascade'],
        ],
        'invoice_number_blocks' => ['device_id' => ['devices', 'cascade']],
        'invoice_number_gaps' => ['invoice_number_block_id' => ['invoice_number_blocks', 'cascade']],
        'invoices' => ['customer_id' => ['customers', 'cascade']],
        'meter_readings' => ['customer_id' => ['customers', 'cascade']],
        'payments' => ['customer_id' => ['customers', 'cascade']],
        'reading_assignments' => [
            'cluster_type_id' => ['cluster_types', 'cascade'],
            'user_id' => ['users', 'cascade'],
        ],
        'route_packages' => ['device_id' => ['devices', 'cascade']],
        'sync_batches' => ['device_id' => ['devices', 'cascade']],
        'sync_discrepancies' => ['sync_item_id' => ['sync_items', 'cascade']],
        'sync_items' => [
            'device_id' => ['devices', 'cascade'],
            'sync_batch_id' => ['sync_batches', 'cascade'],
        ],
        'work_order_readings' => [
            'customer_id' => ['customers', 'cascade'],
            'work_order_id' => ['work_orders', 'cascade'],
        ],
        'work_orders' => ['customer_id' => ['customers', 'cascade']],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->ensureNoCrossCompanyLinks();

        // A composite key needs a unique index on the parent's (company_id, id) to point at.
        foreach ($this->parents() as $parent) {
            Schema::table($parent, function (Blueprint $table) {
                $table->unique(['company_id', 'id']);
            });
        }

        foreach (self::LINKS as $child => $links) {
            Schema::table($child, function (Blueprint $table) use ($links) {
                foreach ($links as $column => [$parent, $onDelete]) {
                    $table->dropForeign([$column]);
                    $table->foreign(['company_id', $column])->references(['company_id', 'id'])->on($parent)->onDelete($onDelete);
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::LINKS as $child => $links) {
            Schema::table($child, function (Blueprint $table) use ($links) {
                foreach ($links as $column => [$parent, $onDelete]) {
                    $table->dropForeign(['company_id', $column]);
                    $table->foreign($column)->references('id')->on($parent)->onDelete($onDelete);
                }
            });

            // MySQL adds an index for each composite key, which stays behind when the key is dropped.
            foreach (array_keys($links) as $column) {
                $index = "{$child}_company_id_{$column}_foreign";
                if (Schema::hasIndex($child, $index)) {
                    $this->keepCompanyIdIndexed($child, $index);
                    Schema::table($child, fn (Blueprint $table) => $table->dropIndex($index));
                }
            }
        }

        foreach ($this->parents() as $parent) {
            $this->keepCompanyIdIndexed($parent, "{$parent}_company_id_id_unique");
            Schema::table($parent, function (Blueprint $table) {
                $table->dropUnique(['company_id', 'id']);
            });
        }
    }

    /**
     * MySQL drops the index it made for the company_id key once another index starts with company_id,
     * and then refuses to drop that other index. Before dropping it, the original index is put back.
     */
    private function keepCompanyIdIndexed(string $table, string $dropping): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $indexedElsewhere = collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => $index['name'] !== $dropping && $index['columns'][0] === 'company_id');

        if (! $indexedElsewhere) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index('company_id', "{$table}_company_id_foreign"));
        }
    }

    /**
     * @return list<string>
     */
    private function parents(): array
    {
        return collect(self::LINKS)->flatten(1)->map(fn (array $link): string => $link[0])->unique()->values()->all();
    }

    /**
     * Stops before changing anything if a record already points at another company's record, naming
     * the links to fix, since the new keys can't be added over them.
     */
    private function ensureNoCrossCompanyLinks(): void
    {
        $found = [];

        foreach (self::LINKS as $child => $links) {
            foreach ($links as $column => [$parent]) {
                $count = DB::table("{$child} as child")
                    ->join("{$parent} as parent", 'parent.id', '=', "child.{$column}")
                    ->where(fn ($query) => $query->whereColumn('parent.company_id', '<>', 'child.company_id')->orWhereNull('parent.company_id'))
                    ->count();

                if ($count > 0) {
                    $found[] = "{$child}.{$column} ({$count})";
                }
            }
        }

        if ($found !== []) {
            throw new RuntimeException('These records point at another company\'s records; fix them before migrating: '.implode(', ', $found));
        }
    }
};
