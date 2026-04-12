<?php

namespace Database\Seeders;

use App\Enums\AutonomyLevel;
use App\Enums\AutonomyScope;
use App\Models\AutonomyConfig;
use Illuminate\Database\Seeder;

class DefaultAutonomyConfigSeeder extends Seeder
{
    public function run(): void
    {
        AutonomyConfig::firstOrCreate(
            [
                'scope' => AutonomyScope::Global,
                'scope_id' => null,
                'stage' => null,
            ],
            [
                'level' => AutonomyLevel::Supervised,
            ]
        );
    }
}
