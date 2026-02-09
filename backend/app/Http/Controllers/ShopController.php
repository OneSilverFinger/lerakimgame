<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ShopController extends Controller
{
    public function buySwap(Request $request)
    {
        $data = $request->validate([
            'pack' => ['required', 'integer', 'in:1,7,20'],
        ]);

        $costs = [
            1 => 50,
            7 => 250,
            20 => 500,
        ];

        $user = $request->user();
        $cost = $costs[$data['pack']];

        if ($user->gems < $cost) {
            throw ValidationException::withMessages([
                'gems' => ['Недостаточно самоцветов'],
            ]);
        }

        $user->gems -= $cost;
        $user->free_swaps_left += $data['pack'];
        $user->save();

        return response()->json([
            'gems' => $user->gems,
            'free_swaps_left' => $user->free_swaps_left,
        ]);
    }
}
