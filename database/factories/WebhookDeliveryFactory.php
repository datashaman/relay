<?php

namespace Database\Factories;

use App\Models\Source;
use App\Models\WebhookDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

class WebhookDeliveryFactory extends Factory
{
    protected $model = WebhookDelivery::class;

    public function definition(): array
    {
        return [
            'source_id' => Source::factory(),
            'external_delivery_id' => fake()->uuid(),
            'event_type' => 'issues',
            'action' => 'opened',
            'payload' => [],
            'processed_at' => null,
            'error' => null,
        ];
    }
}
