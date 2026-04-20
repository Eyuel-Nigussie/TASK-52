<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InventoryItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class InventoryItemFactory extends Factory
{
    protected $model = InventoryItem::class;

    public function definition(): array
    {
        return [
            'external_key'      => $this->faker->unique()->numerify('ITEM-####'),
            'name'              => $this->faker->words(2, true) . ' ' . $this->faker->randomElement(['Syringes', 'Bandages', 'Solution', 'Tablets']),
            'sku'               => strtoupper($this->faker->unique()->bothify('SKU-######')),
            'category'          => $this->faker->randomElement(['surgical', 'pharmacy', 'consumables', 'diagnostics']),
            'unit_of_measure'   => $this->faker->randomElement(['unit', 'box', 'ml', 'mg']),
            'safety_stock_days' => 14,
            'reorder_point'     => $this->faker->numberBetween(10, 100),
            'active'            => true,
        ];
    }
}
