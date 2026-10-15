<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Leaves large binary columns (uploaded images) out of queries that don't choose their own
 * columns, so loading a company or customer doesn't load its files. Select them explicitly
 * when they are needed.
 */
trait DefersBinaryColumns
{
    /**
     * Columns read by default, per table.
     *
     * @var array<string, list<string>>
     */
    private static array $defaultColumns = [];

    /**
     * @return list<string>
     */
    abstract public function binaryColumns(): array;

    public static function bootDefersBinaryColumns(): void
    {
        static::addGlobalScope('defer-binary-columns', function (Builder $builder): void {
            if ($builder->getQuery()->columns !== null) {
                return;
            }

            $model = $builder->getModel();
            $table = $model->getTable();

            self::$defaultColumns[$table] ??= array_values(
                array_diff(Schema::getColumnListing($table), $model->binaryColumns()),
            );

            $builder->select(array_map($model->qualifyColumn(...), self::$defaultColumns[$table]));
        });
    }
}
