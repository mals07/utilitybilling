<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Code reached another company's records, or a company's records with no company to check them against.
 * Always a bug: the code should run as a company (CurrentCompany::as) or say it works across all of them
 * (CurrentCompany::acrossAll).
 */
class CompanyIsolationException extends LogicException
{
    public static function noCurrentCompany(Model $model): self
    {
        return new self('Queried '.$model::class.' with no current company. Run the code as a company, or across all companies.');
    }

    public static function otherCompany(Model $model): self
    {
        return new self('Wrote '.$model::class.' #'.($model->getKey() ?? 'new').' for company '.$model->getAttribute('company_id').', not the current company.');
    }

    public static function movedCompany(Model $model): self
    {
        return new self($model::class.' #'.$model->getKey().' can\'t be moved to another company.');
    }
}
