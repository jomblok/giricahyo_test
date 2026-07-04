<?php

namespace App\Http\Controllers;

use App\Models\CarbonFundIncome;
use App\Models\FundDistribution;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CarbonFundController extends Controller
{
    // GET /api/carbon-fund/income
    public function income(): JsonResponse
    {
        return response()->json(CarbonFundIncome::orderByDesc('date')->get());
    }

    // POST /api/carbon-fund/income
    // total_amount SENGAJA dihitung di backend (qty x unit_price), bukan
    // menerima langsung dari client — supaya data finansial yang akan
    // diaudit tidak bisa dimanipulasi dengan mengirim angka yang tidak
    // konsisten antara qty/unit_price dan total_amount.
    public function storeIncome(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date'       => 'required|date',
            'source'     => 'required|string|max:50',
            'qty'        => 'required|integer|min:1',
            'unit_price' => 'required|numeric|min:0',
        ]);

        $income = CarbonFundIncome::create([
            ...$validated,
            'total_amount' => $validated['qty'] * $validated['unit_price'],
        ]);

        return response()->json(['message' => 'Dana masuk berhasil dicatat.', 'income' => $income], 201);
    }

    // GET /api/carbon-fund/distribution
    public function distribution(): JsonResponse
    {
        return response()->json(FundDistribution::orderByDesc('id')->get());
    }

    // GET /api/carbon-fund/summary
    public function summary(): JsonResponse
    {
        return response()->json([
            'total_income'    => CarbonFundIncome::sum('total_amount'),
            'by_source'       => CarbonFundIncome::selectRaw('source, SUM(total_amount) as total')
                                    ->groupBy('source')->get(),
            'distribution'    => FundDistribution::orderByDesc('id')->get(),
        ]);
    }
}
