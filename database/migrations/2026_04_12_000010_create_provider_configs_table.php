<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_configs', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('scope');
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('stage')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['scope', 'scope_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_configs');
    }
};
