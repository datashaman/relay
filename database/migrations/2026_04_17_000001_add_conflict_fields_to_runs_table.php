<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->boolean('has_conflicts')->default(false)->index();
            $table->timestamp('conflict_detected_at')->nullable();
            $table->text('conflict_files')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropIndex(['has_conflicts']);
            $table->dropColumn(['has_conflicts', 'conflict_detected_at', 'conflict_files']);
        });
    }
};
