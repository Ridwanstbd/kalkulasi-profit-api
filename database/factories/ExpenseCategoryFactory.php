<?php

namespace Database\Factories;

use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ExpenseCategory>
 */
class ExpenseCategoryFactory extends Factory
{
    protected $model = ExpenseCategory::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(), 
            'name' => $this->faker->words(2, true),
            'description' => $this->faker->optional()->sentence(),
            'is_salary' => $this->faker->boolean(10), 
        ];
    }
}
