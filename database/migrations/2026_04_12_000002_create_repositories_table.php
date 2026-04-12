<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repositories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('path');
            $table->string('default_branch')->default('main');
            $table->string('worktree_root')->nullable();
            $table->text('setup_script')->nullable();
            $table->text('teardown_script')->nullable();
            $table->text('run_script')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repositories');
    }
};
