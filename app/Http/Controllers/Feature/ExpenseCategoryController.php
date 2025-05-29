<?php

namespace App\Http\Controllers\Feature;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use App\Models\OperationalExpense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class ExpenseCategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = JWTAuth::user();
        
        $categories = ExpenseCategory::where('user_id', $user->id)->get();

        $transformedCategories = [];
        
        foreach ($categories as $category) {
            $operationalExpenses = OperationalExpense::where('user_id', $user->id)
                ->where('expense_category_id', $category->id)
                ->get();
            
            $categoryData = [
                'id' => $category->id,
                'name' => $category->name,
                'description' => $category->description,
                'is_salary' => $category->is_salary,
                'user_id' => $category->user_id,
                'created_at' => $category->created_at,
                'updated_at' => $category->updated_at,
                'total_amount' => number_format($operationalExpenses->sum('total_amount'), 2, '.', '')
            ];
            
            if ($category->is_salary) {
                $categoryData['total_employees'] = $operationalExpenses->count();
            }
            
            $transformedCategories[] = $categoryData;
        }

        $totalSalary = OperationalExpense::whereHas('expenseCategory', function($query) use ($user) {
                $query->where('user_id', $user->id)->where('is_salary', true);
            })->sum('total_amount');
            
        $totalOperational = OperationalExpense::whereHas('expenseCategory', function($query) use ($user) {
                $query->where('user_id', $user->id)->where('is_salary', false);
            })->sum('total_amount');
            
        $grandTotal = $totalSalary + $totalOperational;

        return response()->json([
            'success' => true,
            'data' => $transformedCategories,
            'summary' => [
                'total_salary' => number_format($totalSalary, 2, '.', ''),
                'total_operational' => number_format($totalOperational, 2, '.', ''),
                'grand_total' => number_format($grandTotal, 2, '.', '')
            ]
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_salary' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $user = JWTAuth::user();

        $category = ExpenseCategory::create([
            'user_id' => $user->id,
            'name' => $request['name'],
            'description' => $request['description'],
            'is_salary' => $request['is_salary'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Kategori biaya berhasil dibuat',
            'data' => $category
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $user = JWTAuth::user();
        
        $category = ExpenseCategory::where('user_id', $user->id)->find($id);

        if (!$category) {
            return response()->json([
                'success' => false,
                'message' => 'Kategori biaya tidak ditemukan'
            ], 404);
        }

        $operationalExpenses = OperationalExpense::where('user_id', $user->id)
            ->where('expense_category_id', $category->id)
            ->get();

        $categoryData = [
            'id' => $category->id,
            'name' => $category->name,
            'description' => $category->description,
            'is_salary' => $category->is_salary,
            'user_id' => $category->user_id,
            'created_at' => $category->created_at,
            'updated_at' => $category->updated_at,
            'total_amount' => number_format($operationalExpenses->sum('total_amount'), 2, '.', '')
        ];
        
        if ($category->is_salary) {
            $categoryData['total_employees'] = $operationalExpenses->count();
        }

        return response()->json([
            'success' => true,
            'data' => $categoryData
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $user = JWTAuth::user();
        
        $category = ExpenseCategory::where('user_id', $user->id)->find($id);

        if (!$category) {
            return response()->json([
                'success' => false,
                'message' => 'Kategori biaya tidak ditemukan'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'is_salary' => 'sometimes|required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $category->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Kategori biaya berhasil diperbarui',
            'data' => $category
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $user = JWTAuth::user();
        
        $category = ExpenseCategory::where('user_id', $user->id)->find($id);

        if (!$category) {
            return response()->json([
                'success' => false,
                'message' => 'Kategori biaya tidak ditemukan'
            ], 404);
        }

        $hasExpenses = OperationalExpense::where('user_id', $user->id)
            ->where('expense_category_id', $category->id)
            ->exists();
        
        if ($hasExpenses) {
            return response()->json([
                'success' => false,
                'message' => 'Kategori ini memiliki item biaya. Hapus semua item biaya terlebih dahulu.'
            ], 422);
        }

        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'Kategori biaya berhasil dihapus'
        ]);
    }
}