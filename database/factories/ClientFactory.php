<?php

namespace Database\Factories;

use App\Models\Client;
use App\Support\Fixtures\HistoricalClientFixture;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;

class ClientFactory extends Factory
{
    protected $model = Client::class;

    /** Seed pre-existing synthetic people; runtime creation must use the guarded model. */
    protected function store(Collection $results)
    {
        $results->each(function (Client $model) {
            if (! isset($this->connection)) {
                $model->setConnection($model->newQueryWithoutScopes()->getConnection()->getName());
            }
            if ($model->status === 'active' || $model->service_start_date !== null) {
                HistoricalClientFixture::save($model);
            } else {
                $model->save();
            }
            foreach ($model->getRelations() as $name => $items) {
                if ($items instanceof Enumerable && $items->isEmpty()) {
                    $model->unsetRelation($name);
                }
            }
            $this->createChildren($model);
        });
    }

    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'nhi_number' => $this->generateNhi(),
            'date_of_birth' => fake()->dateTimeBetween('-80 years', '-18 years'),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->optional(0.7)->safeEmail(),
            'address_line_1' => fake()->streetAddress(),
            'city' => fake()->city(),
            'postcode' => fake()->postcode(),
            // Deterministic: custody, assignment and ownership paths only accept
            // active clients. Use ->inactive() when a test needs one.
            'status' => 'active',
        ];
    }

    /**
     * Generate a valid NZ NHI number (3 letters + 4 numbers)
     */
    private function generateNhi(): string
    {
        $letters = strtoupper(fake()->randomLetter().fake()->randomLetter().fake()->randomLetter());
        $numbers = str_pad(fake()->numberBetween(0, 9999), 4, '0', STR_PAD_LEFT);

        return $letters.$numbers;
    }

    /**
     * Indicate that the client has portal access (user_id set)
     */
    public function withPortalAccess(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'user_id' => null, // Will be set after user creation
            ];
        });
    }

    /**
     * Indicate that the client is inactive
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }
}
