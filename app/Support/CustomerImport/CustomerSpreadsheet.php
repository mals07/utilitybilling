<?php

namespace App\Support\CustomerImport;

use App\Enums\CustomerStatus;
use App\Models\City;
use App\Models\Company;
use App\Models\Country;
use App\Models\CustomerImport;
use App\Models\Province;
use DateTimeInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\CSV\Options as CsvReaderOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Options as XlsxReaderOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Options as XlsxWriterOptions;
use OpenSpout\Writer\XLSX\Validation\CellReference;
use OpenSpout\Writer\XLSX\Validation\Rules\ListValidationRule;
use OpenSpout\Writer\XLSX\Validation\ValidationDisplay;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Reads customer import files (Excel or CSV) and writes the template and skipped-rows files.
 */
final class CustomerSpreadsheet
{
    public const EXTENSIONS = ['xlsx', 'csv', 'txt'];

    public const MAX_KILOBYTES = 5 * 1024;

    /**
     * Sheet of the template listing the values its dropdowns offer.
     */
    private const LISTS_SHEET = 'Valid Values';

    /**
     * Reads the first sheet's rows, keyed by column name. Columns the import doesn't know are ignored.
     *
     * @return array{rows: list<array{row: int, cells: array<string, string>}>, error: ?string}
     */
    public static function read(string $path, string $extension): array
    {
        $fail = fn (string $error): array => ['rows' => [], 'error' => $error];

        try {
            $reader = $extension === 'xlsx' ? new XlsxReader(new XlsxReaderOptions(SHOULD_PRESERVE_EMPTY_ROWS: true)) : self::csvReader($path);
            $reader->open($path);
        } catch (Throwable) {
            return $fail('The file couldn\'t be read. Save it as an Excel workbook (.xlsx) or CSV and try again.');
        }

        try {
            return self::readRows($reader, $fail);
        } catch (Throwable) {
            return $fail('The file couldn\'t be read. Save it as an Excel workbook (.xlsx) or CSV and try again.');
        } finally {
            $reader->close();
        }
    }

    /**
     * @param  callable(string): array{rows: list<array{row: int, cells: array<string, string>}>, error: ?string}  $fail
     * @return array{rows: list<array{row: int, cells: array<string, string>}>, error: ?string}
     */
    private static function readRows(ReaderInterface $reader, callable $fail): array
    {
        $known = [];
        foreach (array_keys(CustomerImporter::COLUMNS) as $column) {
            $known[self::headerKey($column)] = $column;
        }

        $columns = null;
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $number = 0;
            foreach ($sheet->getRowIterator() as $row) {
                $number++;
                $cells = array_map(self::text(...), $row->toArray());
                if (implode('', $cells) === '') {
                    continue;
                }

                // The first filled row names the columns.
                if ($columns === null) {
                    $columns = [];
                    foreach ($cells as $index => $header) {
                        if (isset($known[self::headerKey($header)])) {
                            $columns[$index] = $known[self::headerKey($header)];
                        }
                    }
                    $missing = array_diff(CustomerImporter::REQUIRED, $columns);
                    if ($missing !== []) {
                        return $fail('The file is missing these columns: '.implode(', ', $missing).'. Download the template to see the expected columns.');
                    }

                    continue;
                }

                if (count($rows) === CustomerImporter::MAX_ROWS) {
                    return $fail('The file has more than '.number_format(CustomerImporter::MAX_ROWS).' customers. Split it into smaller files.');
                }

                $values = [];
                foreach ($columns as $index => $column) {
                    $values[$column] = $cells[$index] ?? '';
                }
                $rows[] = ['row' => $number, 'cells' => $values];
            }

            // Only the first sheet holds customers; the template's other sheets are for reference.
            break;
        }

        if ($rows === []) {
            return $fail('The file has no customers in it.');
        }

