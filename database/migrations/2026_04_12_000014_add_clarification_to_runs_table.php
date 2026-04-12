<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->json('known_facts')->nullable()->after('preflight_doc');
            $table->json('clarification_questions')->nullable()->after('known_facts');
            $table->json('clarification_answers')->nullable()->after('clarification_questions');
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn(['known_facts', 'clarification_questions', 'clarification_answers']);
        });
    }
};
