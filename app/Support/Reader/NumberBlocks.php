<?php

namespace App\Support\Reader;

use App\Enums\NumberGapReason;
use App\Models\Device;
use App\Models\Invoice;
use App\Models\InvoiceNumberBlock;
use App\Models\InvoiceNumberGap;
use App\Models\RoutePackage;
use Illuminate\Support\Facades\DB;

/**
 * Invoice numbers reserved for a meter reader's device, taken from the company's invoice sequence
 * (App\Models\Concerns\NumberedPerCompany), so offline bills and office bills never share a number.
 *
 * - A device keeps its open block across packages while enough numbers are left in it; otherwise the
 *   unused rest is handed back (recorded as Unused) and a new block is reserved.
 * - Every reserved number ends up as an invoice or as a gap: skipped on the device, unused, or
 *   rejected by a supervisor.
 */
final class NumberBlocks
{
    /**
     * The fewest numbers reserved at a time.
     */
    public const MINIMUM_SIZE = 10;

    /**
     * The block a new package prints with: the device's open block when it still has enough numbers,
     * else a new one sized for the route's unread meters plus the company's margin.
     *
     * @param  ?int  $releaseFrom  The first number of the device's open block it hasn't used.
     * @return array{first: string, last: string, next: string}
     */
    public static function forPackage(Device $device, RoutePackage $package, int $unread, ?int $releaseFrom): array
    {
        $company = $device->company;
        $needed = (int) ceil($unread * (1 + $company->reader_block_margin / 100));

        $open = $device->numberBlocks()->whereNull('closed_at')->lockForUpdate()->orderBy('id')->get();
        $current = $releaseFrom === null ? null : $open->first(fn (InvoiceNumberBlock $block): bool => $block->contains($releaseFrom));

        if ($current !== null && $current->last_number - $releaseFrom + 1 >= max($needed, 1)) {
            $current->update(['route_package_id' => $package->id]);

            return self::describe($current, $releaseFrom);
        }

        foreach ($open as $block) {
            self::close($block, $block->is($current) ? $releaseFrom : null);
        }

        [$first, $last] = Invoice::reserveNumbers($company, max($needed, self::MINIMUM_SIZE));
        $block = InvoiceNumberBlock::query()->create([
            'company_id' => $company->id,
            'device_id' => $device->id,
            'route_package_id' => $package->id,
            'first_number' => $first,
            'last_number' => $last,
        ]);

        return self::describe($block, $first);
    }

    /**
     * Hands back the device's open block at the end of its route, without downloading a new package:
     * numbers from $releaseFrom on are recorded as Unused and the block is closed.
     *
     * @param  int  $releaseFrom  The first number the device hasn't used; one past the last when it used them all.
     * @return array{first: string, last: string, unused: int}
     *
     * @throws ReaderException When the number isn't in one of the device's open blocks.
     */
    public static function release(Device $device, int $releaseFrom): array
    {
        return DB::transaction(function () use ($device, $releaseFrom): array {
            $block = $device->numberBlocks()
                ->whereNull('closed_at')
                ->where('first_number', '<=', $releaseFrom)
                ->where('last_number', '>=', $releaseFrom - 1)
                ->lockForUpdate()
                ->first();

            if ($block === null) {
                throw new ReaderException('That number isn\'t in an open block of this device. Download the route again to see its numbers.');
            }

            $gapsBefore = $block->gaps()->count();
            self::close($block, $releaseFrom);

            return [
                'first' => InvoiceNumberBlock::format($block->first_number),
                'last' => InvoiceNumberBlock::format($block->last_number),
                'unused' => $block->gaps()->count() - $gapsBefore,
            ];
        });
    }

    /**
     * How many of the block's numbers are neither on an invoice nor recorded as a gap yet: unused,
     * or on bills the device printed but hasn't synced.
     */
    public static function unaccountedCount(InvoiceNumberBlock $block): int
    {
        return $block->last_number - $block->first_number + 1 - count(self::takenNumbers($block));
    }

    /**
     * Closes a block, recording the numbers from $unusedFrom on that never became an invoice as Unused.
     * Numbers before it stay open to a later sync: the device may still be holding bills printed with them.
     */
    public static function close(InvoiceNumberBlock $block, ?int $unusedFrom): void
    {
        if ($unusedFrom !== null) {
            $taken = self::takenNumbers($block);

            for ($number = $unusedFrom; $number <= $block->last_number; $number++) {
                if (! isset($taken[$number])) {
                    self::recordGap($block, $number, NumberGapReason::Unused);
                }
            }
        }

        $block->update(['closed_at' => now()]);
    }

    /**
     * The device's block that holds the number, if the number is still free: not on an invoice and
     * not recorded as a gap.
     */
    public static function freeBlockFor(Device $device, string $invoiceNumber): ?InvoiceNumberBlock
    {
        if (! preg_match('/^\d{1,18}$/', $invoiceNumber)) {
            return null;
        }

        $number = (int) $invoiceNumber;
        $formatted = InvoiceNumberBlock::format($number);
        $block = $device->numberBlocks()->where('first_number', '<=', $number)->where('last_number', '>=', $number)->first();

        if ($block === null
            || Invoice::query()->where('company_id', $device->company_id)->where('invoice_number', $formatted)->exists()
            || InvoiceNumberGap::query()->where('company_id', $device->company_id)->where('invoice_number', $formatted)->exists()) {
            return null;
        }

        return $block;
    }

    public static function recordGap(InvoiceNumberBlock $block, int $number, NumberGapReason $reason, ?string $note = null): void
    {
        InvoiceNumberGap::query()->create([
            'company_id' => $block->company_id,
            'invoice_number_block_id' => $block->id,
            'invoice_number' => InvoiceNumberBlock::format($number),
            'reason' => $reason,
            'note' => $note === null ? null : mb_substr($note, 0, 500),
        ]);
    }

    /**
     * Numbers of the block already on an invoice or recorded as a gap, as keys.
     *
     * @return array<int, true>
     */
    private static function takenNumbers(InvoiceNumberBlock $block): array
    {
        $range = fn (string $class) => $class::query()
            ->where('company_id', $block->company_id)
            ->whereBetween('invoice_number', [InvoiceNumberBlock::format($block->first_number), InvoiceNumberBlock::format($block->last_number)])
            ->pluck('invoice_number');

        return $range(Invoice::class)->concat($range(InvoiceNumberGap::class))
            ->mapWithKeys(fn (string $number): array => [(int) $number => true])
            ->all();
    }

    /**
     * @return array{first: string, last: string, next: string}
     */
    private static function describe(InvoiceNumberBlock $block, int $next): array
    {
        return [
            'first' => InvoiceNumberBlock::format($block->first_number),
            'last' => InvoiceNumberBlock::format($block->last_number),
            'next' => InvoiceNumberBlock::format($next),
        ];
    }
}
