<?php

namespace App\Models\Scopes;

use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Limits queries to the current company's records, so a query that forgets to filter by company
 * still can't reach another company's data.
 */
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! CurrentCompany::isKnown()) {
            return;
        }

        $companyId = CurrentCompany::id();

        if ($companyId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('company_id'), $companyId);
    }
}
