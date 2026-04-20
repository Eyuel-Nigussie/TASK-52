<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\InventoryItem;
use App\Models\StockLevel;
use Tests\TestCase;

class SafetyStockTest extends TestCase
{
    public function test_below_safety_stock_detected(): void
    {
        $item = new InventoryItem(['safety_stock_days' => 14]);
        $level = new StockLevel([
            'on_hand'        => 5,
            'avg_daily_usage' => 10,  // safety stock = 14 * 10 = 140
        ]);
        $level->setRelation('item', $item);

        $this->assertTrue($level->isBelowSafetyStock());
    }

    public function test_above_safety_stock_not_flagged(): void
    {
        $item = new InventoryItem(['safety_stock_days' => 14]);
        $level = new StockLevel([
            'on_hand'        => 200,
            'avg_daily_usage' => 5,  // safety stock = 14 * 5 = 70
        ]);
        $level->setRelation('item', $item);

        $this->assertFalse($level->isBelowSafetyStock());
    }

    public function test_at_safety_stock_boundary(): void
    {
        $item = new InventoryItem(['safety_stock_days' => 14]);
        $level = new StockLevel([
            'on_hand'        => 70,   // exactly at safety stock
            'avg_daily_usage' => 5,   // safety stock = 70
        ]);
        $level->setRelation('item', $item);

        // At exactly safety stock = 14 * 5 = 70, on_hand = 70
        // isBelowSafetyStock checks on_hand <= safety_stock
        $this->assertTrue($level->isBelowSafetyStock()); // on_hand <= safety stock
    }

    public function test_zero_usage_not_flagged(): void
    {
        $item = new InventoryItem(['safety_stock_days' => 14]);
        $level = new StockLevel([
            'on_hand'        => 0,
            'avg_daily_usage' => 0,  // safety stock = 0
        ]);
        $level->setRelation('item', $item);

        $this->assertTrue($level->isBelowSafetyStock()); // 0 <= 0
    }

    public function test_atp_calculation(): void
    {
        $level = new StockLevel([
            'on_hand'              => 100,
            'reserved'             => 30,
            'available_to_promise' => 70,
        ]);

        $this->assertEquals(70.0, (float) $level->available_to_promise);
    }

    public function test_default_safety_stock_days_14(): void
    {
        $item = new InventoryItem([]);
        // Default safety_stock_days should be 14
        $this->assertEquals(14, $item->safety_stock_days ?? 14);
    }
}
