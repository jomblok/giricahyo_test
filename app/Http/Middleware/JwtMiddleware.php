<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;

class JwtMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        try {
            $account = JWTAuth::parseToken()->authenticate();
        } catch (JWTException $e) {
            return response()->json(['message' => 'Token tidak valid atau sudah kadaluarsa. Silakan login ulang.'], 401);
        }

        if (! $account) {
            return response()->json(['message' => 'Akun tidak ditemukan.'], 401);
        }

        if ($account->deactivated) {
            return response()->json(['message' => 'Akun telah dinonaktifkan.'], 403);
        }

        // Kalau route memerlukan role tertentu (e.g. middleware('jwt:admin'))
        if (! empty($roles) && ! in_array($account->role, $roles)) {
            return response()->json(['message' => 'Akses ditolak. Role tidak sesuai.'], 403);
        }

        // Taruh data akun di request attributes (BUKAN ->merge(), karena
        // merge() menaruh ke input bag yang dipakai validasi form —
        // attributes->set() adalah cara yang benar untuk data internal
        // seperti ini, dan tidak akan bocor ke $request->all()).
        $request->attributes->set('account', $account);

        return $next($request);
    }
}
