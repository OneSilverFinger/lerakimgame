<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClearLeaderboard extends Command
{
    protected $signature = 'leaderboard:clear';

    protected $description = 'Очистить таблицу лидеров: удалить сессии и сбросить статистику пользователей';

    public function handle(): int
    {
        DB::table('game_sessions')->delete();
        DB::table('users')->update([
            'best_score' => 0,
            'total_gems' => 0,
            'total_games' => 0,
        ]);

        $this->info('Лидерборд очищен');
        return self::SUCCESS;
    }
}
