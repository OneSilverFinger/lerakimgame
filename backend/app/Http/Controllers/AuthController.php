<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:40', 'alpha_dash', 'unique:users,username'],
            'password' => ['required', 'string', 'min:6'],
            'name' => ['nullable', 'string', 'max:80'],
        ]);

        $email = $data['username'].'@game.local';

        $user = User::create([
            'username' => $data['username'],
            'name' => $data['name'] ?? $data['username'],
            'email' => $email,
            'password' => $data['password'],
            'gems' => 0,
            'free_swaps_left' => 3,
            'last_free_reset_at' => now()->toDateString(),
        ]);

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        /** @var User|null $user */
        $user = User::where('username', $data['username'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['Неверный логин или пароль'],
            ]);
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    public function profile(Request $request)
    {
        return response()->json($this->userPayload($request->user()));
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'name' => $user->name,
            'gems' => $user->gems,
            'free_swaps_left' => $user->free_swaps_left,
            'last_free_reset_at' => $user->last_free_reset_at,
            'best_score' => $user->best_score,
            'total_gems' => $user->total_gems,
            'total_games' => $user->total_games,
        ];
    }
}
