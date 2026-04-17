<?php

use App\Models\Source;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->text('webhook_secret')->nullable()->after('config');
            $table->timestamp('webhook_last_delivery_at')->nullable()->after('webhook_secret');
            $table->text('webhook_last_error')->nullable()->after('webhook_last_delivery_at');
        });

        Source::query()->whereNull('webhook_secret')->each(function (Source $source): void {
            $source->update(['webhook_secret' => Str::random(40)]);
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn(['webhook_secret', 'webhook_last_delivery_at', 'webhook_last_error']);
        });
    }
};
