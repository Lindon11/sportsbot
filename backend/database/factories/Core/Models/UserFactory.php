<?php

namespace Database\Factories\Core\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = User::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition(): array
    {
        $username = $this->faker->unique()->userName();
        $email = $this->faker->unique()->safeEmail();

        return [
            'name' => $this->faker->name(),
            'email' => $email,
            'password' => bcrypt('password'),
            // Provide last_active for tests that expect this core identity column
            'last_active' => now()->toDateTimeString(),
        ];
    }
}
