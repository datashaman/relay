<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->boolean('is_intake_paused')->default(false)->after('is_active');
            $table->unsignedInteger('backlog_threshold')->nullable()->after('is_intake_paused');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn(['is_intake_paused', 'backlog_threshold']);
        });
    }
};
