<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ContentItem;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContentItemFactory extends Factory
{
    protected $model = ContentItem::class;

    public function definition(): array
    {
        $title = $this->faker->sentence(4);
        return [
            'type'    => $this->faker->randomElement(['announcement', 'carousel']),
            'title'   => $title,
            'slug'    => Str::slug($title) . '-' . $this->faker->unique()->numerify('###'),
            'body'    => $this->faker->paragraphs(3, true),
            'excerpt' => $this->faker->sentence(),
            'status'  => 'draft',
            'version' => 1,
            'simhash' => str_pad('', 16, '0'),
            'priority' => 0,
        ];
    }

    public function published(): static
    {
        return $this->state([
            'status'       => 'published',
            'published_at' => now()->subDay(),
        ]);
    }
}
