<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\StockLedger;
use App\Models\StockLevel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes `avg_daily_usage` and the available-to-promise (ATP) column for
 * every stock level row so the low-stock alerts dashboard reflects recent
 * consumption. Designed to be safe to rerun on any schedule.
 */
class RefreshStockLevels extends Command
{
    protected $signature = 'vetops:refresh-stock-levels
        {--window=14 : Rolling window (days) used for average-daily-usage computation}';

    protected $description = 'Refresh average-daily-usage and ATP across all stock levels.';

    public function handle(): int
    {
        $window = max(1, (int) $this->option('window'));
        $since  = now()->subDays($window);
        $touched = 0;

        StockLevel::query()->chunkById(200, function ($levels) use ($since, $window, &$touched) {
            foreach ($levels as $level) {
                $outboundSum = StockLedger::where('item_id', $level->item_id)
                    ->where('storeroom_id', $level->storeroom_id)
                    ->where('transaction_type', 'outbound')
                    ->where('created_at', '>=', $since)
                    ->sum('quantity');

                $avg = $window > 0 ? round(((float) $outboundSum) / $window, 3) : 0;
                $atp = max(0, (float) $level->on_hand - (float) $level->reserved);

                $level->forceFill([
                    'avg_daily_usage'      => $avg,
                    'available_to_promise' => $atp,
                ])->save();

                $touched++;
            }
        });

        $this->info("Refreshed {$touched} stock level row(s) over a {$window}-day window.");

        return self::SUCCESS;
    }
}
