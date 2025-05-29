<?php

namespace Database\Factories;

use App\Models\PriceScheme;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PriceScheme>
 */
class PriceSchemeFactory extends Factory
{
    protected $model = PriceScheme::class;

    public function definition(): array
    {
        $purchasePrice = $this->faker->randomFloat(2, 1000, 5000);
        $discountPercentage = $this->faker->randomFloat(2, 0, 50);
        $sellingPrice = $purchasePrice * (1 + (100 - $discountPercentage) / 100);
        $profitAmount = $sellingPrice - $purchasePrice;

        return [
            'user_id' => User::factory(),
            'product_id' => Product::factory(),
            'level_name' => $this->faker->randomElement(['Grosir', 'Reseller', 'Agen', 'Distributor']),
            'level_order' => $this->faker->numberBetween(1, 5),
            'discount_percentage' => $discountPercentage,
            'purchase_price' => $purchasePrice,
            'selling_price' => $sellingPrice,
            'profit_amount' => $profitAmount,
            'notes' => $this->faker->optional()->sentence(),
        ];
    }
}
