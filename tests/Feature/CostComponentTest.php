<?php

namespace Tests\Feature;

use App\Models\CostComponent;
use App\Models\Product;
use App\Models\ProductCost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CostComponentTest extends TestCase
{
    use RefreshDatabase,WithFaker;

    protected User $user;
    protected User $anotherUser;
    protected string $tokenString;
    protected string $baseApiUrl = '/api/cost-components'; 

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


    public function test_can_list_cost_components_for_authenticated_user()
    {
        CostComponent::factory()->count(3)->create(['user_id' => $this->user->id]);
        CostComponent::factory()->count(2)->create(['user_id' => $this->anotherUser->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson($this->baseApiUrl);

        $response->assertStatus(200)
                 ->assertJson(['success' => true])
                 ->assertJsonCount(3, 'data') 
                 ->assertJsonPath('meta.total_count', 3);
    }

    public function test_returns_default_message_if_user_has_no_cost_components()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson($this->baseApiUrl);

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'message' => 'Daftar komponen biaya',
                     'data' => [],
                     'meta' => ['total_count' => 0]
                 ]);
    }

    public function test_unauthenticated_user_cannot_list_cost_components()
    {
        $this->getJson($this->baseApiUrl)->assertStatus(401);
    }

    public function test_can_filter_cost_components_by_type()
    {
        CostComponent::factory()->create(['user_id' => $this->user->id, 'component_type' => 'direct_material']);
        CostComponent::factory()->create(['user_id' => $this->user->id, 'component_type' => 'overhead']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson($this->baseApiUrl . '?type=direct_material');

        $response->assertStatus(200)
                 ->assertJson(['success' => true])
                 ->assertJsonCount(1, 'data')
                 ->assertJsonPath('data.0.component_type', 'direct_material')
                 ->assertJsonPath('meta.type', 'direct_material')
                 ->assertJsonPath('meta.total_count', 1);
    }

    public function test_returns_error_for_invalid_filter_type_on_index()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson($this->baseApiUrl . '?type=invalid_type');

        $response->assertStatus(400)
                 ->assertJson(['success' => false, 'message' => 'Tipe komponen biaya tidak valid']);
    }

    public function test_can_filter_cost_components_by_keyword()
    {
        CostComponent::factory()->create(['user_id' => $this->user->id, 'name' => 'Bahan Baku Utama', 'description' => 'Kayu Jati']);
        CostComponent::factory()->create(['user_id' => $this->user->id, 'name' => 'Lem Kayu', 'description' => 'Perekat kuat']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson($this->baseApiUrl . '?keyword=Kayu');

        $response->assertStatus(200)
                 ->assertJson(['success' => true])
                 ->assertJsonCount(2, 'data')
                 ->assertJsonPath('meta.keyword', 'Kayu')
                 ->assertJsonPath('meta.total_count', 2);
    }
     public function test_can_filter_cost_components_by_keyword_in_description()
    {
        CostComponent::factory()->create(['user_id' => $this->user->id, 'name' => 'Cat Tembok', 'description' => 'Warna Merah Jambu']);
        CostComponent::factory()->create(['user_id' => $this->user->id, 'name' => 'Kuas Cat', 'description' => 'Untuk aplikasi cat']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson($this->baseApiUrl . '?keyword=Jambu');
        
        $response->assertStatus(200)
                 ->assertJson(['success' => true])
                 ->assertJsonCount(1, 'data')
                 ->assertJsonPath('data.0.name', 'Cat Tembok')
                 ->assertJsonPath('meta.keyword', 'Jambu')
                 ->assertJsonPath('meta.total_count', 1);
    }


    public function test_returns_error_for_empty_keyword_on_index()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson($this->baseApiUrl . '?keyword=');

        $response->assertStatus(400)
                 ->assertJson(['success' => false, 'message' => 'Parameter pencarian (keyword) tidak boleh kosong']);
    }

    public function test_can_filter_by_type_and_keyword_on_index()
    {
        CostComponent::factory()->create(['user_id' => $this->user->id, 'name' => 'Kain Katun', 'component_type' => 'direct_material', 'description' => 'Katun Jepang']);
        CostComponent::factory()->create(['user_id' => $this->user->id, 'name' => 'Benang Jahit', 'component_type' => 'direct_material', 'description' => 'Benang poliester']);
        CostComponent::factory()->create(['user_id' => $this->user->id, 'name' => 'Upah Jahit', 'component_type' => 'direct_labor', 'description' => 'Biaya jahit per potong katun']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson($this->baseApiUrl . '?type=direct_material&keyword=Katun');

        $response->assertStatus(200)
                 ->assertJson(['success' => true])
                 ->assertJsonCount(1, 'data')
                 ->assertJsonPath('data.0.name', 'Kain Katun')
                 ->assertJsonPath('meta.type', 'direct_material')
                 ->assertJsonPath('meta.keyword', 'Katun')
                 ->assertJsonPath('meta.total_count', 1);
    }


    public function test_can_store_new_cost_component()
    {
        $componentData = [
            'name' => 'Biaya Listrik Produksi',
            'description' => 'Biaya listrik bulanan untuk mesin produksi',
            'component_type' => 'overhead',
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->postJson($this->baseApiUrl, $componentData);

        $response->assertStatus(200) 
                 ->assertJsonStructure(['success', 'message', 'data' => ['id', 'name', 'description', 'component_type', 'user_id']])
                 ->assertJson(['success' => true, 'message' => 'Komponen Biaya Berhasil dibuat'])
                 ->assertJsonPath('data.name', $componentData['name'])
                 ->assertJsonPath('data.user_id', $this->user->id);

        $this->assertDatabaseHas('cost_components', [
            'name' => $componentData['name'],
            'user_id' => $this->user->id,
            'component_type' => $componentData['component_type'],
        ]);
    }

    public function test_store_fails_with_validation_errors_for_cost_component()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->postJson($this->baseApiUrl, [
                             'name' => '',
                             'component_type' => 'invalid_type'
                         ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['name', 'component_type']);
    }

    public function test_unauthenticated_user_cannot_store_cost_component()
    {
        $componentData = CostComponent::factory()->make()->toArray();
        $this->postJson($this->baseApiUrl, $componentData)->assertStatus(401);
    }


    public function test_can_show_specific_cost_component_owned_by_user()
    {
        $costComponent = CostComponent::factory()->create(['user_id' => $this->user->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson("{$this->baseApiUrl}/{$costComponent->id}");

        $response->assertStatus(200)
                 ->assertJson(['success' => true])
                 ->assertJsonPath('data.id', $costComponent->id)
                 ->assertJsonPath('data.name', $costComponent->name)
                 ->assertJsonPath('data.user_id', $this->user->id);
    }

    public function test_cannot_show_cost_component_owned_by_another_user()
    {
        $costComponentOtherUser = CostComponent::factory()->create(['user_id' => $this->anotherUser->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson("{$this->baseApiUrl}/{$costComponentOtherUser->id}");

        $response->assertStatus(404)
                 ->assertJson(['success' => false, 'message' => 'Komponen Biaya tidak ditemukan']);
    }

    public function test_returns_not_found_for_non_existent_cost_component_on_show()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->getJson("{$this->baseApiUrl}/9999");

        $response->assertStatus(404)
                 ->assertJson(['success' => false, 'message' => 'Komponen Biaya tidak ditemukan']);
    }

    public function test_unauthenticated_user_cannot_show_cost_component()
    {
        $costComponent = CostComponent::factory()->create(['user_id' => $this->user->id]);
        $this->getJson("{$this->baseApiUrl}/{$costComponent->id}")->assertStatus(401);
    }


    public function test_can_update_owned_cost_component()
    {
        $costComponent = CostComponent::factory()->create(['user_id' => $this->user->id, 'name' => 'Old Name', 'component_type' => 'direct_labor']);
        $updateData = [
            'name' => 'Updated Component Name',
            'description' => 'Updated description.',
            'component_type' => 'overhead',
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->putJson("{$this->baseApiUrl}/{$costComponent->id}", $updateData);

        $response->assertStatus(200)
                 ->assertJson(['success' => true, 'message' => 'Komponen Biaya berhasil diperbarui'])
                 ->assertJsonPath('data.name', $updateData['name'])
                 ->assertJsonPath('data.description', $updateData['description'])
                 ->assertJsonPath('data.component_type', $updateData['component_type']);

        $this->assertDatabaseHas('cost_components', array_merge(['id' => $costComponent->id], $updateData));
    }
    
    public function test_can_partially_update_owned_cost_component()
    {
        $costComponent = CostComponent::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Original Name',
            'description' => 'Original Description',
            'component_type' => 'direct_material'
        ]);
        $updateData = [
            'name' => 'Partially Updated Name',
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->putJson("{$this->baseApiUrl}/{$costComponent->id}", $updateData);

        $response->assertStatus(200)
                 ->assertJson(['success' => true, 'message' => 'Komponen Biaya berhasil diperbarui'])
                 ->assertJsonPath('data.name', $updateData['name'])
                 ->assertJsonPath('data.description', $costComponent->description)
                 ->assertJsonPath('data.component_type', $costComponent->component_type); 

        $this->assertDatabaseHas('cost_components', [
            'id' => $costComponent->id,
            'name' => $updateData['name'],
            'description' => $costComponent->description,
            'component_type' => $costComponent->component_type,
        ]);
    }


    public function test_update_fails_with_validation_errors_for_cost_component()
    {
        $costComponent = CostComponent::factory()->create(['user_id' => $this->user->id]);
        $updateData = [
            'name' => '',
            'component_type' => 'invalid_enum_value', 
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->putJson("{$this->baseApiUrl}/{$costComponent->id}", $updateData);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['name', 'component_type']);
    }

    public function test_cannot_update_cost_component_owned_by_another_user()
    {
        $costComponentOtherUser = CostComponent::factory()->create(['user_id' => $this->anotherUser->id]);
        $updateData = ['name' => 'Attempted Update'];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->putJson("{$this->baseApiUrl}/{$costComponentOtherUser->id}", $updateData);

        $response->assertStatus(404)
                 ->assertJson(['success' => false, 'message' => 'Komponen Biaya tidak ditemukan']);
    }

    public function test_returns_not_found_when_updating_non_existent_cost_component()
    {
        $updateData = ['name' => 'No Such Component'];
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->putJson("{$this->baseApiUrl}/9999", $updateData);

        $response->assertStatus(404)
                 ->assertJson(['success' => false, 'message' => 'Komponen Biaya tidak ditemukan']);
    }

    public function test_unauthenticated_user_cannot_update_cost_component()
    {
        $costComponent = CostComponent::factory()->create(['user_id' => $this->user->id]);
        $updateData = ['name' => 'Unauth Update'];
        $this->putJson("{$this->baseApiUrl}/{$costComponent->id}", $updateData)->assertStatus(401);
    }


    public function test_can_destroy_owned_cost_component_if_not_in_use()
    {
        $costComponent = CostComponent::factory()->create(['user_id' => $this->user->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->deleteJson("{$this->baseApiUrl}/{$costComponent->id}");

        $response->assertStatus(200)
                 ->assertJson(['success' => true, 'message' => 'Komponen Biaya berhasil dihapus']);

        $this->assertDatabaseMissing('cost_components', ['id' => $costComponent->id]);
    }

    public function test_cannot_destroy_cost_component_if_in_use()
    {
        $costComponent = CostComponent::factory()->create(['user_id' => $this->user->id]);
        $product = Product::factory()->create(['user_id' => $this->user->id]);
        ProductCost::factory()->create([
            'product_id' => $product->id,
            'cost_component_id' => $costComponent->id,
            'amount' => 100 
        ]);


        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->deleteJson("{$this->baseApiUrl}/{$costComponent->id}");

        $response->assertStatus(400)
                 ->assertJson(['success' => false, 'message' => 'Komponen Biaya tidak dapat dihapus karena sedang digunakan']);

        $this->assertDatabaseHas('cost_components', ['id' => $costComponent->id]);
    }

    public function test_cannot_destroy_cost_component_owned_by_another_user()
    {
        $costComponentOtherUser = CostComponent::factory()->create(['user_id' => $this->anotherUser->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->deleteJson("{$this->baseApiUrl}/{$costComponentOtherUser->id}");

        $response->assertStatus(404)
                 ->assertJson(['success' => false, 'message' => 'Komponen Biaya tidak ditemukan']);
    }

    public function test_returns_not_found_when_destroying_non_existent_cost_component()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->deleteJson("{$this->baseApiUrl}/9999");

        $response->assertStatus(404)
                 ->assertJson(['success' => false, 'message' => 'Komponen Biaya tidak ditemukan']);
    }

    public function test_unauthenticated_user_cannot_destroy_cost_component()
    {
        $costComponent = CostComponent::factory()->create(['user_id' => $this->user->id]);
        $this->deleteJson("{$this->baseApiUrl}/{$costComponent->id}")->assertStatus(401);
    }
}
