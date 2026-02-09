<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->unique()->after('id');
            $table->integer('gems')->default(0);
            $table->integer('free_swaps_left')->default(3);
            $table->date('last_free_reset_at')->nullable();
            $table->integer('best_score')->default(0);
            $table->integer('total_gems')->default(0);
            $table->integer('total_games')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'username',
                'gems',
                'free_swaps_left',
                'last_free_reset_at',
                'best_score',
                'total_gems',
                'total_games',
            ]);
        });
    }
};
