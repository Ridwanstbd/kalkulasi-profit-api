<?php

namespace Database\Factories;

use App\Models\ExpenseCategory;
use App\Models\OperationalExpense;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OperationalExpense>
 */
class OperationalExpenseFactory extends Factory
{
    protected $model = OperationalExpense::class;

    public function definition()
    {
        $quantity = $this->faker->numberBetween(1, 20);
        $amount = $this->faker->randomFloat(2, 1000, 100000);
        $totalAmount = $quantity * $amount;

        return [
            'user_id' => User::factory(),
            'expense_category_id' => ExpenseCategory::factory(),
            'quantity' => $quantity,
            'unit' => $this->faker->randomElement(['pcs', 'liter', 'kg', 'meter']),
            'amount' => $amount,
            'year' => $this->faker->numberBetween(2020, date('Y')),
            'month' => $this->faker->numberBetween(1, 12),
            'total_amount' => $totalAmount,
        ];
    }
}
