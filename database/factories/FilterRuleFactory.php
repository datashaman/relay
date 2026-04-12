<?php

namespace Database\Factories;

use App\Models\FilterRule;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

class FilterRuleFactory extends Factory
{
    protected $model = FilterRule::class;

    public function definition(): array
    {
        return [
            'source_id' => Source::factory(),
            'include_labels' => null,
            'exclude_labels' => null,
            'unassigned_only' => false,
            'auto_accept_labels' => null,
        ];
    }
}
