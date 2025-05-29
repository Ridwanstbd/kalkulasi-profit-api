<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $anotherUser;
    protected $tokenString;

    protected string $baseApiUrl = '/api/products';

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
            'name' => 'Another Test User',
            'email' => 'another@example.com',
            'password' => Hash::make('password456'),
        ]);
    }

    public function test_can_list_products_for_the_authenticated_user()
    {
        Product::factory()->count(3)->create(['user_id' => $this->user->id]);
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)->getJson($this->baseApiUrl);

        $response->assertStatus(200)
                 ->assertJson(['success' => true])
                 ->assertJsonCount(3, 'data')
                 ->assertJsonPath('stats.total_products', 3);
    }

    public function test_returns_a_message_if_user_has_no_products()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)->getJson($this->baseApiUrl);

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => false,
                     'message' => 'Produk tidak ditemukan.'
                 ]);
    }

    public function test_unauthenticated_user_cannot_list_products()
    {
        $this->getJson($this->baseApiUrl)->assertStatus(401);
    }

    public function test_it_can_store_a_new_product()
    {
        $productData = [
            'user_id' => $this->user->id, 
            'name' => 'Produk Baru Saya',
            'sku' => 'SKU-001-NEW',
            'hpp' => 50000,
            'selling_price' => 75000,
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->postJson($this->baseApiUrl, $productData);

        $response->assertStatus(201)
                 ->assertJson([
                     'success' => true,
                     'message' => 'Produk berhasil dibuat',
                     'data' => [
                         'name' => 'Produk Baru Saya',
                         'user_id' => $this->user->id,
                     ]
                 ]);

        $this->assertDatabaseHas('products', [
            'sku' => 'SKU-001-NEW',
            'user_id' => $this->user->id
        ]);
    }

    public function test_it_cannot_store_product_by_specifying_different_user_id_in_payload()
    {
        $productData = [
            'user_id' => $this->anotherUser->id,
            'name' => 'Produk Curian',
            'sku' => 'SKU-002-FORBIDDEN',
            'hpp' => 10000,
            'selling_price' => 20000,
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->postJson($this->baseApiUrl, $productData);

        $response->assertStatus(403)
                 ->assertJson(['message' => 'Tidak diizinkan membuat produk untuk pengguna lain']);

        $this->assertDatabaseMissing('products', ['sku' => 'SKU-002-FORBIDDEN']);
    }


    public function test_store_fails_with_validation_errors()
    {
        $invalidData = [
            'user_id' => $this->user->id, 
            'name' => '', 
            'sku' => '',  
            'hpp' => null, 
            'selling_price' => null 
        ];
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->postJson($this->baseApiUrl, $invalidData);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['name', 'sku']);
    }


    public function test_it_can_show_a_specific_product_owned_by_authenticated_user()
    {
        $product = Product::factory()->create(['user_id' => $this->user->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson("/api/products/{$product->id}");

        $response->assertStatus(200)
                 ->assertJson(['success' => true])
                 ->assertJsonPath('data.id', $product->id)
                 ->assertJsonPath('data.user_id', $this->user->id);
    }


    public function test_it_cannot_show_a_product_owned_by_another_user()
    {
        $productOwnedByAnother = Product::factory()->create(['user_id' => $this->anotherUser->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson("/api/products/{$productOwnedByAnother->id}");

        $response->assertStatus(404)
                 ->assertJson(['message' => 'Product tidak ditemukan']);
    }

    public function test_it_can_update_a_product_owned_by_authenticated_user()
    {
        $product = Product::factory()->create(['user_id' => $this->user->id]);
        $updateData = [
            'name' => 'Nama Produk Diperbarui',
            'selling_price' => 80000,
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->putJson("/api/products/{$product->id}", $updateData);

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'message' => 'Produk berhasil diperbarui',
                 ])
                 ->assertJsonPath('data.name', 'Nama Produk Diperbarui')
                 ->assertJsonPath('data.selling_price', 80000);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Nama Produk Diperbarui',
            'selling_price' => 80000
        ]);
    }

    public function test_update_fails_with_validation_errors()
    {
        $product = Product::factory()->create(['user_id' => $this->user->id]);
        $updateData = [
            'name' => '',          
            'hpp' => 'bukanangka'  
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->putJson("/api/products/{$product->id}", $updateData);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'hpp']);
    }


    public function test_it_cannot_update_a_product_owned_by_another_user()
    {
        $productOwnedByAnother = Product::factory()->create(['user_id' => $this->anotherUser->id]);
        $updateData = ['name' => 'Coba Update Produk Orang Lain'];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->putJson("/api/products/{$productOwnedByAnother->id}", $updateData);

        $response->assertStatus(404)
                 ->assertJson(['message' => 'Produk tidak ditemukan atau bukan milik pengguna']);
    }


    public function test_it_can_destroy_a_product_owned_by_authenticated_user()
    {
        $product = Product::factory()->create(['user_id' => $this->user->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->deleteJson("/api/products/{$product->id}");

        $response->assertStatus(200)
                 ->assertJson(['message' => 'Produk berhasil dihapus']);

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_it_cannot_destroy_a_product_owned_by_another_user()
    {
        $productOwnedByAnother = Product::factory()->create(['user_id' => $this->anotherUser->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->deleteJson("/api/products/{$productOwnedByAnother->id}");

        $response->assertStatus(404)
                 ->assertJson(['message' => 'Produk tidak ditemukan atau bukan milik pengguna']);
    }
}
