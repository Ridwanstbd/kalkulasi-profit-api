<?php

namespace Tests\Feature;

use App\Models\ExpenseCategory;
use App\Models\OperationalExpense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class OperationalExpenseTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $anotherUser;
    protected string $tokenString;
    protected string $baseApiUrl = '/api/operational-expenses'; 
    protected ExpenseCategory $userCategory;
    protected ExpenseCategory $anotherUserCategory;


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

        $this->userCategory = ExpenseCategory::factory()->create(['user_id' => $this->user->id, 'name' => 'User Category']);
        $this->anotherUserCategory = ExpenseCategory::factory()->create(['user_id' => $this->anotherUser->id, 'name' => 'Another User Category']);
    }

    private function createExpenseForUser(User $user, ExpenseCategory $category, array $attributes = []): OperationalExpense
    {
        $defaults = [
            'user_id' => $user->id,
            'expense_category_id' => $category->id,
            'year' => Carbon::now()->year,
            'month' => Carbon::now()->month,
        ];
        return OperationalExpense::factory()->create(array_merge($defaults, $attributes));
    }


    public function test_can_list_operational_expenses_for_current_month_and_year()
    {
        $this->createExpenseForUser($this->user, $this->userCategory, ['amount' => 100, 'year' => Carbon::now()->year, 'month' => Carbon::now()->month]);
        $this->createExpenseForUser($this->user, $this->userCategory, ['amount' => 200, 'year' => Carbon::now()->year, 'month' => Carbon::now()->month]);
        $this->createExpenseForUser($this->user, $this->userCategory, ['amount' => 300, 'year' => Carbon::now()->subYear()->year, 'month' => Carbon::now()->month]);
        $this->createExpenseForUser($this->anotherUser, $this->anotherUserCategory, ['amount' => 400]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson($this->baseApiUrl);

        $response->assertStatus(200)
                 ->assertJson(['success' => true])
                 ->assertJsonCount(2, 'data') 
                 ->assertJsonStructure([
                     'success',
                     'data',
                     'summary' => ['details', 'total_salary', 'total_operational', 'grand_total', 'total_employees', 'year', 'month'],
                     'filters' => ['available_years', 'available_months', 'current_year', 'current_month']
                 ])
                 ->assertJsonPath('summary.year', Carbon::now()->year)
                 ->assertJsonPath('summary.month', Carbon::now()->month);
    }

    public function test_can_filter_operational_expenses_by_year_and_month()
    {
        $targetYear = Carbon::now()->subYear()->year;
        $targetMonth = 5;

        $this->createExpenseForUser($this->user, $this->userCategory, ['amount' => 150, 'year' => $targetYear, 'month' => $targetMonth]);
        $this->createExpenseForUser($this->user, $this->userCategory, ['amount' => 250, 'year' => Carbon::now()->year, 'month' => Carbon::now()->month]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson("{$this->baseApiUrl}?year={$targetYear}&month={$targetMonth}");

        $response->assertStatus(200)
                 ->assertJson(['success' => true])
                 ->assertJsonCount(1, 'data')
                 ->assertJsonPath('data.0.amount', '150.00') 
                 ->assertJsonPath('summary.year', $targetYear) 
                 ->assertJsonPath('summary.month', $targetMonth)
                 ->assertJsonPath('filters.current_year', $targetYear)
                 ->assertJsonPath('filters.current_month', $targetMonth);
    }
    
    public function test_index_returns_empty_data_and_correct_summary_if_no_expenses_for_filter()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson($this->baseApiUrl . '?year=1990&month=1');
        
        $response->assertStatus(200)
                 ->assertJson(['success' => true])
                 ->assertJsonCount(0, 'data')
                 ->assertJsonPath('summary.total_salary', 0) 
                 ->assertJsonPath('summary.total_operational', 0)
                 ->assertJsonPath('summary.grand_total', 0)
                 ->assertJsonPath('summary.year', 1990)
                 ->assertJsonPath('summary.month', 1);
    }


    public function test_index_unauthenticated_user_cannot_list_expenses()
    {
        $this->getJson($this->baseApiUrl)->assertStatus(401);
    }


    public function test_can_store_new_operational_expense()
    {
        $expenseData = [
            'expense_category_id' => $this->userCategory->id,
            'quantity' => 2,
            'unit' => 'pcs',
            'amount' => 250.75,
            'year' => Carbon::now()->year,
            'month' => Carbon::now()->month,
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->postJson($this->baseApiUrl, $expenseData);

        $response->assertStatus(201)
                 ->assertJson(['success' => true, 'message' => 'Item biaya operasional berhasil dibuat'])
                 ->assertJsonPath('data.expense_category_id', $this->userCategory->id)
                 ->assertJsonPath('data.amount', '250.75') 
                 ->assertJsonPath('data.user_id', $this->user->id);

        $this->assertDatabaseHas('operational_expenses', [
            'user_id' => $this->user->id,
            'expense_category_id' => $this->userCategory->id,
            'amount' => 250.75
        ]);
    }
    
    public function test_store_uses_current_year_month_if_not_provided()
    {
        $expenseData = [
            'expense_category_id' => $this->userCategory->id,
            'quantity' => 1,
            'unit' => 'unit',
            'amount' => 100,
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->postJson($this->baseApiUrl, $expenseData);
        
        $response->assertStatus(201)
                 ->assertJsonPath('data.year', Carbon::now()->year)
                 ->assertJsonPath('data.month', Carbon::now()->month);
    }


    public function test_store_fails_with_validation_errors()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->postJson($this->baseApiUrl, [
                         ]);

        $response->assertStatus(422)
                 ->assertJson(['success' => false])
                 ->assertJsonValidationErrors(['expense_category_id', 'quantity', 'unit', 'amount']);
    }

    public function test_store_fails_if_category_does_not_belong_to_user()
    {
        $expenseData = [
            'expense_category_id' => $this->anotherUserCategory->id,
            'quantity' => 1,
            'unit' => 'pcs',
            'amount' => 100,
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->postJson($this->baseApiUrl, $expenseData);

        $response->assertStatus(404) 
                 ->assertJson(['success' => false, 'message' => 'Kategori biaya tidak ditemukan']);
    }

    public function test_store_fails_if_expense_for_category_year_month_already_exists()
    {
        $year = Carbon::now()->year;
        $month = Carbon::now()->month;
        $this->createExpenseForUser($this->user, $this->userCategory, ['year' => $year, 'month' => $month, 'amount' => 100]);

        $expenseData = [
            'expense_category_id' => $this->userCategory->id,
            'quantity' => 1,
            'unit' => 'pcs',
            'amount' => 200,
            'year' => $year,
            'month' => $month,
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->postJson($this->baseApiUrl, $expenseData);

        $response->assertStatus(422) 
                 ->assertJson(['success' => false, 'message' => 'Biaya operasional untuk kategori, tahun, dan bulan yang sama sudah ada']);
    }

    public function test_store_unauthenticated_user_cannot_create_expense()
    {
        $expenseData = OperationalExpense::factory()->make()->toArray();
        $this->postJson($this->baseApiUrl, $expenseData)->assertStatus(401);
    }


    public function test_can_show_specific_operational_expense()
    {
        $expense = $this->createExpenseForUser($this->user, $this->userCategory, ['amount' => 500]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson("{$this->baseApiUrl}/{$expense->id}");

        $response->assertStatus(200)
                 ->assertJson(['success' => true])
                 ->assertJsonPath('data.id', $expense->id)
                 ->assertJsonPath('data.amount', '500.00');
    }

    public function test_cannot_show_expense_owned_by_another_user()
    {
        $anotherUserExpense = $this->createExpenseForUser($this->anotherUser, $this->anotherUserCategory);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson("{$this->baseApiUrl}/{$anotherUserExpense->id}");

        $response->assertStatus(404)
                 ->assertJson(['success' => false, 'message' => 'Item biaya operasional tidak ditemukan']);
    }

    public function test_show_returns_404_for_non_existent_expense()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson("{$this->baseApiUrl}/9999");

        $response->assertStatus(404);
    }
    
    public function test_show_unauthenticated_user_cannot_view_expense()
    {
        $expense = $this->createExpenseForUser($this->user, $this->userCategory);
        $this->getJson("{$this->baseApiUrl}/{$expense->id}")->assertStatus(401);
    }



    public function test_can_update_owned_operational_expense()
    {
        $expense = $this->createExpenseForUser($this->user, $this->userCategory, ['amount' => 100, 'quantity' => 1]);
        $updateData = [
            'amount' => 150.50,
            'quantity' => 2,
            'unit' => 'box',
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->putJson("{$this->baseApiUrl}/{$expense->id}", $updateData);

        $response->assertStatus(200)
                 ->assertJson(['success' => true, 'message' => 'Item biaya operasional berhasil diperbarui'])
                 ->assertJsonPath('data.amount', '150.50')
                 ->assertJsonPath('data.quantity', 2)
                 ->assertJsonPath('data.unit', 'box');

        $this->assertDatabaseHas('operational_expenses', ['id' => $expense->id, 'amount' => 150.50, 'unit' => 'box']);
    }

    public function test_update_fails_with_validation_errors()
    {
        $expense = $this->createExpenseForUser($this->user, $this->userCategory);
        $updateData = ['amount' => 'not-a-number', 'quantity' => 0]; 

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->putJson("{$this->baseApiUrl}/{$expense->id}", $updateData);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['amount', 'quantity']);
    }

    public function test_update_fails_if_changing_to_category_not_owned_by_user()
    {
        $expense = $this->createExpenseForUser($this->user, $this->userCategory);
        $updateData = ['expense_category_id' => $this->anotherUserCategory->id];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->putJson("{$this->baseApiUrl}/{$expense->id}", $updateData);

        $response->assertStatus(404) 
                 ->assertJson(['success' => false, 'message' => 'Kategori biaya tidak ditemukan']);
    }
    
    public function test_update_fails_if_changing_to_existing_category_year_month_combination()
    {
        $year = 2023; $month = 10;
        $category1 = $this->userCategory; 
        $category2 = ExpenseCategory::factory()->create(['user_id' => $this->user->id, 'name' => 'User Category 2']);

        $this->createExpenseForUser($this->user, $category1, ['year' => $year, 'month' => $month, 'amount' => 100]);
        $expenseToUpdate = $this->createExpenseForUser($this->user, $category2, ['year' => $year, 'month' => $month, 'amount' => 200]);

        $updateData = [
            'expense_category_id' => $category1->id, 
            'year' => $year,
            'month' => $month,
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->putJson("{$this->baseApiUrl}/{$expenseToUpdate->id}", $updateData);
        
        $response->assertStatus(422)
                 ->assertJson(['success' => false, 'message' => 'Biaya operasional untuk kategori, tahun, dan bulan yang sama sudah ada']);
    }


    public function test_cannot_update_expense_owned_by_another_user()
    {
        $anotherUserExpense = $this->createExpenseForUser($this->anotherUser, $this->anotherUserCategory);
        $updateData = ['amount' => 999];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->putJson("{$this->baseApiUrl}/{$anotherUserExpense->id}", $updateData);

        $response->assertStatus(404);
    }
    
    public function test_update_unauthenticated_user_cannot_update_expense()
    {
        $expense = $this->createExpenseForUser($this->user, $this->userCategory);
        $this->putJson("{$this->baseApiUrl}/{$expense->id}", [])->assertStatus(401);
    }



    public function test_can_destroy_owned_operational_expense()
    {
        $expense = $this->createExpenseForUser($this->user, $this->userCategory);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->deleteJson("{$this->baseApiUrl}/{$expense->id}");

        $response->assertStatus(200)
                 ->assertJson(['success' => true, 'message' => 'Item biaya operasional berhasil dihapus']);

        $this->assertDatabaseMissing('operational_expenses', ['id' => $expense->id]);
    }

    public function test_cannot_destroy_expense_owned_by_another_user()
    {
        $anotherUserExpense = $this->createExpenseForUser($this->anotherUser, $this->anotherUserCategory);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->deleteJson("{$this->baseApiUrl}/{$anotherUserExpense->id}");

        $response->assertStatus(404);
    }

    public function test_destroy_returns_404_for_non_existent_expense()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->deleteJson("{$this->baseApiUrl}/9999");

        $response->assertStatus(404);
    }

    public function test_destroy_unauthenticated_user_cannot_delete_expense()
    {
        $expense = $this->createExpenseForUser($this->user, $this->userCategory);
        $this->deleteJson("{$this->baseApiUrl}/{$expense->id}")->assertStatus(401);
    }
}
