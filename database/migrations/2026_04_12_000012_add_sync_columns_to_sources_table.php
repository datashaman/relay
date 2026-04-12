<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->text('sync_error')->nullable()->after('config');
            $table->timestamp('next_retry_at')->nullable()->after('sync_error');
            $table->unsignedInteger('sync_interval')->default(5)->after('next_retry_at');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn(['sync_error', 'next_retry_at', 'sync_interval']);
        });
    }
};
