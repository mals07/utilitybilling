<?php

namespace App\Livewire\Customers;

use App\Enums\Access;
use App\Enums\Page;
use App\Livewire\Concerns\GuardsPageAccess;
use App\Models\CustomerImport;
use App\Support\CustomerImport\CustomerImporter;
use App\Support\CustomerImport\CustomerSpreadsheet;
use App\Support\Format;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Import Customers link and modal on the Customers page: download the template, upload a filled-in
 * Excel or CSV file, review the rows, then add the valid ones. Each import is kept in the history.
 */
class ImportCustomers extends Component
{
    use GuardsPageAccess, WithFileUploads;

    /**
     * Imports listed in the modal's history.
     */
    public const HISTORY_LIMIT = 10;

    /**
     * Skipped rows listed in the preview; the downloaded file has them all.
     */
    public const PREVIEW_LIMIT = 50;

    public bool $open = false;

    public ?TemporaryUploadedFile $file = null;

    /**
     * The checked file: how many rows are ready, and the skipped ones with their problem.
     *
     * @var array{fileName: string, readyCount: int, skippedCount: int, skipped: list<array{row: int, name: string, problem: string}>}|null
     */
    #[Locked]
    public ?array $preview = null;

    /**
     * The import just made, shown as the result.
     */
    #[Locked]
    public ?int $importedId = null;

    protected function page(): Page
    {
        return Page::Customers;
    }

    public function openImport(): void
    {
        if (! $this->allowed(Access::Add)) {
            return;
        }

        $this->resetImport();
        $this->open = true;
    }

    public function closeImport(): void
    {
        $this->open = false;
        $this->resetImport();
    }

    /**
     * Back to choosing a file.
     */
    public function resetImport(): void
    {
        $this->reset('file', 'preview', 'importedId');
        $this->resetValidation();
    }

    public function updatedFile(): void
    {
        $this->reset('preview', 'importedId');
        $this->resetValidation('file');

        if (! $this->allowed(Access::Add)) {
            $this->file = null;

            return;
        }

        $checked = $this->checkFile();
        if ($checked === null) {
            $this->file = null;

            return;
        }

        $this->preview = [
            'fileName' => $this->file->getClientOriginalName(),
            'readyCount' => count($checked['ready']),
            'skippedCount' => count($checked['skipped']),
            'skipped' => array_map(fn (array $row): array => [
                'row' => $row['row'],
                'name' => trim(($row['cells']['First Name'] ?? '').' '.($row['cells']['Last Name'] ?? '')),
                'problem' => $row['problem'],
            ], array_slice($checked['skipped'], 0, self::PREVIEW_LIMIT)),
        ];
    }

    /**
     * Adds the ready rows and skips the rest. The file is checked again first, since customers may
     * have been added or lookups changed since the preview.
     */
    public function import(): void
    {
        if (! $this->allowed(Access::Add) || $this->file === null || $this->preview === null) {
            return;
        }

        $checked = $this->checkFile();
        if ($checked === null) {
            $this->preview = null;

            return;
        }

        if ($checked['ready'] === []) {
            $this->updatedFile();

            return;
        }

        $import = (new CustomerImporter($this->company()))
            ->import($checked['ready'], $checked['skipped'], $this->file->getClientOriginalName(), $this->user());

        $this->reset('file', 'preview');
        $this->importedId = $import->id;
        unset($this->history);

        $this->dispatch('customers-imported');
        $this->flash($import->imported_count === 1 ? '1 customer imported.' : "{$import->imported_count} customers imported.");
    }

    /**
     * The skipped rows of the file being previewed, to fix before importing.
     */
    public function downloadProblems(): ?StreamedResponse
    {
        if (! $this->allowed(Access::Add) || $this->file === null) {
            return null;
        }

        $checked = $this->checkFile();
        if ($checked === null || $checked['skipped'] === []) {
            return null;
        }

        $name = pathinfo($this->file->getClientOriginalName(), PATHINFO_FILENAME).' - problems.xlsx';

        return CustomerSpreadsheet::problemsFile($name, $checked['skipped']);
    }

    /**
     * The rows a past import skipped.
     */
    public function downloadSkipped(int $importId): ?StreamedResponse
    {
        if (! $this->allowed(Access::Add)) {
            return null;
        }

        $import = $this->company()->customerImports()->find($importId);
        if ($import === null || $import->skipped_count === 0) {
            return null;
        }

        return CustomerSpreadsheet::skippedRows($import);
    }

    public function downloadTemplate(string $format = 'xlsx'): ?StreamedResponse
    {
        if (! $this->allowed(Access::Add)) {
            return null;
        }

        return CustomerSpreadsheet::template($this->company(), $format === 'csv' ? 'csv' : 'xlsx');
    }

    /**
     * Reads and checks the uploaded file, or shows why it can't be.
     *
     * @return array{
     *     ready: list<array{row: int, values: array<string, string>}>,
     *     skipped: list<array{row: int, cells: array<string, string>, problem: string}>,
     * }|null
     */
    private function checkFile(): ?array
    {
        $file = $this->file;
        $extension = strtolower($file?->getClientOriginalExtension() ?? '');

        $error = match (true) {
            $file === null => 'Choose a file to import.',
            ! in_array($extension, CustomerSpreadsheet::EXTENSIONS, true) => "\"{$file->getClientOriginalName()}\" is not an Excel (.xlsx) or CSV file.",
            $file->getSize() > CustomerSpreadsheet::MAX_KILOBYTES * 1024 => "\"{$file->getClientOriginalName()}\" is larger than ".(CustomerSpreadsheet::MAX_KILOBYTES / 1024).'MB.',
            default => null,
        };

        if ($error === null) {
            $read = CustomerSpreadsheet::read($file->getRealPath(), $extension);
            $error = $read['error'];
        }

        if ($error !== null) {
            $this->addError('file', $error);

            return null;
        }

        return (new CustomerImporter($this->company()))->check($read['rows']);
    }

    /**
     * @return Collection<int, CustomerImport>
     */
    #[Computed]
    public function history(): Collection
    {
        return $this->company()->customerImports()
            ->with('user:id,name')
            ->latest('id')
            ->limit(self::HISTORY_LIMIT)
            ->get(['id', 'user_id', 'file_name', 'imported_count', 'skipped_count', 'first_account_number', 'last_account_number', 'created_at']);
    }

    public function render(): View
    {
        return view('livewire.customers.import-customers', [
            'imported' => $this->importedId === null ? null : $this->history->firstWhere('id', $this->importedId),
            'timezone' => $this->company()->timezone ?? Format::DEFAULT_TIMEZONE,
        ]);
    }
}
