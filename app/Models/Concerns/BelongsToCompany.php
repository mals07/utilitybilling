<?php

namespace App\Models\Concerns;

use App\Models\Scopes\CompanyScope;
use App\Support\CompanyIsolationException;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Records owned by one company. Queries see only the current company's records (CompanyScope), and
 * a record created without a company_id gets the current company's.
 *
 * Writes are held to the same line: while a company is current, a record can only be saved or deleted
 * for that company, and no record ever moves to another company. Code with no current company (seeders,
 * tests' factories) may write, but must name the company itself.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        // "saving" runs before "creating", so the company is filled in here, ahead of the check.
        static::saving(function (Model $model): void {
            if ($model->exists && $model->isDirty('company_id')) {
                throw CompanyIsolationException::movedCompany($model);
            }

            if (! $model->exists && $model->getAttribute('company_id') === null && CurrentCompany::id() !== null) {
                $model->setAttribute('company_id', CurrentCompany::id());
            }

            self::ensureCurrentCompanyOwns($model);
        });

        static::deleting(fn (Model $model) => self::ensureCurrentCompanyOwns($model));
    }

    private static function ensureCurrentCompanyOwns(Model $model): void
    {
        if (CurrentCompany::isAcrossAll() || ! CurrentCompany::isKnown()) {
            return;
        }

        // Loaded with a select that left company_id out; the company scope already limited that query.
        if ($model->exists && ! array_key_exists('company_id', $model->getAttributes())) {
            return;
        }

        $companyId = CurrentCompany::id();

        if ($companyId === null || (int) $model->getAttribute('company_id') !== $companyId) {
            throw CompanyIsolationException::otherCompany($model);
        }
    }
}
