<?php

namespace App\Http\Controllers\Feature;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use App\Models\OperationalExpense;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class OperationalExpenseController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = JWTAuth::user();
        
        $year = (int)$request->input('year', Carbon::now()->year);
        $month = (int)$request->input('month', Carbon::now()->month);
        
        $query = OperationalExpense::with('category')
            ->where('user_id', $user->id);
            
        if ($year) {
            $query->where('year', $year);
        }
        if ($month) {
            $query->where('month', $month);
        }
        
        $expenses = $query->get();
        
        $availableYears = OperationalExpense::getAvailableYears($user->id);
        $availableMonths = $year ? OperationalExpense::getAvailableMonths($year, $user->id) : [];
        
        $expensesByCategory = $expenses->groupBy('expense_category_id');
        
        $categories = ExpenseCategory::where('user_id', $user->id)->get();
        
        $summary = [];
        $totalSalary = 0;
        $totalOperational = 0;
        $totalEmployees = 0;
        
        foreach ($categories as $category) {
            $categoryExpenses = $expensesByCategory->get($category->id, collect([]));
            $categoryTotal = $categoryExpenses->sum('total_amount');
            
            if ($category->is_salary) {
                $totalSalary += $categoryTotal;
                $totalEmployees += $categoryExpenses->sum('quantity');
            } else {
                $totalOperational += $categoryTotal;
            }
            
            $summary[] = [
                'category' => $category->toArray(),
                'expenses' => $categoryExpenses->toArray(),
                'total' => $categoryTotal
            ];
        }

        $summaryData = [
            'details' => $summary,
            'total_salary' => $totalSalary,
            'total_operational' => $totalOperational,
            'grand_total' => $totalSalary + $totalOperational,
            'total_employees' => $totalEmployees,
            'year' => $year,
            'month' => $month
        ];
        
        return response()->json([
            'success' => true,
            'data' => $expenses,
            'summary' => $summaryData,
            'filters' => [
                'available_years' => $availableYears,
                'available_months' => $availableMonths,
                'current_year' => $year,
                'current_month' => $month,
            ]
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $user = JWTAuth::user();
        
        $validator = Validator::make($request->all(), [
            'expense_category_id' => 'required|exists:expense_categories,id',
            'quantity' => 'required|integer|min:1',
            'unit' => 'required|string|max:50',
            'amount' => 'required|numeric|min:0',
            'year' => 'sometimes|integer|min:2000|max:2100',
            'month' => 'sometimes|integer|min:1|max:12',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        $categoryBelongsToUser = ExpenseCategory::where('id', $request->expense_category_id)
            ->where('user_id', $user->id)
            ->exists();
            
        if (!$categoryBelongsToUser) {
            return response()->json([
                'success' => false,
                'message' => 'Kategori biaya tidak ditemukan'
            ], 404);
        }

        $data = $request->all();
        $data['user_id'] = $user->id;
        
        if (!isset($data['year'])) {
            $data['year'] = Carbon::now()->year;
        }
        
        if (!isset($data['month'])) {
            $data['month'] = Carbon::now()->month;
        }

        $existingExpense = OperationalExpense::where('user_id', $user->id)
            ->where('expense_category_id', $data['expense_category_id'])
            ->where('year', $data['year'])
            ->where('month', $data['month'])
            ->first();
            
        if ($existingExpense) {
            return response()->json([
                'success' => false,
                'message' => 'Biaya operasional untuk kategori, tahun, dan bulan yang sama sudah ada'
            ], 422);
        }
        
        $expense = OperationalExpense::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Item biaya operasional berhasil dibuat',
            'data' => $expense
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $user = JWTAuth::user();
        
        $expense = OperationalExpense::with('category')
            ->where('user_id', $user->id)
            ->find($id);

        if (!$expense) {
            return response()->json([
                'success' => false,
                'message' => 'Item biaya operasional tidak ditemukan'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $expense
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $user = JWTAuth::user();
        
        $expense = OperationalExpense::where('user_id', $user->id)->find($id);

        if (!$expense) {
            return response()->json([
                'success' => false,
                'message' => 'Item biaya operasional tidak ditemukan'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'expense_category_id' => 'sometimes|required|exists:expense_categories,id',
            'quantity' => 'sometimes|required|integer|min:1',
            'unit' => 'sometimes|required|string|max:50',
            'amount' => 'sometimes|required|numeric|min:0',
            'year' => 'sometimes|required|integer|min:2000|max:2100',
            'month' => 'sometimes|required|integer|min:1|max:12',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        if ($request->has('expense_category_id')) {
            $categoryBelongsToUser = ExpenseCategory::where('id', $request->expense_category_id)
                ->where('user_id', $user->id)
                ->exists();
                
            if (!$categoryBelongsToUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kategori biaya tidak ditemukan'
                ], 404);
            }
        }

        $categoryIdChanged = $request->has('expense_category_id') && $expense->expense_category_id != $request->expense_category_id;
        $yearChanged = $request->has('year') && $expense->year != $request->year;
        $monthChanged = $request->has('month') && $expense->month != $request->month;
        
        if ($categoryIdChanged || $yearChanged || $monthChanged) {
            $expenseCategoryId = $request->expense_category_id ?? $expense->expense_category_id;
            $year = $request->year ?? $expense->year;
            $month = $request->month ?? $expense->month;
            
            $existingExpense = OperationalExpense::where('user_id', $user->id)
                ->where('expense_category_id', $expenseCategoryId)
                ->where('year', $year)
                ->where('month', $month)
                ->where('id', '!=', $id)
                ->first();
                
            if ($existingExpense) {
                return response()->json([
                    'success' => false,
                    'message' => 'Biaya operasional untuk kategori, tahun, dan bulan yang sama sudah ada'
                ], 422);
            }
        }

        $expense->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Item biaya operasional berhasil diperbarui',
            'data' => $expense
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $user = JWTAuth::user();
        
        $expense = OperationalExpense::where('user_id', $user->id)->find($id);

        if (!$expense) {
            return response()->json([
                'success' => false,
                'message' => 'Item biaya operasional tidak ditemukan'
            ], 404);
        }

        $expense->delete();

        return response()->json([
            'success' => true,
            'message' => 'Item biaya operasional berhasil dihapus'
        ]);
    }
}