<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\ShopController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'profile']);

    Route::post('/game/start', [GameController::class, 'start']);
    Route::post('/game/swap', [GameController::class, 'swap']);
    Route::post('/game/submit', [GameController::class, 'submit']);
    Route::post('/game/check-word', [GameController::class, 'checkWord']);
    Route::post('/game/reveal-hints', [GameController::class, 'revealHints']);

    Route::post('/shop/buy-swap', [ShopController::class, 'buySwap']);

    Route::get('/leaderboard', [LeaderboardController::class, 'index']);
});
