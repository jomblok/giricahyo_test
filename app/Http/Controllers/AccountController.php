<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Farmer;
use App\Models\Buyer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class AccountController extends Controller
{
    // GET /api/accounts
    public function index(): JsonResponse
    {
        $accounts = Account::where('deactivated', false)
                           ->orderBy('role')
                           ->orderBy('name')
                           ->get(['id','email','role','name','linked_id','deactivated','created_at']);
        return response()->json($accounts);
    }

    // POST /api/accounts
    // Membuat akun baru. Kalau role=farmer, otomatis buat record di tabel farmers.
    // Kalau role=buyer, otomatis buat record di tabel buyers.
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'       => 'required|string|max:100',
            'email'      => 'required|email|unique:accounts,email',
            'password'   => 'required|string|min:6',
            'role'       => 'required|in:admin,farmer,buyer',
            'group_coop' => 'nullable|string',      // untuk farmer
            'address'    => 'nullable|string',      // untuk farmer
            'phone'      => 'nullable|string',      // untuk buyer
        ]);

        return DB::transaction(function () use ($validated) {
            $linkedId = null;

            // Auto-buat record Farmer
            if ($validated['role'] === 'farmer') {
                $lastId  = Farmer::orderByDesc('id')->value('id') ?? 'F000';
                $nextNum = intval(substr($lastId, 1)) + 1;
                $newId   = 'F' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);

                Farmer::create([
                    'id'         => $newId,
                    'name'       => $validated['name'],
                    'group_coop' => $validated['group_coop'] ?? 'Kelompok Tani Giricahyo I',
                    'address'    => $validated['address'] ?? 'Giricahyo, Purwosari, Gunungkidul',
                    'status'     => 'active',
                ]);
                $linkedId = $newId;
            }

            // Auto-buat record Buyer
            if ($validated['role'] === 'buyer') {
                $lastId  = Buyer::orderByDesc('id')->value('id') ?? 'B000';
                $nextNum = intval(substr($lastId, 1)) + 1;
                $newId   = 'B' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);

                Buyer::create([
                    'id'    => $newId,
                    'name'  => $validated['name'],
                    'email' => $validated['email'],
                    'phone' => $validated['phone'] ?? '',
                ]);
                $linkedId = $newId;
            }

            // Buat akun
            $lastAcc = Account::orderByDesc('id')->value('id') ?? 'ACC000';
            $nextNum = intval(substr($lastAcc, 3)) + 1;
            $accId   = 'ACC' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);

            $account = Account::create([
                'id'        => $accId,
                'email'     => $validated['email'],
                'password'  => Hash::make($validated['password']),
                'role'      => $validated['role'],
                'name'      => $validated['name'],
                'linked_id' => $linkedId,
            ]);

            return response()->json([
                'message' => 'Akun berhasil dibuat.',
                'account' => $account->only(['id','email','role','name','linked_id']),
            ], 201);
        });
    }

    // PATCH /api/accounts/{id}/deactivate
    public function deactivate(string $id): JsonResponse
    {
        return DB::transaction(function () use ($id) {
            $account = Account::findOrFail($id);
            if ($account->role === 'admin') {
                $adminCount = Account::where('role', 'admin')->where('deactivated', false)->count();
                if ($adminCount <= 1) {
                    return response()->json(['message' => 'Minimal satu akun admin harus aktif.'], 422);
                }
            }
            $account->update(['deactivated' => true]);

            // Sinkronkan balik ke farmers.status, supaya konsisten dengan
            // FarmerController::updateStatus() yang melakukan hal sebaliknya
            // (mengubah status farmer otomatis ikut menonaktifkan akun).
            if ($account->role === 'farmer' && $account->linked_id) {
                Farmer::where('id', $account->linked_id)->update(['status' => 'inactive']);
            }

            return response()->json(['message' => 'Akun berhasil dinonaktifkan.']);
        });
    }

    // PATCH /api/accounts/{id}/password
    public function updatePassword(Request $request, string $id): JsonResponse
    {
        $request->validate(['password' => 'required|string|min:6']);
        $account = Account::findOrFail($id);
        $account->update(['password' => Hash::make($request->password)]);
        return response()->json(['message' => 'Password berhasil diubah.']);
    }
}
