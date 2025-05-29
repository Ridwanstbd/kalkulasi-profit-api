<?php

namespace Database\Factories;

use App\Models\CostComponent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CostComponent>
 */
class CostComponentFactory extends Factory
{
    protected $model = CostComponent::class;

    public function definition()
    {
        $types = ['direct_material', 'indirect_material','direct_labor', 'overhead', 'packaging', 'other'];
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->sentence,
            'component_type' => $this->faker->randomElement($types),
        ];
    }
}
