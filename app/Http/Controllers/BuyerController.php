<?php

namespace App\Http\Controllers;

use App\Models\Buyer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BuyerController extends Controller
{
    // GET /api/buyers
    public function index(): JsonResponse
    {
        return response()->json(Buyer::orderBy('name')->get());
    }

    // GET /api/buyers/{id} — dashboard data untuk buyer yang login
    public function show(Request $request, string $id): JsonResponse
    {
        $account = $request->attributes->get('account');

        // Buyer hanya boleh mengakses data dirinya sendiri.
        // Admin boleh mengakses data buyer manapun.
        if ($account->role === 'buyer' && $account->linked_id !== $id) {
            return response()->json(['message' => 'Anda tidak boleh mengakses data buyer lain.'], 403);
        }

        $buyer = Buyer::with(['adoptions.tree.farmer'])->findOrFail($id);

        $adoptions = $buyer->adoptions->map(fn($a) => [
            'id'             => $a->id,
            'tree_id'        => $a->tree_id,
            'adopted_date'   => $a->adopted_date,
            'package_name'   => $a->package_name,
            'certificate_no' => $a->certificate_no,
            'tree'           => $a->tree,
            'farmer'         => $a->tree?->farmer,
        ]);

        return response()->json([
            'buyer'       => $buyer,
            'adoptions'   => $adoptions,
            'total_co2_kg'=> round($adoptions->sum(fn($a) => $a['tree']?->co2_eq_kg ?? 0), 2),
        ]);
    }
}
