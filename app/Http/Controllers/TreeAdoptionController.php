<?php

namespace App\Http\Controllers;

use App\Models\TreeAdoption;
use App\Models\Tree;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TreeAdoptionController extends Controller
{
    // GET /api/adoptions
    public function index(): JsonResponse
    {
        $adoptions = TreeAdoption::with(['tree.farmer', 'buyer'])
                                 ->orderByDesc('adopted_date')
                                 ->get();
        return response()->json($adoptions);
    }

    // POST /api/adoptions — admin menambah adopsi baru
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tree_id'      => 'required|exists:trees,id|unique:tree_adoptions,tree_id',
            'buyer_id'     => 'required|exists:buyers,id',
            'package_name' => 'required|string',
            'adopted_date' => 'required|date',
        ]);

        $buyer    = \App\Models\Buyer::findOrFail($validated['buyer_id']);
        $adoption = TreeAdoption::create([
            ...$validated,
            'buyer_name'     => $buyer->name,
            'certificate_no' => TreeAdoption::generateCertNo(),
        ]);

        return response()->json([
            'message'  => 'Sertifikat adopsi berhasil dibuat.',
            'adoption' => $adoption->load(['tree', 'buyer']),
        ], 201);
    }
}
