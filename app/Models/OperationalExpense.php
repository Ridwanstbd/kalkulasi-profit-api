<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OperationalExpense extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'expense_category_id',
        'quantity',
        'unit',
        'amount',
        'year',
        'month',
        'total_amount',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'year' => 'integer',
        'month' => 'integer'
    ];

    /**
     * Get the category that this expense belongs to
     */
    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /**
     * Calculate total amount before saving
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($expense) {
            $conversionFactor = 1;
            if (strtolower($expense->unit) === 'minggu') {
                $conversionFactor = 4; 
            }
            
            $expense->total_amount = $expense->quantity * $expense->amount * $conversionFactor;
            
            if (empty($expense->year)) {
                $expense->year = Carbon::now()->year;
            }
            
            if (empty($expense->month)) {
                $expense->month = Carbon::now()->month;
            }
        });
    }

    /**
     * Get all expense categories with their related expenses and totals
     */
    public function expenseCategory()
    {
        return $this->belongsTo(ExpenseCategory::class);
    }

    /**
     * Get all expenses grouped by category
     */
    public static function getExpensesByCategory($year = null, $month = null)
    {
        $query = static::with('category')
            ->orderBy('expense_category_id');
            
        if ($year) {
            $query->where('year', $year);
        }
        
        if ($month) {
            $query->where('month', $month);
        }
        
        return $query->get()
            ->groupBy('expense_category_id');
    }

    /**
     * Get all salary expenses
     */
    public static function getSalaryExpenses($year = null, $month = null)
    {
        $query = static::with('category')
            ->whereHas('category', function ($query) {
                $query->where('is_salary', true);
            });
            
        if ($year) {
            $query->where('year', $year);
        }
        
        if ($month) {
            $query->where('month', $month);
        }
        
        return $query->get();
    }

    /**
     * Get all operational (non-salary) expenses
     */
    public static function getOperationalExpenses($year = null, $month = null)
    {
        $query = static::with('category')
            ->whereHas('category', function ($query) {
                $query->where('is_salary', false);
            });
            
        if ($year) {
            $query->where('year', $year);
        }
        
        if ($month) {
            $query->where('month', $month);
        }
        
        return $query->get();
    }

    /**
     * Get total salary expenses
     */
    public static function getTotalSalaryExpenses($year = null, $month = null)
    {
        $query = static::whereHas('category', function ($query) {
                $query->where('is_salary', true);
            });
            
        if ($year) {
            $query->where('year', $year);
        }
        
        if ($month) {
            $query->where('month', $month);
        }
        
        return $query->sum('total_amount');
    }

    /**
     * Get total operational (non-salary) expenses
     */
    public static function getTotalOperationalExpenses($year = null, $month = null)
    {
        $query = static::whereHas('category', function ($query) {
                $query->where('is_salary', false);
            });
            
        if ($year) {
            $query->where('year', $year);
        }
        
        if ($month) {
            $query->where('month', $month);
        }
        
        return $query->sum('total_amount');
    }

    /**
     * Get grand total of all expenses
     */
    public static function getGrandTotal($year = null, $month = null)
    {
        $query = static::query();
        
        if ($year) {
            $query->where('year', $year);
        }
        
        if ($month) {
            $query->where('month', $month);
        }
        
        return $query->sum('total_amount');
    }

    /**
     * Get complete summary of expenses
     */
    public static function getSummary($year = null, $month = null)
    {
        $categories = ExpenseCategory::get();
        $expensesByCategory = static::getExpensesByCategory($year, $month);
        
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
        
        return [
            'details' => $summary,
            'total_salary' => $totalSalary,
            'total_operational' => $totalOperational,
            'grand_total' => $totalSalary + $totalOperational,
            'total_employees' => $totalEmployees,
            'year' => $year,
            'month' => $month
        ];
    }

    public static function getAvailableYears($userId = null)
    {
        $query = static::select('year')
            ->distinct();
            
        if ($userId) {
            $query->where('user_id', $userId);
        }
        
        return $query->orderBy('year')
            ->pluck('year')
            ->toArray();
    }

    public static function getAvailableMonths($year, $userId = null)
    {
        $query = static::select('month')
            ->where('year', $year)
            ->distinct();
            
        if ($userId) {
            $query->where('user_id', $userId);
        }
        
        return $query->orderBy('month')
            ->pluck('month')
            ->toArray();
    }
}