<?php

namespace Tests\Feature;

use App\Models\ExpenseCategory;
use App\Models\OperationalExpense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ExpenseCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $anotherUser;
    protected string $tokenString;
    protected string $baseApiUrl = '/api/expense-categories';

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

        OperationalExpense::where('user_id', $this->user->id)->delete();
        ExpenseCategory::where('user_id', $this->user->id)->delete();
    }

    private function createCategoryForUser(array $attributes = []): ExpenseCategory
    {
        return ExpenseCategory::factory()->create(array_merge(['user_id' => $this->user->id], $attributes));
    }

    private function createOperationalExpenseForCategory(ExpenseCategory $category, array $attributes = []): OperationalExpense
    {
        // Pastikan total_amount dihitung dari quantity * amount jika tidak disediakan
        if (!isset($attributes['total_amount']) && isset($attributes['amount'])) {
            $quantity = $attributes['quantity'] ?? 1;
            $attributes['total_amount'] = $quantity * $attributes['amount'];
        }
        
        return OperationalExpense::factory()->create(array_merge([
            'user_id' => $category->user_id,
            'expense_category_id' => $category->id,
        ], $attributes));
    }

    // --- Test INDEX ---

    public function test_can_list_expense_categories_for_authenticated_user()
    {
        // Buat kategori gaji dengan 2 operational expenses
        $category1 = $this->createCategoryForUser(['name' => 'Gaji Karyawan', 'is_salary' => true]);
        $opEx1_1 = $this->createOperationalExpenseForCategory($category1, [
            'quantity' => 1,
            'amount' => 5000000,
            'total_amount' => 5000000
        ]);
        $opEx1_2 = $this->createOperationalExpenseForCategory($category1, [
            'quantity' => 1,
            'amount' => 6000000,
            'total_amount' => 6000000
        ]);

        // Buat kategori operasional dengan 1 operational expense
        $category2 = $this->createCategoryForUser(['name' => 'Biaya Listrik', 'is_salary' => false]);
        $opEx2_1 = $this->createOperationalExpenseForCategory($category2, [
            'quantity' => 1,
            'amount' => 1000000,
            'total_amount' => 1000000
        ]);

        // Category for another user, should not be listed
        ExpenseCategory::factory()->create(['user_id' => $this->anotherUser->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson($this->baseApiUrl);

        $expectedTotalEmployeesCat1 = $category1->operationalExpenses()->count();
        $expectedTotalSalary = 5000000 + 6000000; // 11000000
        $expectedTotalOperational = 1000000;
        $expectedGrandTotal = $expectedTotalSalary + $expectedTotalOperational; // 12000000

        $response->assertStatus(200)
                 ->assertJson(['success' => true])
                 ->assertJsonCount(2, 'data')
                 ->assertJsonPath('data.0.name', $category1->name)
                 ->assertJsonPath('data.0.total_amount', number_format($expectedTotalSalary, 2, '.', ''))
                 ->assertJsonPath('data.0.total_employees', $expectedTotalEmployeesCat1)
                 ->assertJsonPath('data.1.name', $category2->name)
                 ->assertJsonPath('data.1.total_amount', number_format($expectedTotalOperational, 2, '.', ''))
                 ->assertJsonMissingPath('data.1.total_employees')
                 ->assertJsonStructure([
                     'success',
                     'data' => [
                         '*' => ['id', 'name', 'is_salary', 'total_amount']
                     ],
                     'summary' => ['total_salary', 'total_operational', 'grand_total']
                 ])
                 ->assertJsonPath('summary.total_salary', number_format($expectedTotalSalary, 2, '.', ''))
                 ->assertJsonPath('summary.total_operational', number_format($expectedTotalOperational, 2, '.', ''))
                 ->assertJsonPath('summary.grand_total', number_format($expectedGrandTotal, 2, '.', ''));
    }

    public function test_index_returns_empty_list_and_zero_summary_if_user_has_no_categories_or_expenses()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson($this->baseApiUrl);

        $response->assertStatus(200)
                 ->assertJson(['success' => true])
                 ->assertJsonCount(0, 'data')
                 ->assertJsonPath('summary.total_salary', '0.00')
                 ->assertJsonPath('summary.total_operational', '0.00')
                 ->assertJsonPath('summary.grand_total', '0.00');
    }

    public function test_index_unauthenticated_user_cannot_list_categories()
    {
        $this->getJson($this->baseApiUrl)->assertStatus(401);
    }

    // --- Test STORE ---

    public function test_can_store_new_expense_category()
    {
        $categoryData = [
            'name' => 'Biaya Transportasi',
            'description' => 'Pengeluaran untuk bensin dan tol',
            'is_salary' => false,
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->postJson($this->baseApiUrl, $categoryData);

        $response->assertStatus(201)
                 ->assertJson(['success' => true, 'message' => 'Kategori biaya berhasil dibuat'])
                 ->assertJsonPath('data.name', $categoryData['name'])
                 ->assertJsonPath('data.is_salary', false)
                 ->assertJsonPath('data.user_id', $this->user->id);

        $this->assertDatabaseHas('expense_categories', [
            'name' => $categoryData['name'],
            'user_id' => $this->user->id,
            'is_salary' => false,
        ]);
    }
    
    public function test_can_store_new_salary_expense_category()
    {
        $categoryData = [
            'name' => 'Gaji Staff IT',
            'is_salary' => true,
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->postJson($this->baseApiUrl, $categoryData);

        $response->assertStatus(201)
                 ->assertJsonPath('data.name', $categoryData['name'])
                 ->assertJsonPath('data.is_salary', true);
    }

    public function test_store_fails_with_validation_errors()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->postJson($this->baseApiUrl, [
                             'name' => '', 
                             'is_salary' => 'not_a_boolean' 
                         ]);

        $response->assertStatus(422)
                 ->assertJson(['success' => false])
                 ->assertJsonValidationErrors(['name', 'is_salary']);
    }

    public function test_store_unauthenticated_user_cannot_create_category()
    {
        $categoryData = ExpenseCategory::factory()->make()->toArray();
        $this->postJson($this->baseApiUrl, $categoryData)->assertStatus(401);
    }

    // --- Test SHOW ---

    public function test_can_show_specific_expense_category_owned_by_user()
    {
        $category = $this->createCategoryForUser(['name' => 'Sewa Kantor', 'is_salary' => false]);
        $opEx1 = $this->createOperationalExpenseForCategory($category, [
            'quantity' => 1,
            'amount' => 2000000,
            'total_amount' => 2000000
        ]);
        $opEx2 = $this->createOperationalExpenseForCategory($category, [
            'quantity' => 1,
            'amount' => 2500000,
            'total_amount' => 2500000
        ]);

        $expectedTotal = 2000000 + 2500000; // 4500000

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson("{$this->baseApiUrl}/{$category->id}");

        $response->assertStatus(200)
                 ->assertJson(['success' => true])
                 ->assertJsonPath('data.id', $category->id)
                 ->assertJsonPath('data.name', 'Sewa Kantor')
                 ->assertJsonPath('data.total_amount', number_format($expectedTotal, 2, '.', ''))
                 ->assertJsonMissingPath('data.total_employees');
    }
    
    public function test_can_show_specific_salary_category_with_employee_count()
    {
        $category = $this->createCategoryForUser(['name' => 'Gaji Marketing', 'is_salary' => true]);
        $opEx1 = $this->createOperationalExpenseForCategory($category, [
            'quantity' => 1,
            'amount' => 7000000,
            'total_amount' => 7000000
        ]);
        $opEx2 = $this->createOperationalExpenseForCategory($category, [
            'quantity' => 1,
            'amount' => 7500000,
            'total_amount' => 7500000
        ]);
        $opEx3 = $this->createOperationalExpenseForCategory($category, [
            'quantity' => 1,
            'amount' => 7200000,
            'total_amount' => 7200000
        ]);

        $expectedTotal = 7000000 + 7500000 + 7200000; // 21700000
        $expectedTotalEmployees = $category->operationalExpenses()->count();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson("{$this->baseApiUrl}/{$category->id}");

        $response->assertStatus(200)
                 ->assertJsonPath('data.id', $category->id)
                 ->assertJsonPath('data.total_amount', number_format($expectedTotal, 2, '.', ''))
                 ->assertJsonPath('data.total_employees', $expectedTotalEmployees);
    }

    public function test_cannot_show_category_owned_by_another_user()
    {
        $categoryOtherUser = ExpenseCategory::factory()->create(['user_id' => $this->anotherUser->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson("{$this->baseApiUrl}/{$categoryOtherUser->id}");

        $response->assertStatus(404)
                 ->assertJson(['success' => false, 'message' => 'Kategori biaya tidak ditemukan']);
    }

    public function test_show_returns_404_for_non_existent_category()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson("{$this->baseApiUrl}/9999"); 

        $response->assertStatus(404)
                 ->assertJson(['success' => false, 'message' => 'Kategori biaya tidak ditemukan']);
    }

    public function test_show_unauthenticated_user_cannot_view_category()
    {
        $category = $this->createCategoryForUser();
        $this->getJson("{$this->baseApiUrl}/{$category->id}")->assertStatus(401);
    }

    // --- Test UPDATE ---

    public function test_can_update_owned_expense_category()
    {
        $category = $this->createCategoryForUser(['name' => 'Old Name', 'is_salary' => false]);
        $updateData = [
            'name' => 'Updated Category Name',
            'description' => 'Updated description.',
            'is_salary' => true,
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->putJson("{$this->baseApiUrl}/{$category->id}", $updateData);

        $response->assertStatus(200)
                 ->assertJson(['success' => true, 'message' => 'Kategori biaya berhasil diperbarui'])
                 ->assertJsonPath('data.name', $updateData['name'])
                 ->assertJsonPath('data.description', $updateData['description'])
                 ->assertJsonPath('data.is_salary', true);

        $this->assertDatabaseHas('expense_categories', array_merge(['id' => $category->id], $updateData));
    }

    public function test_update_fails_with_validation_errors()
    {
        $category = $this->createCategoryForUser();
        $updateData = [
            'name' => '', 
            'is_salary' => 'not_a_boolean_value'
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->putJson("{$this->baseApiUrl}/{$category->id}", $updateData);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['name', 'is_salary']);
    }

    public function test_cannot_update_category_owned_by_another_user()
    {
        $categoryOtherUser = ExpenseCategory::factory()->create(['user_id' => $this->anotherUser->id]);
        $updateData = ['name' => 'Attempted Update'];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->putJson("{$this->baseApiUrl}/{$categoryOtherUser->id}", $updateData);

        $response->assertStatus(404)
                 ->assertJson(['success' => false, 'message' => 'Kategori biaya tidak ditemukan']);
    }

    public function test_update_returns_404_for_non_existent_category()
    {
        $updateData = ['name' => 'No Such Category'];
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->putJson("{$this->baseApiUrl}/9999", $updateData);

        $response->assertStatus(404)
                 ->assertJson(['success' => false, 'message' => 'Kategori biaya tidak ditemukan']);
    }
    
    public function test_update_unauthenticated_user_cannot_update_category()
    {
        $category = $this->createCategoryForUser();
        $updateData = ['name' => 'Unauth Update'];
        $this->putJson("{$this->baseApiUrl}/{$category->id}", $updateData)->assertStatus(401);
    }

    // --- Test DESTROY ---

    public function test_can_destroy_owned_expense_category_if_not_in_use()
    {
        $category = $this->createCategoryForUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->deleteJson("{$this->baseApiUrl}/{$category->id}");

        $response->assertStatus(200)
                 ->assertJson(['success' => true, 'message' => 'Kategori biaya berhasil dihapus']);

        $this->assertDatabaseMissing('expense_categories', ['id' => $category->id]);
    }

    public function test_cannot_destroy_category_if_it_has_operational_expenses()
    {
        $category = $this->createCategoryForUser();
        $this->createOperationalExpenseForCategory($category, ['amount' => 1000]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->deleteJson("{$this->baseApiUrl}/{$category->id}");

        $response->assertStatus(422) 
                 ->assertJson(['success' => false, 'message' => 'Kategori ini memiliki item biaya. Hapus semua item biaya terlebih dahulu.']);

        $this->assertDatabaseHas('expense_categories', ['id' => $category->id]);
    }

    public function test_cannot_destroy_category_owned_by_another_user()
    {
        $categoryOtherUser = ExpenseCategory::factory()->create(['user_id' => $this->anotherUser->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->deleteJson("{$this->baseApiUrl}/{$categoryOtherUser->id}");
        
        $response->assertStatus(404)
                 ->assertJson(['success' => false, 'message' => 'Kategori biaya tidak ditemukan']);
    }

    public function test_destroy_returns_404_for_non_existent_category()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->deleteJson("{$this->baseApiUrl}/9999");

        $response->assertStatus(404)
                 ->assertJson(['success' => false, 'message' => 'Kategori biaya tidak ditemukan']);
    }

    public function test_destroy_unauthenticated_user_cannot_delete_category()
    {
        $category = $this->createCategoryForUser();
        $this->deleteJson("{$this->baseApiUrl}/{$category->id}")->assertStatus(401);
    }
}