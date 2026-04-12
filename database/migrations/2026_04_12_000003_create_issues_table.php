<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repository_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('status');
            $table->string('external_url')->nullable();
            $table->string('assignee')->nullable();
            $table->json('labels')->nullable();
            $table->boolean('auto_accepted')->default(false);
            $table->timestamps();

            $table->unique(['source_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issues');
    }
};
