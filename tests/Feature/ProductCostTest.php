<?php

namespace Tests\Feature;

use App\Models\CostComponent;
use App\Models\Product;
use App\Models\ProductCost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ProductCostTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $anotherUser;
    protected string $tokenString;
    protected string $baseApiUrl = '/api/hpp';

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


    public function test_can_list_product_costs_for_given_product_id()
    {
        $product = Product::factory()->create(['user_id' => $this->user->id]);
        ProductCost::factory()->count(3)->create(['product_id' => $product->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson($this->baseApiUrl . '?product_id=' . $product->id);

        $response->assertStatus(200)
                 ->assertJson(['success' => true, 'message' => 'Komponen biaya produk berhasil ditampilkan!'])
                 ->assertJsonCount(3, 'data')
                 ->assertJsonPath('product.id', $product->id);
    }

    public function test_index_returns_first_product_costs_if_no_product_id_provided()
    {
        $product1 = Product::factory()->create(['user_id' => $this->user->id, 'created_at' => now()->subDay()]);
        Product::factory()->create(['user_id' => $this->user->id, 'created_at' => now()]); 
        ProductCost::factory()->count(2)->create(['product_id' => $product1->id]);


        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson($this->baseApiUrl);

        $response->assertStatus(200)
                 ->assertJson(['success' => true])
                 ->assertJsonPath('product.id', $product1->id) 
                 ->assertJsonCount(2, 'data');
    }

    public function test_index_returns_message_if_user_has_no_products()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson($this->baseApiUrl);

        $response->assertStatus(200) 
                 ->assertJson([
                     'success' => false,
                     'message' => 'Produk yang terhubung masih kosong'
                 ]);
    }

    public function test_index_returns_message_if_product_has_no_costs()
    {
        $product = Product::factory()->create(['user_id' => $this->user->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson($this->baseApiUrl . '?product_id=' . $product->id);

        $response->assertStatus(200) 
                 ->assertJson([
                     'success' => false,
                     'message' => 'Komponen biaya untuk produk ini masih kosong',
                 ])
                 ->assertJsonPath('product.id', $product->id);
    }
    
    public function test_index_cannot_list_costs_for_product_owned_by_another_user()
    {
        $anotherUserProduct = Product::factory()->create(['user_id' => $this->anotherUser->id]);
        Product::factory()->create(['user_id' => $this->user->id]); 

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson($this->baseApiUrl . '?product_id=' . $anotherUserProduct->id);

        $response->assertStatus(200);
        $jsonResponse = $response->json();
        if (isset($jsonResponse['product']['id'])) {
            $this->assertNotEquals($anotherUserProduct->id, $jsonResponse['product']['id']);
        } else {
            $this->assertTrue(true);
        }
    }


    public function test_index_unauthenticated_user_cannot_list_product_costs()
    {
        $this->getJson($this->baseApiUrl)->assertStatus(401);
    }


    public function test_can_store_product_costs_and_hpp_is_calculated()
    {
        $product = Product::factory()->create(['user_id' => $this->user->id, 'hpp' => 0]);
        $component1 = CostComponent::factory()->create(['user_id' => $this->user->id]);
        $component2 = CostComponent::factory()->create(['user_id' => $this->user->id]);

        $costsData = [
            'product_id' => $product->id,
            'costs' => [
                [
                    'cost_component_id' => $component1->id,
                    'unit' => 'kg',
                    'unit_price' => 100,
                    'quantity' => 2,
                    'conversion_qty' => 1, 
                ],
                [
                    'cost_component_id' => $component2->id,
                    'unit' => 'pcs',
                    'unit_price' => 50,
                    'quantity' => 3,
                    'conversion_qty' => 1, 
                ],
            ]
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->postJson($this->baseApiUrl, $costsData);
        
        $response->assertStatus(201)
                 ->assertJson(['success' => true, 'message' => 'HPP berhasil disimpan'])
                 ->assertJsonPath('data.id', $product->id)
                 ->assertJsonPath('data.hpp', '350.00');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'hpp' => '350.00']);
        $this->assertDatabaseCount('product_costs', 2);
    }
    
    public function test_store_product_costs_with_conversion_qty()
    {
        $product = Product::factory()->create(['user_id' => $this->user->id, 'hpp' => 0]);
        $component = CostComponent::factory()->create(['user_id' => $this->user->id]);

        $costsData = [
            'product_id' => $product->id,
            'costs' => [
                [
                    'cost_component_id' => $component->id,
                    'unit' => 'gram', 
                    'unit_price' => 10000, 
                    'quantity' => 200,    
                    'conversion_qty' => 1000, 
                ], 
            ]
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->postJson($this->baseApiUrl, $costsData);

        $response->assertStatus(201)
                 ->assertJsonPath('data.hpp', '2000.00');
        $this->assertDatabaseHas('products', ['id' => $product->id, 'hpp' => '2000.00']); 
    }


    public function test_store_fails_with_validation_errors()
    {
        $product = Product::factory()->create(['user_id' => $this->user->id]);
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->postJson($this->baseApiUrl, [
                             'product_id' => $product->id, 
                             'costs' => [
                                 ['cost_component_id' => 999] 
                             ]
                         ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors([
                     'costs.0.cost_component_id', 
                     'costs.0.unit',
                     'costs.0.unit_price',
                     'costs.0.quantity',
                     'costs.0.conversion_qty'
                 ]);
    }

    public function test_store_fails_if_product_not_owned_by_user()
    {
        $anotherUserProduct = Product::factory()->create(['user_id' => $this->anotherUser->id]);
        $component = CostComponent::factory()->create(['user_id' => $this->user->id]);
        $costsData = [
            'product_id' => $anotherUserProduct->id,
            'costs' => [[ 'cost_component_id' => $component->id, 'unit' => 'kg', 'unit_price' => 10, 'quantity' => 1, 'conversion_qty' => 1 ]]
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->postJson($this->baseApiUrl, $costsData);

        $response->assertStatus(404) 
                 ->assertJson(['success' => false, 'message' => 'Produk tidak ditemukan atau bukan milik Anda']);
    }

    public function test_store_fails_if_duplicate_cost_components_provided_in_payload()
    {
        $product = Product::factory()->create(['user_id' => $this->user->id]);
        $component = CostComponent::factory()->create(['user_id' => $this->user->id]);
        $costsData = [
            'product_id' => $product->id,
            'costs' => [
                [ 'cost_component_id' => $component->id, 'unit' => 'kg', 'unit_price' => 10, 'quantity' => 1, 'conversion_qty' => 1 ],
                [ 'cost_component_id' => $component->id, 'unit' => 'kg', 'unit_price' => 10, 'quantity' => 1, 'conversion_qty' => 1 ] 
            ]
        ];
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->postJson($this->baseApiUrl, $costsData);

        $response->assertStatus(422)
                 ->assertJson(['success' => false, 'message' => 'Terdapat komponen biaya yang diinput lebih dari satu kali']);
    }

    public function test_store_fails_if_cost_component_already_exists_for_product_in_db()
    {
        $product = Product::factory()->create(['user_id' => $this->user->id]);
        $component = CostComponent::factory()->create(['user_id' => $this->user->id]);
        ProductCost::factory()->create(['product_id' => $product->id, 'cost_component_id' => $component->id]); 

        $costsData = [
            'product_id' => $product->id,
            'costs' => [[ 'cost_component_id' => $component->id, 'unit' => 'kg', 'unit_price' => 10, 'quantity' => 1, 'conversion_qty' => 1 ]]
        ];
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->postJson($this->baseApiUrl, $costsData);

        $response->assertStatus(422)
                 ->assertJson(['success' => false, 'message' => 'Komponen biaya sudah ada dalam produk ini']);
    }

    public function test_store_unauthenticated_user_cannot_store_product_costs()
    {
        $product = Product::factory()->create();
        $costsData = ['product_id' => $product->id, 'costs' => []];
        $this->postJson($this->baseApiUrl, $costsData)->assertStatus(401);
    }


    public function test_can_show_specific_product_cost_detail()
    {
        $product = Product::factory()->create(['user_id' => $this->user->id]);
        $productCost = ProductCost::factory()->create(['product_id' => $product->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson("{$this->baseApiUrl}/{$productCost->id}");

        $response->assertStatus(200)
                 ->assertJson(['success' => true, 'message' => 'Detail komponen biaya produk'])
                 ->assertJsonPath('data.id', $productCost->id);
    }

    public function test_show_returns_message_if_product_cost_not_found()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson("{$this->baseApiUrl}/9999"); 

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true, 
                     'message' => 'Produk belum memiliki komponen biaya',
                     'data' => [] 
                 ]);
    }
    
    public function test_show_allows_viewing_product_cost_even_if_parent_product_not_owned_by_user()
    {
        $anotherUserProduct = Product::factory()->create(['user_id' => $this->anotherUser->id]);
        $productCostOfAnotherUser = ProductCost::factory()->create(['product_id' => $anotherUserProduct->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson("{$this->baseApiUrl}/{$productCostOfAnotherUser->id}");

        $response->assertStatus(200)
                 ->assertJson(['success' => true])
                 ->assertJsonPath('data.id', $productCostOfAnotherUser->id);
    }


    public function test_show_unauthenticated_user_cannot_view_product_cost_detail()
    {
        $productCost = ProductCost::factory()->create();
        $this->getJson("{$this->baseApiUrl}/{$productCost->id}")->assertStatus(401);
    }


    public function test_can_update_specific_product_cost_and_hpp_is_recalculated()
    {
        $product = Product::factory()->create(['user_id' => $this->user->id]);
        $component = CostComponent::factory()->create(['user_id' => $this->user->id]);
        $productCost = ProductCost::factory()->create([
            'product_id' => $product->id,
            'cost_component_id' => $component->id,
            'unit_price' => 100,
            'quantity' => 1,
            'conversion_qty' => 1,
            'amount' => 100
        ]);
        $product->update(['hpp' => '100.00']); 

        $updateData = [
            'product_id' => $product->id,
            'cost_component_id' => $component->id, 
            'unit' => 'meter',
            'unit_price' => 120,
            'quantity' => 2,
            'conversion_qty' => 1, 
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->putJson("{$this->baseApiUrl}/{$productCost->id}", $updateData);
        
        $response->assertStatus(200)
                 ->assertJson(['success' => true, 'message' => 'Komponen biaya berhasil diperbarui']);

        $this->assertDatabaseHas('product_costs', [
            'id' => $productCost->id,
            'unit_price' => 120, 
            'amount' => '240.00' 
        ]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'hpp' => '240.00']);
    }

    public function test_update_fails_with_validation_errors_for_product_cost()
    {
        $product = Product::factory()->create(['user_id' => $this->user->id]);
        $productCost = ProductCost::factory()->create(['product_id' => $product->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->putJson("{$this->baseApiUrl}/{$productCost->id}", [
                             'unit_price' => -10 
                         ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors([
                    'cost_component_id', 'unit', 'quantity', 'conversion_qty', 
                    'unit_price' 
                    ]);
    }
    
    public function test_update_fails_if_product_cost_not_found()
    {
        $updateData = [
            'cost_component_id' => CostComponent::factory()->create(['user_id' => $this->user->id])->id,
            'unit' => 'kg', 'unit_price' => 10, 'quantity' => 1, 'conversion_qty' => 1
        ];
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->putJson("{$this->baseApiUrl}/99999", $updateData); 
        
        $response->assertStatus(404);
    }


    public function test_update_fails_if_product_not_owned_by_user()
    {
        $anotherUserProduct = Product::factory()->create(['user_id' => $this->anotherUser->id]);
        $productCost = ProductCost::factory()->create(['product_id' => $anotherUserProduct->id]);
        $updateData = [
            'cost_component_id' => CostComponent::factory()->create(['user_id' => $this->anotherUser->id])->id,
            'unit' => 'kg', 'unit_price' => 150, 'quantity' => 1, 'conversion_qty' => 1
        ];


        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->putJson("{$this->baseApiUrl}/{$productCost->id}", $updateData);

        $response->assertStatus(403) 
                 ->assertJson(['success' => false, 'message' => 'Produk tidak ditemukan atau bukan milik Anda']);
    }

    public function test_update_fails_if_updating_to_an_already_existing_cost_component_for_that_product()
    {
        $product = Product::factory()->create(['user_id' => $this->user->id]);
        $component1 = CostComponent::factory()->create(['user_id' => $this->user->id]);
        $component2 = CostComponent::factory()->create(['user_id' => $this->user->id]);

        ProductCost::factory()->create(['product_id' => $product->id, 'cost_component_id' => $component1->id]);
        $productCostToUpdate = ProductCost::factory()->create(['product_id' => $product->id, 'cost_component_id' => $component2->id]);

        $updateData = [
            'cost_component_id' => $component1->id, 
            'unit' => 'pcs', 'unit_price' => 20, 'quantity' => 5, 'conversion_qty' => 1
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->putJson("{$this->baseApiUrl}/{$productCostToUpdate->id}", $updateData);

        $response->assertStatus(422)
                 ->assertJson(['success' => false, 'message' => 'Komponen biaya ini sudah ada dalam produk']);
    }

    public function test_update_unauthenticated_user_cannot_update_product_cost()
    {
        $productCost = ProductCost::factory()->create();
        $this->putJson("{$this->baseApiUrl}/{$productCost->id}", [])->assertStatus(401);
    }


    public function test_can_destroy_specific_product_cost_and_hpp_is_recalculated()
    {
        $product = Product::factory()->create(['user_id' => $this->user->id]);
        $component1 = CostComponent::factory()->create(['user_id' => $this->user->id]);
        $component2 = CostComponent::factory()->create(['user_id' => $this->user->id]);

        $pc1 = ProductCost::factory()->create(['product_id' => $product->id, 'cost_component_id' => $component1->id, 'amount' => '100.00']);
        ProductCost::factory()->create(['product_id' => $product->id, 'cost_component_id' => $component2->id, 'amount' => '50.00']);
        $product->update(['hpp' => '150.00']);


        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->deleteJson("{$this->baseApiUrl}/{$pc1->id}");

        $response->assertStatus(200)
                 ->assertJson(['success' => true, 'message' => 'Komponen biaya berhasil dihapus']);

        $this->assertDatabaseMissing('product_costs', ['id' => $pc1->id]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'hpp' => '50.00']); 
    }

    public function test_destroy_fails_if_product_cost_not_found()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->deleteJson("{$this->baseApiUrl}/99999"); 
        $response->assertStatus(404);
    }

    public function test_destroy_fails_if_product_not_owned_by_user()
    {
        $anotherUserProduct = Product::factory()->create(['user_id' => $this->anotherUser->id]);
        $productCost = ProductCost::factory()->create(['product_id' => $anotherUserProduct->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->deleteJson("{$this->baseApiUrl}/{$productCost->id}");

        $response->assertStatus(403) 
                 ->assertJson(['success' => false, 'message' => 'Produk tidak ditemukan atau bukan milik Anda']);
    }

    public function test_destroy_unauthenticated_user_cannot_delete_product_cost()
    {
        $productCost = ProductCost::factory()->create();
        $this->deleteJson("{$this->baseApiUrl}/{$productCost->id}")->assertStatus(401);
    }
}
