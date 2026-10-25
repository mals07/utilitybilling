<?php

namespace App\Models\Concerns;

use App\Models\Scopes\CompanyScope;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Records owned by one company. Queries see only the current company's records (CompanyScope), and
 * a record created without a company_id gets the current company's.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('company_id') === null && CurrentCompany::id() !== null) {
                $model->setAttribute('company_id', CurrentCompany::id());
            }
        });
    }
}
