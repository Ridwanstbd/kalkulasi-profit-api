<?php

namespace Database\Factories;

use App\Models\CostComponent;
use App\Models\Product;
use App\Models\ProductCost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductCost>
 */
class ProductCostFactory extends Factory
{
    protected $model = ProductCost::class;

    public function definition(): array
    {
        $unitPrice = $this->faker->randomFloat(2, 100, 1000); // Contoh harga satuan
        $quantity = $this->faker->randomFloat(2, 1, 100);
        $conversionQty = $this->faker->randomFloat(2, 1, 10);
        $amount = $unitPrice * $quantity;

        return [
            'product_id' => Product::factory(),
            'cost_component_id' => CostComponent::factory(),
            'unit' => $this->faker->randomElement(['pcs', 'kg', 'ltr', 'box']),
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'conversion_qty' => $conversionQty,
            'amount' => $amount,
        ];
    }
}
