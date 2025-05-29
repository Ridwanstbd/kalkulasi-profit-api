<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->words(3, true),
            'sku' => $this->faker->unique()->ean8(),
            'description' => $this->faker->sentence(),
            'hpp' => $this->faker->numberBetween(10000, 50000),
            'selling_price' => $this->faker->numberBetween(50000, 100000),
        ];
    }
}