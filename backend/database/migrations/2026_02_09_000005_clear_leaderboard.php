<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Очистка сыгранных сессий и статистики, чтобы обнулить рейтинг/таблицу лидеров.
        DB::table('game_sessions')->delete();
        DB::table('users')->update([
            'best_score' => 0,
            'total_gems' => 0,
            'total_games' => 0,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Невозможно восстановить очищенные данные.
    }
};
