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
        DB::table('users')
            ->where('username', 'VladKim')
            ->update([
                'gems' => 9_999_999,
                'total_gems' => 9_999_999,
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Не откатываем, чтобы не потерять данные (можно вручную сменить значение gems при необходимости)
    }
};
