<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class RegisterTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected string $baseApiUrl = '/api/register';

    protected function setUp(): void
    {
        parent::setUp();
        
        Role::create([
            'name' => 'user',
            'display_name' => 'User',
            'description' => 'Regular user role'
        ]);
    }

    public function test_successful_registration()
    {
        $userData = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123'
        ];

        $response = $this->postJson($this->baseApiUrl, $userData);

        $response->assertStatus(201)
                ->assertJsonStructure([
                    'success',
                    'message',
                    'data' => [
                        'user' => [
                            'id',
                            'name',
                            'email',
                            'created_at',
                            'updated_at'
                        ],
                        'token'
                    ]
                ])
                ->assertJson([
                    'success' => true,
                    'message' => 'User registered successfully'
                ]);

        $this->assertDatabaseHas('users', [
            'name' => 'John Doe',
            'email' => 'john@example.com'
        ]);

        $user = User::where('email', 'john@example.com')->first();
        $this->assertTrue($user->roles->contains('name', 'user'));

        $this->assertTrue(Hash::check('password123', $user->password));

        $token = $response->json('data.token');
        $this->assertNotEmpty($token);
        
        $this->withHeader('Authorization', 'Bearer ' . $token)
             ->getJson('/api/me')
             ->assertStatus(200);
    }

    public function test_registration_fails_without_name()
    {
        $userData = [
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123'
        ];

        $response = $this->postJson($this->baseApiUrl, $userData);

        $response->assertStatus(422)
                ->assertJsonStructure([
                    'errors' => [
                        'name'
                    ]
                ]);

        $this->assertDatabaseMissing('users', [
            'email' => 'john@example.com'
        ]);
    }

    public function test_registration_fails_without_email()
    {
        $userData = [
            'name' => 'John Doe',
            'password' => 'password123',
            'password_confirmation' => 'password123'
        ];

        $response = $this->postJson($this->baseApiUrl, $userData);

        $response->assertStatus(422)
                ->assertJsonStructure([
                    'errors' => [
                        'email'
                    ]
                ]);
    }

    public function test_registration_fails_with_invalid_email()
    {
        $userData = [
            'name' => 'John Doe',
            'email' => 'invalid-email',
            'password' => 'password123',
            'password_confirmation' => 'password123'
        ];

        $response = $this->postJson($this->baseApiUrl, $userData);

        $response->assertStatus(422)
                ->assertJsonStructure([
                    'errors' => [
                        'email'
                    ]
                ]);
    }

    public function test_registration_fails_with_duplicate_email()
    {
        User::create([
            'name' => 'Existing User',
            'email' => 'john@example.com',
            'password' => Hash::make('password')
        ]);

        $userData = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123'
        ];

        $response = $this->postJson($this->baseApiUrl, $userData);

        $response->assertStatus(422)
                ->assertJsonStructure([
                    'errors' => [
                        'email'
                    ]
                ]);
    }

    public function test_registration_fails_with_short_password()
    {
        $userData = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => '123',
            'password_confirmation' => '123'
        ];

        $response = $this->postJson($this->baseApiUrl, $userData);

        $response->assertStatus(422)
                ->assertJsonStructure([
                    'errors' => [
                        'password'
                    ]
                ]);
    }

    public function test_registration_fails_with_mismatched_password_confirmation()
    {
        $userData = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'different_password'
        ];

        $response = $this->postJson($this->baseApiUrl, $userData);

        $response->assertStatus(422)
                ->assertJsonStructure([
                    'errors' => [
                        'password'
                    ]
                ]);
    }

    public function test_registration_fails_with_multiple_validation_errors()
    {
        $userData = [
            'email' => 'invalid-email',
            'password' => '123'
        ];

        $response = $this->postJson($this->baseApiUrl, $userData);

        $response->assertStatus(422)
                ->assertJsonStructure([
                    'errors' => [
                        'name',
                        'email',
                        'password'
                    ]
                ]);
    }

    public function test_registration_fails_when_user_role_not_found()
    {
        Role::where('name', 'user')->delete();

        $userData = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123'
        ];

        $response = $this->postJson($this->baseApiUrl, $userData);

        $response->assertStatus(500)
                ->assertJson([
                    'success' => false,
                    'message' => 'Registration failed',
                    'error' => 'Registration failed: Role "user" not found.'
                ]);

        $this->assertDatabaseMissing('users', [
            'email' => 'john@example.com'
        ]);
    }

    public function test_registration_handles_database_exception()
    {
        Role::where('name', 'user')->delete();
        
        Role::create([
            'name' => 'different_role',
            'display_name' => 'Different Role',
            'description' => 'Different role'
        ]);

        $userData = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123'
        ];

        $response = $this->postJson($this->baseApiUrl, $userData);

        $response->assertStatus(500)
                ->assertJsonStructure([
                    'success',
                    'message',
                    'error'
                ])
                ->assertJson([
                    'success' => false,
                    'message' => 'Registration failed'
                ]);

        $this->assertDatabaseMissing('users', [
            'email' => 'john@example.com'
        ]);
    }

    public function test_registration_handles_jwt_exception()
    {
        JWTAuth::shouldReceive('fromUser')
               ->once()
               ->andThrow(new \Tymon\JWTAuth\Exceptions\JWTException('Could not create token'));

        $userData = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123'
        ];

        $response = $this->postJson($this->baseApiUrl, $userData);

        $response->assertStatus(500)
                ->assertJsonStructure([
                    'success',
                    'message',
                    'error'
                ])
                ->assertJson([
                    'success' => false,
                    'message' => 'Registration failed'
                ]);

        $this->assertDatabaseMissing('users', [
            'email' => 'john@example.com'
        ]);
    }

    public function test_registration_with_maximum_length_values()
    {
        $userData = [
            'name' => str_repeat('a', 255), 
            'email' => str_repeat('a', 243) . '@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123'
        ];

        $response = $this->postJson($this->baseApiUrl, $userData);

        $response->assertStatus(201)
                ->assertJson([
                    'success' => true,
                    'message' => 'User registered successfully'
                ]);
    }

    public function test_registration_with_minimum_length_values()
    {
        $userData = [
            'name' => 'a',
            'email' => 'a@b.c', 
            'password' => 'pwd123',
            'password_confirmation' => 'pwd123'
        ];

        $response = $this->postJson($this->baseApiUrl, $userData);

        $response->assertStatus(201)
                ->assertJson([
                    'success' => true,
                    'message' => 'User registered successfully'
                ]);
    }

    public function test_registration_with_special_characters_in_name()
    {
        $userData = [
            'name' => 'John O\'Connor-Smith Jr.',
            'email' => 'john.oconnor@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123'
        ];

        $response = $this->postJson($this->baseApiUrl, $userData);

        $response->assertStatus(201)
                ->assertJson([
                    'success' => true,
                    'message' => 'User registered successfully'
                ]);

        $this->assertDatabaseHas('users', [
            'name' => 'John O\'Connor-Smith Jr.',
            'email' => 'john.oconnor@example.com'
        ]);
    }

    public function test_transaction_rollback_on_role_attachment_failure()
    {
        $initialUserCount = User::count();

        $userData = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123'
        ];

        Role::where('name', 'user')->delete();

        $response = $this->postJson($this->baseApiUrl, $userData);

        $response->assertStatus(500);

        $this->assertEquals($initialUserCount, User::count());
        $this->assertDatabaseMissing('users', [
            'email' => 'john@example.com'
        ]);
    }
}