<?php

namespace App\Http\Controllers;

use App\Models\User;

class LeaderboardController extends Controller
{
    public function index()
    {
        $leaders = User::select('id', 'username', 'best_score', 'total_gems', 'total_games')
            ->orderByDesc('best_score')
            ->orderByDesc('total_gems')
            ->limit(20)
            ->get();

        return response()->json($leaders);
    }
}
