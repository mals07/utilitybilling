<?php

namespace App\Support\Reports;

use App\Enums\ReportColumnType;

/**
 * One column of a report table.
 */
final readonly class ReportColumn
{
    /**
     * @param  bool  $wide  Whether the column holds long text (names, reasons) that may wrap.
     */
    public function __construct(
        public string $label,
        public ReportColumnType $type = ReportColumnType::Text,
        public bool $wide = false,
    ) {}

    public static function text(string $label, bool $wide = false): self
    {
        return new self($label, ReportColumnType::Text, $wide);
    }

    public static function date(string $label): self
    {
        return new self($label, ReportColumnType::Date);
    }

    public static function amount(string $label): self
    {
        return new self($label, ReportColumnType::Amount);
    }

    public static function quantity(string $label): self
    {
        return new self($label, ReportColumnType::Quantity);
    }

    public static function count(string $label): self
    {
        return new self($label, ReportColumnType::Count);
    }

    public static function percent(string $label): self
    {
        return new self($label, ReportColumnType::Percent);
    }
}
