<?php

namespace App\Http\Controllers;

use App\Models\Species;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SpeciesController extends Controller
{
    // GET /api/species
    public function index(): JsonResponse
    {
        return response()->json(Species::orderBy('name')->get());
    }

    // POST /api/species
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'        => 'required|string|unique:species,name',
            'density_rho' => 'required|numeric|min:0.1|max:1.5',
        ]);
        $species = Species::create($validated);
        return response()->json(['message' => 'Jenis pohon berhasil ditambahkan.', 'species' => $species], 201);
    }
}
