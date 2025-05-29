<?php

namespace Tests\Feature;

use App\Models\ExpenseCategory;
use App\Models\OperationalExpense;
use App\Models\Product;
use App\Models\SalesRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class StatsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $anotherUser;
    protected string $tokenString;
    protected string $baseApiUrl = '/api/stats'; 
    protected Product $userProduct1;
    protected ExpenseCategory $salaryCategory;
    protected ExpenseCategory $operationalCategory;


    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);
        $this->tokenString = JWTAuth::fromUser($this->user);

        $this->anotherUser = User::factory()->create([
            'name' => 'Another User',
            'email' => 'another@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->userProduct1 = Product::factory()->create(['user_id' => $this->user->id, 'name' => 'Test Product', 'hpp' => 100, 'selling_price' => 150]);

        $this->salaryCategory = ExpenseCategory::factory()->create(['user_id' => $this->user->id, 'name' => 'Gaji', 'is_salary' => true]);
        $this->operationalCategory = ExpenseCategory::factory()->create(['user_id' => $this->user->id, 'name' => 'Operasional Lain', 'is_salary' => false]);
    }

    private function createSale(array $attributes = []): SalesRecord
    {
        $defaults = [
            'user_id' => $this->user->id,
            'product_id' => $this->userProduct1->id,
            'month' => Carbon::now()->month,
            'year' => Carbon::now()->year,
            'number_of_sales' => 10,
            'hpp' => $this->userProduct1->hpp,
            'selling_price' => $this->userProduct1->selling_price,
        ];
        return SalesRecord::factory()->create(array_merge($defaults, $attributes));
    }

    private function createOperationalExpense(ExpenseCategory $category, array $attributes = []): OperationalExpense
    {
        $defaults = [
            'user_id' => $this->user->id,
            'expense_category_id' => $category->id,
            'amount' => 1000,
            'total_amount' => $attributes['amount'] ?? 1000, 
            'quantity' => 1,
            'unit' => 'unit',
            'year' => Carbon::now()->year,
            'month' => Carbon::now()->month,
        ];
        return OperationalExpense::factory()->create(array_merge($defaults, $attributes));
    }

    public function test_can_get_stats_for_current_month_and_year_with_data()
    {
        $now = Carbon::now();
        $year = $now->year;
        $month = $now->month;

        $sale1 = $this->createSale(['number_of_sales' => 10, 'selling_price' => 150, 'hpp' => 100, 'year' => $year, 'month' => $month]); 
        $sale2 = $this->createSale(['product_id' => Product::factory()->create(['user_id' => $this->user->id, 'hpp' => 50, 'selling_price' => 80])->id, 'number_of_sales' => 20, 'selling_price' => 80, 'hpp' => 50, 'year' => $year, 'month' => $month]);
        
        $opExSalary = $this->createOperationalExpense($this->salaryCategory, ['amount' => 200, 'total_amount' => 200, 'year' => $year, 'month' => $month]);
        $opExOperational = $this->createOperationalExpense($this->operationalCategory, ['amount' => 150, 'total_amount' => 150, 'year' => $year, 'month' => $month]);

        $expectedTotalSales = (10 * 150) + (20 * 80); 
        $expectedTotalVariableCost = (10 * 100) + (20 * 50);
        $expectedGrossProfit = $expectedTotalSales - $expectedTotalVariableCost; 
        $expectedTotalSalary = 200;
        $expectedTotalOperational = 150;
        $expectedTotalCost = $expectedTotalVariableCost + $expectedTotalOperational + $expectedTotalSalary;
        $expectedNetProfit = $expectedGrossProfit - $expectedTotalOperational - $expectedTotalSalary;

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson($this->baseApiUrl . "?year={$year}&month={$month}"); 

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.total_sales', $expectedTotalSales)
            ->assertJsonPath('data.total_variable_cost', $expectedTotalVariableCost)
            ->assertJsonPath('data.total_operational_cost', $expectedTotalOperational)
            ->assertJsonPath('data.total_salary_expenses', $expectedTotalSalary)
            ->assertJsonPath('data.total_cost', $expectedTotalCost)
            ->assertJsonPath('data.gross_profit', $expectedGrossProfit)
            ->assertJsonPath('data.net_profit', $expectedNetProfit)
            ->assertJsonPath('data.year', $year)
            ->assertJsonPath('data.month', $month)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total_sales', 'total_cost', 'total_variable_cost', 'total_operational_cost',
                    'total_salary_expenses', 'gross_profit', 'net_profit',
                    'year', 'month', 'availableYears', 'availableMonths'
                ]
            ]);
    }

    public function test_get_stats_with_no_sales_or_expenses_returns_zero_values()
    {
        $year = Carbon::now()->year;
        $month = Carbon::now()->month;

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson("{$this->baseApiUrl}?year={$year}&month={$month}");

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.total_sales', 0)
            ->assertJsonPath('data.total_variable_cost', 0)
            ->assertJsonPath('data.total_operational_cost', 0)
            ->assertJsonPath('data.total_salary_expenses', 0)
            ->assertJsonPath('data.total_cost', 0)
            ->assertJsonPath('data.gross_profit', 0)
            ->assertJsonPath('data.net_profit', 0)
            ->assertJsonPath('data.year', $year)
            ->assertJsonPath('data.month', $month);
    }

    public function test_get_stats_for_specific_past_year_and_month()
    {
        $targetYear = Carbon::now()->subYears(2)->year;
        $targetMonth = 3;

        $this->createSale(['year' => $targetYear, 'month' => $targetMonth, 'number_of_sales' => 5, 'selling_price' => 200, 'hpp' => 120]);
        $this->createOperationalExpense($this->operationalCategory, ['amount' => 50, 'total_amount' => 50, 'year' => $targetYear, 'month' => $targetMonth]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson("{$this->baseApiUrl}?year={$targetYear}&month={$targetMonth}");

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.total_sales', 1000)
            ->assertJsonPath('data.total_variable_cost', 600)
            ->assertJsonPath('data.total_operational_cost', 50)
            ->assertJsonPath('data.total_salary_expenses', 0)
            ->assertJsonPath('data.gross_profit', 400)
            ->assertJsonPath('data.net_profit', 350)
            ->assertJsonPath('data.year', $targetYear)
            ->assertJsonPath('data.month', $targetMonth);
    }

    public function test_stats_validation_fails_for_invalid_year()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson($this->baseApiUrl . '?year=1990');

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['year']);
    }

    public function test_stats_validation_fails_for_invalid_month()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson($this->baseApiUrl . '?month=13'); 

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['month']);
    }
    
    public function test_stats_validation_fails_for_non_integer_year()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson($this->baseApiUrl . '?year=abc');

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['year']);
    }

    public function test_stats_unauthenticated_user_cannot_access_stats()
    {
        $this->getJson($this->baseApiUrl)->assertStatus(401);
    }

    public function test_available_filters_are_correct()
    {
        $year1 = Carbon::now()->year;
        $year2 = Carbon::now()->subYear()->year;
        $month1 = Carbon::now()->month;
        $month2 = Carbon::now()->subMonth()->month;
        if ($month2 <= 0) { 
            $month2 = 12 + $month2;
        }


        $this->createSale(['year' => $year1, 'month' => $month1]);
        $this->createSale(['year' => $year1, 'month' => $month2]);
        $this->createSale(['year' => $year2, 'month' => $month1]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson("{$this->baseApiUrl}?year={$year1}&month={$month1}");

        $response->assertStatus(200)
                 ->assertJsonPath('data.availableYears', fn ($years) => in_array($year1, $years) && in_array($year2, $years) && count($years) >= 2)
                 ->assertJsonPath('data.availableMonths', fn ($months) => in_array($month1, $months) && in_array($month2, $months) && count($months) >= 2);
    }
}