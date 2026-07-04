<?php

namespace App\Http\Controllers;

use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    // POST /api/auth/login
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $account = Account::where('email', $request->email)
                          ->where('deactivated', false)
                          ->first();

        if (! $account || ! Hash::check($request->password, $account->password)) {
            return response()->json(['message' => 'Email atau password salah.'], 401);
        }

        $token = JWTAuth::fromUser($account);

        return response()->json([
            'token'      => $token,
            'token_type' => 'bearer',
            'expires_in' => config('jwt.ttl') * 60,
            'user'       => [
                'id'        => $account->id,
                'name'      => $account->name,
                'email'     => $account->email,
                'role'      => $account->role,
                'linked_id' => $account->linked_id,
            ],
        ]);
    }

    // POST /api/auth/logout
    public function logout(): JsonResponse
    {
        JWTAuth::invalidate(JWTAuth::getToken());
        return response()->json(['message' => 'Berhasil logout.']);
    }

    // GET /api/auth/me
    public function me(): JsonResponse
    {
        $account = JWTAuth::parseToken()->authenticate();
        return response()->json([
            'id'        => $account->id,
            'name'      => $account->name,
            'email'     => $account->email,
            'role'      => $account->role,
            'linked_id' => $account->linked_id,
        ]);
    }
}
