<?php

namespace App\Http\Controllers\Feature;

use App\Http\Controllers\Controller;
use App\Models\OperationalExpense;
use App\Models\SalesRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class StatsController extends Controller
{
    public function stats(Request $request)
    {
        $user = JWTAuth::user();
        
        $year = $request->input('year', Carbon::now()->year);
        $month = $request->input('month', Carbon::now()->month);
        
        $validator = Validator::make([
            'year' => $year,
            'month' => $month,
        ], [
            'year' => 'required|integer|min:2000|max:2900',
            'month' => 'nullable|integer|min:1|max:12',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        $year = (int) $year;
        $month = $month ? (int) $month : null;
        
        $availableYears = SalesRecord::where('user_id', $user->id)
            ->selectRaw('DISTINCT year')
            ->orderBy('year', 'desc')
            ->pluck('year')
            ->toArray();
        
        $availableMonths = SalesRecord::where('user_id', $user->id)
            ->where('year', $year)
            ->selectRaw('DISTINCT month')
            ->orderBy('month', 'asc')
            ->pluck('month')
            ->toArray();
        
        $salesRecords = SalesRecord::where('user_id', $user->id)
            ->where('year', $year)
            ->where('month', $month)
            ->get();
        
        $totalSales = $salesRecords->sum(function ($record) {
            return $record->number_of_sales * $record->selling_price;
        });
        
        $totalVariableCost = $salesRecords->sum(function ($record) {
            return $record->number_of_sales * $record->hpp;
        });
        
        $totalOperationalCost = OperationalExpense::getTotalOperationalExpenses($year, $month);
        
        $totalSalaryExpenses = OperationalExpense::getTotalSalaryExpenses($year, $month);
        
        $totalCost = $totalVariableCost + $totalOperationalCost + $totalSalaryExpenses;
        $grossProfit = $totalSales - $totalVariableCost;
        $netProfit = $grossProfit - $totalOperationalCost - $totalSalaryExpenses;
        
        return response()->json([
            'success' => true,
            'data' => [
                'total_sales' => floatval($totalSales),
                'total_cost' => floatval($totalCost),
                'total_variable_cost' => floatval($totalVariableCost),
                'total_operational_cost' => floatval($totalOperationalCost),
                'total_salary_expenses' => floatval($totalSalaryExpenses),
                'gross_profit' => floatval($grossProfit),
                'net_profit' => floatval($netProfit),
                'year' => $year,
                'month' => $month,
                'availableYears' => $availableYears,
                'availableMonths' => $availableMonths
            ]
        ]);
    }
}