        return ['rows' => $rows, 'error' => null];
    }

    /**
     * Excel saves "CSV" in the Windows code page and "CSV UTF-8" in UTF-8, and some regions separate
     * values with semicolons; both are detected.
     */
    private static function csvReader(string $path): CsvReader
    {
        $contents = (string) file_get_contents($path);
        $contents = str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents;
        $firstLine = strtok($contents, "\r\n") ?: '';
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn (string $candidate): int => substr_count($firstLine, $candidate))->first();

        return new CsvReader(new CsvReaderOptions(
            FIELD_DELIMITER: $delimiter,
            ENCODING: mb_check_encoding($contents, 'UTF-8') ? 'UTF-8' : 'Windows-1252',
        ));
    }

    /**
     * "First Name *" and "first_name" both name the First Name column.
     */
    private static function headerKey(string $header): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($header));
    }

    private static function text(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            // Excel stores numbers typed into General cells as floats, e.g. a zip code 1000.0.
            is_float($value) && floor($value) === $value && abs($value) < 1e15 => (string) (int) $value,
            is_int($value), is_float($value), is_string($value) => trim((string) $value),
            default => '',
        };
    }

    /* -------------------------------- Writing -------------------------------- */

    /**
     * The template: the columns, text-formatted so Excel keeps leading zeros, with dropdowns of the
     * company's lookups and the locations.
     */
    public static function template(Company $company, string $format): StreamedResponse
    {
        $headers = array_map(
            fn (string $column): string => in_array($column, CustomerImporter::REQUIRED, true) ? "{$column} *" : $column,
            array_keys(CustomerImporter::COLUMNS),
        );

        if ($format === 'csv') {
            return self::download('customer-import-template.csv', function (string $path) use ($headers): void {
                $writer = new CsvWriter;
                $writer->openToFile($path);
                $writer->addRow(Row::fromValues($headers));
                $writer->close();
            });
        }

        return self::download('customer-import-template.xlsx', function (string $path) use ($company, $headers): void {
            $lists = self::lists($company);
            $options = new XlsxWriterOptions;
            $columnIndexes = array_flip(array_keys(CustomerImporter::COLUMNS));
            $listIndex = 0;
            foreach ($lists as $column => $values) {
                foreach (array_filter([$column, str_replace('Home ', 'Billing ', $column)]) as $target) {
                    if ($values === [] || ! isset($columnIndexes[$target])) {
                        continue;
                    }
                    $options->addValidation(
                        $columnIndexes[$target], 2, $columnIndexes[$target], CustomerImporter::MAX_ROWS + 1,
                        new ListValidationRule(new CellReference($listIndex, 2, $listIndex, count($values) + 1, sheetName: self::LISTS_SHEET)),
                        // openspout writes these into XML attributes unescaped, so they must not contain quotes.
                        new ValidationDisplay(errorTitle: $target, error: 'Pick a value from the list. The '.self::LISTS_SHEET.' sheet shows them all.'),
                    );
                }
                $listIndex++;
            }

            $writer = new XlsxWriter($options);
            $writer->openToFile($path);

            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Customers');
            $sheet->setColumnWidth(18, ...range(1, count($headers)));
            $writer->addRow(Row::fromValuesWithStyle($headers, self::headerStyle()));
            $blank = Row::fromValuesWithStyle(array_fill(0, count($headers), null), self::textStyle());
            for ($row = 0; $row < CustomerImporter::MAX_ROWS; $row++) {
                $writer->addRow($blank);
            }

            $sheet = $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName(self::LISTS_SHEET);
            $sheet->setColumnWidth(24, ...range(1, count($lists)));
            $writer->addRow(Row::fromValuesWithStyle(array_keys($lists), self::headerStyle()));
            $longest = max(array_map('count', $lists));
            for ($index = 0; $index < $longest; $index++) {
                $writer->addRow(Row::fromValues(array_map(fn (array $values): ?string => $values[$index] ?? null, array_values($lists))));
            }

            $sheet = $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName('Instructions');
            $sheet->setColumnWidth(110, 1);
            foreach (self::instructions() as $line) {
                $writer->addRow(Row::fromValues([$line]));
            }

            $writer->close();
        });
    }

    /**
     * The rows an import skipped, with the problem beside each, in the template's columns so the file
     * can be fixed and imported again.
     */
    public static function skippedRows(CustomerImport $import): StreamedResponse
    {
        $name = pathinfo($import->file_name, PATHINFO_FILENAME).' - skipped rows.xlsx';

        return self::problemsFile($name, $import->skipped_rows ?? []);
    }

    /**
     * @param  list<array{row: int, cells: array<string, string>, problem: string}>  $skipped
     */
    public static function problemsFile(string $name, array $skipped): StreamedResponse
    {
        return self::download($name, function (string $path) use ($skipped): void {
            $columns = array_keys(CustomerImporter::COLUMNS);
            $writer = new XlsxWriter;
            $writer->openToFile($path);

            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Skipped Rows');
            $sheet->setColumnWidth(8, 1);
            $sheet->setColumnWidth(60, 2);
            $sheet->setColumnWidth(18, ...range(3, count($columns) + 2));
            $writer->addRow(Row::fromValuesWithStyle(['Row', 'Problem', ...$columns], self::headerStyle()));
            foreach ($skipped as $row) {
                $cells = array_map(fn (string $column): string => $row['cells'][$column] ?? '', $columns);
                $writer->addRow(Row::fromValuesWithStyle([(string) $row['row'], $row['problem'], ...$cells], self::textStyle()));
            }

            $writer->close();
        });
    }

    /**
     * The template dropdowns' values, keyed by the column they fill.
     *
     * @return array<string, list<string>>
     */
    private static function lists(Company $company): array
    {
        $names = fn ($query): array => $query->orderBy('name')->pluck('name')->unique()->values()->all();

        return [
            'Account Type' => $names($company->accountTypes()->where('is_active', true)),
            'Status' => array_map(fn (CustomerStatus $status): string => $status->label(), CustomerStatus::cases()),
            'Charge Type' => $names($company->chargeTypes()),
            'Rate' => $names($company->rateTypes()),
            'Cluster Type' => $names($company->clusterTypes()->where('is_active', true)),
            'Home Country' => $names(Country::query()),
            'Home Province' => $names(Province::query()),
            'Home City' => $names(City::query()),
        ];
    }

    /**
     * @return list<string>
     */
    private static function instructions(): array
    {
        return [
            'How to fill in the Customers sheet',
            '',
            '• One customer per row. Columns marked * are required.',
            '• Account Type, Charge Type, Rate and Cluster Type must match a name in the "'.self::LISTS_SHEET.'" sheet. Pick from the dropdown.',
            '• Status may be Pending, Connected or Disconnected. Left blank, it is Pending.',
            '• Country left blank is your company\'s country.',
            '• The billing address is optional. Once any part of it is filled, fill all of it.',
            '• Latitude and Longitude are optional; give both or neither.',
            '• Account numbers are given automatically, in row order.',
            '• Up to '.number_format(CustomerImporter::MAX_ROWS).' customers per file.',
            '• A row with the same first name, last name, home address and email as an existing customer, or as an earlier row, is skipped as a duplicate.',
            '',
            'Using CSV instead? Keep the same column names in the first row, and save as "CSV UTF-8" so letters like ñ stay intact.',
        ];
    }

    private static function headerStyle(): Style
    {
        return new Style(fontBold: true, backgroundColor: 'E9E9EE', format: '@');
    }

    private static function textStyle(): Style
    {
        return new Style(format: '@');
    }

    /**
     * Writes the file to a temporary path, then sends it and removes it.
     *
     * @param  callable(string): void  $write
     */
    private static function download(string $name, callable $write): StreamedResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'customer-import');
        $write($path);

        return response()->streamDownload(function () use ($path): void {
            readfile($path);
            unlink($path);
        }, $name);
    }
}
