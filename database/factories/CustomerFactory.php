<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\SchoolClass;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        $schoolClass = SchoolClass::factory()->create();

        return [
            'account_number' => fake()->unique()->numerify('##########'),
            'student_number' => fake()->unique()->numerify('########'),
            'student_name' => fake()->name(),
            'class_id' => $schoolClass->id,
            'status' => 'active',
            'security_pin' => Hash::make('123456'),
            'balance' => 500000,
        ];
    }
}
