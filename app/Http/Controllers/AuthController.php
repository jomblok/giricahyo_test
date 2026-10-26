<?php

namespace App\Http\Controllers;

use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    // Hash bcrypt hasil Hash::make() atas 16 byte acak yang langsung dibuang —
    // plaintext-nya tidak pernah ada di mana pun, jadi tidak bisa dicocokkan.
    // Gunanya cuma satu: memberi Hash::check() pekerjaan yang sama beratnya
    // saat email tidak ditemukan (terukur ~236 ms, sama dengan hash akun asli
    // ~228 ms), supaya lama balasan tidak membedakan email ada dan tidak ada.
    private const DUMMY_HASH = '$2y$12$2dlnklGw.eGixLEykrVyqegfYqr5rEUVdVlQ6S2OUsdUYTs2p/sIe';

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

        // Kalau email tidak ada, Hash::check tidak pernah dipanggil sehingga
        // balasan datang jauh lebih cepat — selisih waktu itu cukup untuk
        // memilah mana email yang benar-benar terdaftar tanpa perlu menebak
        // satu password pun. Hash dummy di bawah membuat kedua jalur memakan
        // waktu yang sama, jadi lamanya balasan tidak lagi membocorkan apa pun.
        if (! $account) {
            Hash::check($request->password, self::DUMMY_HASH);
            return response()->json(['message' => 'Email atau password salah.'], 401);
        }

        if (! Hash::check($request->password, $account->password)) {
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
