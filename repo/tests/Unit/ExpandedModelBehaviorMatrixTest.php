<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\RentalAsset;
use App\Models\RentalTransaction;
use App\Models\StocktakeEntry;
use App\Models\User;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExpandedModelBehaviorMatrixTest extends TestCase
{
    #[DataProvider('varianceMatrix')]
    public function test_stocktake_variance_matrix(float $system, float $counted, float $expected): void
    {
        $actual = StocktakeEntry::calculateVariancePct($system, $counted);
        $this->assertEqualsWithDelta($expected, $actual, 0.0001);
    }

    public static function varianceMatrix(): iterable
    {
        // deterministic edge rows
        yield 'zero_zero' => [0.0, 0.0, 0.0];
        yield 'zero_positive' => [0.0, 1.0, 100.0];
        yield 'equal' => [10.0, 10.0, 0.0];
        yield 'plus_10' => [10.0, 11.0, 10.0];
        yield 'minus_10' => [10.0, 9.0, 10.0];

        // broad matrix (40 additional cases)
        for ($system = 1; $system <= 10; $system++) {
            foreach ([0.5, 0.8, 1.0, 1.2] as $factor) {
                $counted = $system * $factor;
                $expected = abs(($counted - $system) / $system * 100);
                yield "sys{$system}_f{$factor}" => [(float) $system, (float) $counted, (float) $expected];
            }
        }
    }

    #[DataProvider('depositMatrix')]
    public function test_rental_asset_deposit_matrix(float $replacementCost, float $rate, float $min, float $expected): void
    {
        config()->set('vetops.deposit_rate', $rate);
        config()->set('vetops.deposit_min', $min);

        $asset = new RentalAsset([
            'replacement_cost' => $replacementCost,
        ]);

        $this->assertEqualsWithDelta($expected, $asset->calculateDeposit(), 0.0001);
    }

    public static function depositMatrix(): iterable
    {
        // hard edges
        yield 'at_minimum' => [200.0, 0.2, 50.0, 50.0];
        yield 'below_minimum' => [100.0, 0.2, 50.0, 50.0];
        yield 'above_minimum' => [1000.0, 0.2, 50.0, 200.0];

        // 36 additional combinations
        $costs = [50.0, 100.0, 250.0, 500.0, 1000.0, 2500.0];
        $rates = [0.1, 0.2, 0.25];
        $mins = [25.0, 50.0];

        foreach ($costs as $c) {
            foreach ($rates as $r) {
                foreach ($mins as $m) {
                    $expected = max($c * $r, $m);
                    yield "c{$c}_r{$r}_m{$m}" => [$c, $r, $m, $expected];
                }
            }
        }
    }

    #[DataProvider('overdueMatrix')]
    public function test_rental_transaction_overdue_matrix(
        string $status,
        string $expectedReturn,
        int $thresholdHours,
        bool $isOverdue,
        int $minutes
    ): void {
        try {
            Carbon::setTestNow(Carbon::parse('2026-04-20 12:00:00'));
            config()->set('vetops.overdue_hours', $thresholdHours);

            $txForOverdueCheck = new RentalTransaction([
                'status' => $status,
                'expected_return_at' => Carbon::parse($expectedReturn),
            ]);
            $this->assertSame($isOverdue, $txForOverdueCheck->isOverdue());

            $txForMinutesCheck = new RentalTransaction([
                'status' => $status,
                'expected_return_at' => Carbon::parse($expectedReturn),
            ]);
            $this->assertSame($minutes, $txForMinutesCheck->overdueMinutes());
        } finally {
            Carbon::setTestNow();
        }
    }

    public static function overdueMatrix(): iterable
    {
        yield 'inactive_status_never_overdue' => ['returned', '2026-04-20 08:00:00', 2, false, 0];
        yield 'active_not_past_threshold' => ['active', '2026-04-20 11:00:00', 2, false, 0];
        yield 'active_exact_threshold' => ['active', '2026-04-20 10:00:00', 2, false, 0];
        yield 'active_past_threshold' => ['active', '2026-04-20 09:30:00', 2, true, 30];
        yield 'already_overdue_status' => ['overdue', '2026-04-20 09:00:00', 2, true, 60];

        $thresholds = [1, 2, 3, 4, 6];
        $offsets = [0, 30, 60, 90, 120, 180, 240, 300];
        foreach ($thresholds as $threshold) {
            foreach ($offsets as $offset) {
                $now = Carbon::parse('2026-04-20 12:00:00');
                $expected = $now->copy()->subMinutes($offset);
                $cutoff = $now->copy()->subHours($threshold);
                $isOverdue = $expected->lt($cutoff);
                $minutes = $isOverdue
                    ? (int) $expected->copy()->addHours($threshold)->diffInMinutes($now)
                    : 0;
                yield "thr{$threshold}_off{$offset}" => [
                    'active',
                    $expected->toDateTimeString(),
                    $threshold,
                    $isOverdue,
                    $minutes,
                ];
            }
        }
    }

    #[DataProvider('maskedPhoneMatrix')]
    public function test_user_masked_phone_matrix(?string $raw, ?string $expected): void
    {
        $user = new User();
        $user->phone_encrypted = $raw === null ? null : encrypt($raw);

        $this->assertSame($expected, $user->getMaskedPhone());
    }

    public static function maskedPhoneMatrix(): iterable
    {
        yield 'null' => [null, null];
        yield 'empty' => ['', '***-***-****'];
        yield 'ten_digits_plain' => ['5551234567', '(555) ***-4567'];
        yield 'formatted' => ['(212) 555-1234', '(212) ***-1234'];
        yield 'short_fallback' => ['12', '***-***-****'];

        $inputs = [
            '5550000001',
            '5550000002',
            '5550000003',
            '5550000004',
            '5550000005',
            '5550000006',
            '5550000007',
            '5550000008',
            '5550000009',
            '5550000010',
            '5550000011',
            '5550000012',
            '5550000013',
            '5550000014',
            '5550000015',
            '5550000016',
            '5550000017',
            '5550000018',
            '5550000019',
            '5550000020',
        ];

        foreach ($inputs as $in) {
            $last4 = substr($in, -4);
            $area = substr($in, 0, 3);
            yield "masked_{$in}" => [$in, "({$area}) ***-{$last4}"];
        }
    }
}
