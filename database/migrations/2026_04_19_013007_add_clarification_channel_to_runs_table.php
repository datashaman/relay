<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            // Snapshots the source's clarification_channel at the moment a
            // clarification round opens so a mid-flight Source toggle does
            // not flip an in-flight Run between channels.
            $table->string('clarification_channel')->nullable()->after('clarification_history');
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn('clarification_channel');
        });
    }
};
