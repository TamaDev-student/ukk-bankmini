<?php

namespace Database\Factories;

use App\Models\Major;
use App\Models\SchoolClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchoolClass>
 */
class SchoolClassFactory extends Factory
{
    protected $model = SchoolClass::class;

    public function definition(): array
    {
        return [
            'name' => fake()->randomElement([
                'X RPL 1',
                'XI RPL 1',
                'XII RPL 1',
            ]),
            'major_id' => Major::factory(),
        ];
    }
}
