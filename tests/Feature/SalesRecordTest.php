<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SalesRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class SalesRecordTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $anotherUser;
    protected string $tokenString;
    protected string $baseApiUrl = '/api/sales';
    protected Product $userProduct1;
    protected Product $userProduct2;

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

        $this->userProduct1 = Product::factory()->create(['user_id' => $this->user->id, 'name' => 'Product A', 'hpp' => 100, 'selling_price' => 150]);
        $this->userProduct2 = Product::factory()->create(['user_id' => $this->user->id, 'name' => 'Product B', 'hpp' => 200, 'selling_price' => 280]);
    }

    private function createSale(User $user, Product $product, array $attributes = []): SalesRecord
    {
        $defaults = [
            'user_id' => $user->id,
            'product_id' => $product->id,
            'month' => Carbon::now()->month,
            'year' => Carbon::now()->year,
            'number_of_sales' => 10,
            'hpp' => $product->hpp,
            'selling_price' => $product->selling_price, 
        ];
        return SalesRecord::factory()->create(array_merge($defaults, $attributes));
    }


    public function test_can_list_sales_records_for_current_month_and_year_with_summary_and_filters()
    {
        $now = Carbon::now();

        $sale1 = $this->createSale($this->user, $this->userProduct1, ['number_of_sales' => 5, 'selling_price' => 150, 'hpp' => 100, 'month' => $now->month, 'year' => $now->year]); 
        $sale2 = $this->createSale($this->user, $this->userProduct2, ['number_of_sales' => 10, 'selling_price' => 280, 'hpp' => 200, 'month' => $now->month, 'year' => $now->year]); 
        
        $this->createSale($this->user, $this->userProduct1, [
            'month' => $now->copy()->subMonth()->month, 
            'year' => $now->year
        ]);

        SalesRecord::factory()->create(['user_id' => $this->anotherUser->id, 'product_id' => Product::factory()->create(['user_id' => $this->anotherUser->id])->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson($this->baseApiUrl);
        
        $response->assertStatus(200)
                 ->assertJson(['success' => true])
                 ->assertJsonCount(2, 'data')
                 ->assertJsonPath('summary.total_sales', 750 + 2800)
                 ->assertJsonPath('summary.total_profit', 250 + 800)
                 ->assertJsonPath('summary.total_profit_percentage', round((1050 / 3550) * 100, 2)) 
                 ->assertJsonPath('filters.current_year', $now->year)
                 ->assertJsonPath('filters.current_month', $now->month)
                 ->assertJsonPath('data.0.profit_contribution_percentage', round((250 / 1050) * 100, 2))
                 ->assertJsonPath('data.1.profit_contribution_percentage', round((800 / 1050) * 100, 2)); 
    }

    public function test_index_with_specific_year_month_filter()
    {
        $targetYear = 2023;
        $targetMonth = 7;
        $this->createSale($this->user, $this->userProduct1, ['year' => $targetYear, 'month' => $targetMonth, 'number_of_sales' => 3]);
        $this->createSale($this->user, $this->userProduct1, ['year' => $targetYear, 'month' => $targetMonth + 1]); 

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson("{$this->baseApiUrl}?year={$targetYear}&month={$targetMonth}");

        $response->assertStatus(200)
                 ->assertJson(['success' => true])
                 ->assertJsonCount(1, 'data')
                 ->assertJsonPath('data.0.year', $targetYear)
                 ->assertJsonPath('data.0.month', $targetMonth)
                 ->assertJsonPath('filters.current_year', $targetYear)
                 ->assertJsonPath('filters.current_month', $targetMonth);
    }

    public function test_index_validation_fails_for_invalid_year()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson($this->baseApiUrl . '?year=1990');

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['year']);
    }

    public function test_index_validation_fails_for_invalid_month()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson($this->baseApiUrl . '?month=13');

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['month']);
    }
    
    public function test_index_returns_empty_data_if_no_sales_for_period()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson($this->baseApiUrl . '?year=2023&month=1');
        
        $response->assertStatus(200)
                 ->assertJson(['success' => true])
                 ->assertJsonCount(0, 'data')
                 ->assertJsonPath('summary.total_sales', 0)
                 ->assertJsonPath('summary.total_profit', 0);
    }

    public function test_index_unauthenticated_user_cannot_list_sales()
    {
        $this->getJson($this->baseApiUrl)->assertStatus(401);
    }


    public function test_can_store_new_sales_record()
    {
        $year = Carbon::now()->year;
        $month = Carbon::now()->month;
        $salesData = [
            'product_id' => $this->userProduct1->id,
            'month' => $month,
            'year' => $year,
            'number_of_sales' => 15,
            'hpp' => 100, 
            'selling_price' => 150, 
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->postJson($this->baseApiUrl, $salesData);

        $response->assertStatus(200) 
                 ->assertJson(['success' => true, 'message' => 'Penjualan berhasil disimpan'])
                 ->assertJsonPath('data.product_id', $this->userProduct1->id)
                 ->assertJsonPath('data.number_of_sales', 15)
                 ->assertJsonPath('data.user_id', $this->user->id);

        $this->assertDatabaseHas('sales_records', [
            'user_id' => $this->user->id,
            'product_id' => $this->userProduct1->id,
            'month' => $month,
            'year' => $year,
            'number_of_sales' => 15
        ]);
    }

    public function test_store_fails_with_validation_errors()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->postJson($this->baseApiUrl, [
                         ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['product_id', 'month', 'year', 'number_of_sales', 'hpp', 'selling_price']);
    }

    public function test_store_fails_if_product_not_owned_by_user()
    {
        $anotherUserProduct = Product::factory()->create(['user_id' => $this->anotherUser->id]);
        $salesData = [
            'product_id' => $anotherUserProduct->id,
            'month' => 1, 'year' => 2023, 'number_of_sales' => 5, 'hpp' => 50, 'selling_price' => 70
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->postJson($this->baseApiUrl, $salesData);

        $response->assertStatus(404) 
                 ->assertJson(['success' => false, 'message' => 'Produk tidak ditemukan atau bukan milik anda']);
    }

    public function test_store_fails_if_record_already_exists_for_product_month_year()
    {
        $year = 2023; $month = 8;
        $this->createSale($this->user, $this->userProduct1, ['year' => $year, 'month' => $month]);

        $salesData = [
            'product_id' => $this->userProduct1->id,
            'month' => $month,
            'year' => $year,
            'number_of_sales' => 20,
            'hpp' => 100,
            'selling_price' => 150,
        ];
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->postJson($this->baseApiUrl, $salesData);

        $response->assertStatus(422)
                 ->assertJson(['success' => false, 'message' => 'Penjualan untuk produk ini sudah ada di bulan yang diminta']);
    }
    
    public function test_store_unauthenticated_user_cannot_create_sale()
    {
        $salesData = SalesRecord::factory()->make()->toArray();
        $this->postJson($this->baseApiUrl, $salesData)->assertStatus(401);
    }



    public function test_can_show_specific_sales_record_with_calculated_fields()
    {
        $year = 2023; $month = 6;
        $sale = $this->createSale($this->user, $this->userProduct1, [
            'year' => $year, 'month' => $month,
            'number_of_sales' => 10, 'selling_price' => 150, 'hpp' => 100
        ]);
        $this->createSale($this->user, $this->userProduct2, [
            'year' => $year, 'month' => $month,
            'number_of_sales' => 5, 'selling_price' => 280, 'hpp' => 200
        ]);


        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson("{$this->baseApiUrl}/{$sale->id}");

        $profitPerUnit = 150 - 100;
        $totalProfitForThisRecord = $profitPerUnit * 10;
        $totalProfitAllSalesInPeriod = ( (150-100)*10 + (280-200)*5 );
        $expectedContribution = $totalProfitAllSalesInPeriod > 0 ? round(($totalProfitForThisRecord / $totalProfitAllSalesInPeriod) * 100, 2) : 0;

        $response->assertStatus(200)
                 ->assertJson(['success' => true])
                 ->assertJsonPath('data.id', $sale->id)
                 ->assertJsonPath('data.product_name', $this->userProduct1->name)
                 ->assertJsonPath('data.profit_unit', $profitPerUnit)
                 ->assertJsonPath('data.profit_percentage', (int)round(($profitPerUnit / 150) * 100, 0))
                 ->assertJsonPath('data.sub_total', 150 * 10) 
                 ->assertJsonPath('data.total_profit', $totalProfitForThisRecord)
                 ->assertJsonPath('data.profit_contribution_percentage', $expectedContribution);
    }

    public function test_show_returns_404_if_record_not_found()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson("{$this->baseApiUrl}/9999");
        $response->assertStatus(404)
                 ->assertJson(['success' => false, 'message' => 'Penjualan Tidak ditemukan!']);
    }

    public function test_cannot_show_sales_record_owned_by_another_user()
    {
        $anotherUserSale = SalesRecord::factory()->create(['user_id' => $this->anotherUser->id, 'product_id' => Product::factory()->create(['user_id' => $this->anotherUser->id])->id]);
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson("{$this->baseApiUrl}/{$anotherUserSale->id}");
        $response->assertStatus(404);
    }
    
    public function test_show_unauthenticated_user_cannot_view_sale()
    {
        $sale = $this->createSale($this->user, $this->userProduct1);
        $this->getJson("{$this->baseApiUrl}/{$sale->id}")->assertStatus(401);
    }

    public function test_can_update_owned_sales_record()
    {
        $sale = $this->createSale($this->user, $this->userProduct1, ['number_of_sales' => 5]);
        $updateData = [
            'number_of_sales' => 8,
            'selling_price' => 160,
            'hpp' => 110,
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->putJson("{$this->baseApiUrl}/{$sale->id}", $updateData);

        $response->assertStatus(200)
                 ->assertJson(['success' => true, 'message' => 'Penjualan berhasil diperbarui'])
                 ->assertJsonPath('data.number_of_sales', 8)
                 ->assertJsonPath('data.selling_price', 160)
                 ->assertJsonPath('data.hpp', 110);

        $this->assertDatabaseHas('sales_records', [
            'id' => $sale->id, 
            'number_of_sales' => 8, 
            'selling_price' => 160,
            'hpp' => 110
        ]);
    }

    public function test_update_fails_with_validation_errors()
    {
        $sale = $this->createSale($this->user, $this->userProduct1);
        $updateData = ['number_of_sales' => 0, 'selling_price' => 'abc']; 
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->putJson("{$this->baseApiUrl}/{$sale->id}", $updateData);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['number_of_sales', 'selling_price']);
    }

    public function test_update_fails_if_changing_to_product_not_owned_by_user()
    {
        $sale = $this->createSale($this->user, $this->userProduct1);
        $anotherUserProduct = Product::factory()->create(['user_id' => $this->anotherUser->id]);
        $updateData = ['product_id' => $anotherUserProduct->id];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->putJson("{$this->baseApiUrl}/{$sale->id}", $updateData);
        
        $response->assertStatus(404)
                 ->assertJson(['success' => false, 'message' => 'Produk tidak ditemukan atau bukan milik anda']);
    }
    
    public function test_update_fails_if_changing_to_existing_product_month_year_combination()
    {
        $year = 2023; $month = 9;
        $sale1 = $this->createSale($this->user, $this->userProduct1, ['year' => $year, 'month' => $month]);
        $saleToUpdate = $this->createSale($this->user, $this->userProduct2, ['year' => $year, 'month' => $month -1 ]); 

        $updateData = [
            'product_id' => $this->userProduct1->id,
            'month' => $month,
            'year' => $year,
        ];
        
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->putJson("{$this->baseApiUrl}/{$saleToUpdate->id}", $updateData);

        $response->assertStatus(422)
                 ->assertJson(['success' => false, 'message' => 'Penjualan untuk produk ini sudah ada di bulan yang diminta']);
    }


    public function test_cannot_update_sales_record_owned_by_another_user()
    {
        $anotherUserSale = SalesRecord::factory()->create(['user_id' => $this->anotherUser->id, 'product_id' => Product::factory()->create(['user_id' => $this->anotherUser->id])->id]);
        $updateData = ['number_of_sales' => 100];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->putJson("{$this->baseApiUrl}/{$anotherUserSale->id}", $updateData);
        $response->assertStatus(404);
    }
    
    public function test_update_unauthenticated_user_cannot_update_sale()
    {
        $sale = $this->createSale($this->user, $this->userProduct1);
        $this->putJson("{$this->baseApiUrl}/{$sale->id}", [])->assertStatus(401);
    }



    public function test_can_destroy_owned_sales_record()
    {
        $sale = $this->createSale($this->user, $this->userProduct1);
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->deleteJson("{$this->baseApiUrl}/{$sale->id}");
        $response->assertStatus(200)
                 ->assertJson(['success' => true, 'message' => 'Penjualan berhasil dihapus']);
        $this->assertDatabaseMissing('sales_records', ['id' => $sale->id]);
    }

    public function test_cannot_destroy_sales_record_owned_by_another_user()
    {
        $anotherUserSale = SalesRecord::factory()->create(['user_id' => $this->anotherUser->id, 'product_id' => Product::factory()->create(['user_id' => $this->anotherUser->id])->id]);
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->deleteJson("{$this->baseApiUrl}/{$anotherUserSale->id}");
        $response->assertStatus(404);
    }

    public function test_destroy_returns_404_for_non_existent_record()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->deleteJson("{$this->baseApiUrl}/9999");
        $response->assertStatus(404);
    }
    
    public function test_destroy_unauthenticated_user_cannot_delete_sale()
    {
        $sale = $this->createSale($this->user, $this->userProduct1);
        $this->deleteJson("{$this->baseApiUrl}/{$sale->id}")->assertStatus(401);
    }

}
