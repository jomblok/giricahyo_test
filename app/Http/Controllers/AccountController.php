<?php

namespace App\Http\Controllers;

use App\Support\Audit;
use App\Support\IdSequence;
use App\Models\Account;
use App\Models\Farmer;
use App\Models\Buyer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    // GET /api/accounts
    public function index(Request $request): JsonResponse
    {
        // ?termasuk_nonaktif=1 menampilkan yang sudah dinonaktifkan juga.
        //
        // Sebelumnya daftar ini SELALU menyaring deactivated=false, dan tidak
        // ada endpoint untuk mengaktifkan kembali. Jadi menonaktifkan akun
        // adalah pintu satu arah: akunnya lenyap dari layar dan satu-satunya
        // jalan pulang adalah menyunting basis data langsung. Salah klik
        // sekali sudah cukup untuk mengunci seorang petani selamanya.
        $query = Account::orderBy('role')->orderBy('name');

        if (! $request->boolean('termasuk_nonaktif')) {
            $query->where('deactivated', false);
        }

        return response()->json(
            $query->get(['id','email','role','name','linked_id','deactivated','created_at'])
        );
    }

    // POST /api/accounts
    // Membuat akun baru. Kalau role=farmer, otomatis buat record di tabel farmers.
    // Kalau role=buyer, otomatis buat record di tabel buyers.
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'       => 'required|string|max:100',
            'email'      => 'required|email|unique:accounts,email',
            'password'   => ['required', 'string', Password::min(8)->letters()->numbers()],
            'role'       => 'required|in:admin,farmer,buyer',
            // Batas panjang diambil dari lebar kolomnya masing-masing.
            // TERUKUR: tanpa ini, group_coop 5.000 karakter menembus sampai
            // kolom varchar(255) dan menghasilkan HTTP 500 berisi galat SQL,
            // bukan pesan validasi.
            'group_coop' => 'nullable|string|max:120',   // farmers.group_coop varchar(255)
            'address'    => 'nullable|string|max:255',   // farmers.address TEXT, dibatasi agar wajar
            'phone'      => 'nullable|string|max:30',    // buyers.phone varchar(255)
        ]);

        return DB::transaction(function () use ($validated) {
            $linkedId = null;

            // Auto-buat record Farmer
            if ($validated['role'] === 'farmer') {
                // IdSequence, bukan membaca ID tertinggi dari tabelnya
                // sendiri: urutan teks salah pada F999 -> F1000, dan
                // pembacaan tanpa kunci membuat dua pendaftaran serentak
                // menerbitkan ID yang sama. Alasan lengkap di
                // App\Support\IdSequence.
                $newId = IdSequence::berikutnya('farmers', 'F');

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
                $newId = IdSequence::berikutnya('buyers', 'B');

                Buyer::create([
                    'id'    => $newId,
                    'name'  => $validated['name'],
                    'email' => $validated['email'],
                    'phone' => $validated['phone'] ?? '',
                ]);
                $linkedId = $newId;
            }

            // Buat akun
            $accId = IdSequence::berikutnya('accounts', 'ACC');

            $account = Account::create([
                'id'        => $accId,
                'email'     => $validated['email'],
                'password'  => Hash::make($validated['password']),
                'role'      => $validated['role'],
                'name'      => $validated['name'],
                'linked_id' => $linkedId,
            ]);

            Audit::catat('account.create', "Membuat akun {$account->role} untuk {$account->name}", 'account', $accId, [
                'email'     => $account->email,
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

            Audit::catat('account.deactivate', "Menonaktifkan akun {$account->name}", 'account', $account->id);

            return response()->json(['message' => 'Akun berhasil dinonaktifkan.']);
        });
    }

    // PATCH /api/accounts/{id}/activate
    //
    // Pasangan dari deactivate() di atas. Tanpa ini penonaktifan tidak bisa
    // dibatalkan sama sekali lewat aplikasi.
    public function activate(string $id): JsonResponse
    {
        return DB::transaction(function () use ($id) {
            $account = Account::findOrFail($id);
            $account->update(['deactivated' => false]);

            // Cerminan persis dari deactivate(): status petani ikut dipulihkan,
            // supaya accounts dan farmers tidak berakhir saling bertentangan
            // (akun hidup tapi petaninya masih 'inactive', atau sebaliknya).
            if ($account->role === 'farmer' && $account->linked_id) {
                Farmer::where('id', $account->linked_id)->update(['status' => 'active']);
            }

            Audit::catat('account.activate', "Mengaktifkan kembali akun {$account->name}", 'account', $account->id);

            return response()->json([
                'message' => 'Akun berhasil diaktifkan kembali.',
                'account' => $account->only(['id','email','role','name','linked_id','deactivated']),
            ]);
        });
    }

    // PATCH /api/accounts/{id}/password
    //
    // Admin mereset password AKUN LAIN tetap tanpa password lama — itu memang
    // fungsi yang dibutuhkan saat petani lupa passwordnya sendiri.
    //
    // Tapi kalau yang diubah adalah akun admin ITU SENDIRI, password lama wajib
    // disertakan: tanpa syarat ini, siapa pun yang sempat memakai layar admin
    // yang tertinggal terbuka bisa mengunci pemilik aslinya keluar hanya dengan
    // satu permintaan, tanpa pernah tahu password yang berlaku.
    public function updatePassword(Request $request, string $id): JsonResponse
    {
        $actor   = $request->attributes->get('account');
        $account = Account::findOrFail($id);
        $isSelf  = $actor->id === $account->id;

        $rules = ['password' => ['required', 'string', Password::min(8)->letters()->numbers()]];
        if ($isSelf) {
            $rules['current_password'] = 'required|string';
        }
        $request->validate($rules, [
            'current_password.required' => 'Password lama wajib diisi saat mengubah password sendiri.',
        ]);

        if ($isSelf && ! Hash::check($request->current_password, $account->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Password lama tidak cocok.'],
            ]);
        }

        $account->update(['password' => Hash::make($request->password)]);
        // Nilai kata sandinya TIDAK ikut dicatat, jelas. Yang dicatat
        // hanya bahwa kata sandi akun ini diubah, oleh siapa, dan kapan.
        Audit::catat('account.password', "Mengubah kata sandi akun {$account->name}", 'account', $account->id, [
            'akun_sendiri' => $isSelf,
        ]);

        return response()->json(['message' => 'Password berhasil diubah.']);
    }
}
