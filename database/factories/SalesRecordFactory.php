<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\SalesRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SalesRecord>
 */
class SalesRecordFactory extends Factory
{
    protected $model = SalesRecord::class;

    public function definition(): array
    {
        $month = $this->faker->numberBetween(1, 12);
        $year = $this->faker->numberBetween(2020, date('Y'));
        $numberOfSales = $this->faker->numberBetween(1, 100);
        $hpp = $this->faker->randomFloat(2, 5000, 50000);
        $sellingPrice = $hpp + $this->faker->randomFloat(2, 1000, 10000);

        return [
            'user_id' => User::factory(),
            'product_id' => Product::factory(),
            'month' => $month,
            'year' => $year,
            'number_of_sales' => $numberOfSales,
            'hpp' => $hpp,
            'selling_price' => $sellingPrice,
        ];
    }
}
