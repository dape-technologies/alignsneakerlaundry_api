<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AttendantController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => User::query()
                ->where('role', 'worker')
                ->latest()
                ->get(['id', 'name', 'email', 'role', 'is_active', 'created_at']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $attendant = User::create([
            ...$data,
            'role' => 'worker',
            'is_active' => true,
        ]);

        return response()->json([
            'data' => $attendant->only(['id', 'name', 'email', 'role', 'is_active', 'created_at']),
        ], 201);
    }

    public function updateAccess(Request $request, User $attendant)
    {
        abort_unless($attendant->role === 'worker', 404);

        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $attendant->update($data);

        if (! $attendant->is_active) {
            DB::table('sessions')->where('user_id', $attendant->id)->delete();
        }

        return response()->json([
            'data' => $attendant->only(['id', 'name', 'email', 'role', 'is_active', 'created_at']),
        ]);
    }
}
