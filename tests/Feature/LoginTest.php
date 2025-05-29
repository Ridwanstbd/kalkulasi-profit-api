<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class LoginTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $user;
    protected $adminUser;

    protected string $baseApiUrl = '/api/login';

    protected function setUp(): void
    {
        parent::setUp();
        
        $userRole = Role::create([
            'name' => 'user',
            'display_name' => 'User',
            'description' => 'Regular user role'
        ]);

        $adminRole = Role::create([
            'name' => 'admin',
            'display_name' => 'Administrator',
            'description' => 'Administrator role'
        ]);

        $this->user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => Hash::make('password123')
        ]);
        $this->user->roles()->attach($userRole);

        $this->adminUser = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => Hash::make('admin123')
        ]);
        $this->adminUser->roles()->attach($adminRole);
    }

    public function test_successful_login()
    {
        $loginData = [
            'email' => 'john@example.com',
            'password' => 'password123'
        ];

        $response = $this->postJson($this->baseApiUrl, $loginData);

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'message',
                    'user' => [
                        'id',
                        'name',
                        'email',
                        'roles',
                        'is_admin'
                    ],
                    'authorization' => [
                        'token',
                        'type',
                        'expires_in',
                        'remember_me'
                    ]
                ])
                ->assertJson([
                    'success' => true,
                    'message' => 'Login berhasil',
                    'user' => [
                        'id' => $this->user->id,
                        'name' => 'John Doe',
                        'email' => 'john@example.com',
                        'is_admin' => false
                    ],
                    'authorization' => [
                        'type' => 'Bearer',
                        'remember_me' => false
                    ]
                ]);

        $token = $response->json('authorization.token');
        $this->assertNotEmpty($token);
        
        $this->withHeader('Authorization', 'Bearer ' . $token)
             ->getJson('/api/me')
             ->assertStatus(200);

        $this->user->refresh();
        $this->assertNull($this->user->remember_token);
    }

    public function test_successful_login_with_remember_me()
    {
        $loginData = [
            'email' => 'john@example.com',
            'password' => 'password123',
            'remember_me' => true
        ];

        $response = $this->postJson($this->baseApiUrl, $loginData);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Login berhasil',
                    'authorization' => [
                        'remember_me' => true
                    ]
                ]);

        $this->user->refresh();
        $this->assertNotNull($this->user->remember_token);

        $expiresIn = $response->json('authorization.expires_in');
        $refreshTtl = config('jwt.refresh_ttl', 10080);
        $this->assertEquals($refreshTtl * 60, $expiresIn);
    }

    public function test_admin_user_login()
    {
        $loginData = [
            'email' => 'admin@example.com',
            'password' => 'admin123'
        ];

        $response = $this->postJson($this->baseApiUrl, $loginData);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Login berhasil',
                    'user' => [
                        'id' => $this->adminUser->id,
                        'name' => 'Admin User',
                        'email' => 'admin@example.com',
                        'is_admin' => true
                    ]
                ]);

        $roles = $response->json('user.roles');
        $this->assertContains('admin', $roles);
    }

    public function test_login_fails_with_invalid_email()
    {
        $loginData = [
            'email' => 'nonexistent@example.com',
            'password' => 'password123'
        ];

        $response = $this->postJson($this->baseApiUrl, $loginData);

        $response->assertStatus(401)
                ->assertJson([
                    'success' => false,
                    'message' => 'Kredensial tidak valid'
                ]);
    }

    public function test_login_fails_with_invalid_password()
    {
        $loginData = [
            'email' => 'john@example.com',
            'password' => 'wrongpassword'
        ];

        $response = $this->postJson($this->baseApiUrl, $loginData);

        $response->assertStatus(401)
                ->assertJson([
                    'success' => false,
                    'message' => 'Kredensial tidak valid'
                ]);
    }

    public function test_login_fails_without_email()
    {
        $loginData = [
            'password' => 'password123'
        ];

        $response = $this->postJson($this->baseApiUrl, $loginData);

        $response->assertStatus(422)
                ->assertJsonStructure([
                    'success',
                    'message',
                    'errors' => [
                        'email'
                    ]
                ])
                ->assertJson([
                    'success' => false,
                    'message' => 'Validasi error'
                ]);
    }

    public function test_login_fails_without_password()
    {
        $loginData = [
            'email' => 'john@example.com'
        ];

        $response = $this->postJson($this->baseApiUrl, $loginData);

        $response->assertStatus(422)
                ->assertJsonStructure([
                    'success',
                    'message',
                    'errors' => [
                        'password'
                    ]
                ])
                ->assertJson([
                    'success' => false,
                    'message' => 'Validasi error'
                ]);
    }

    public function test_login_fails_with_invalid_email_format()
    {
        $loginData = [
            'email' => 'invalid-email-format',
            'password' => 'password123'
        ];

        $response = $this->postJson($this->baseApiUrl, $loginData);

        $response->assertStatus(422)
                ->assertJsonStructure([
                    'success',
                    'message',
                    'errors' => [
                        'email'
                    ]
                ])
                ->assertJson([
                    'success' => false,
                    'message' => 'Validasi error'
                ]);
    }

    public function test_login_fails_with_multiple_validation_errors()
    {
        $loginData = [
            'email' => 'invalid-email',
        ];

        $response = $this->postJson($this->baseApiUrl, $loginData);

        $response->assertStatus(422)
                ->assertJsonStructure([
                    'success',
                    'message',
                    'errors' => [
                        'email',
                        'password'
                    ]
                ])
                ->assertJson([
                    'success' => false,
                    'message' => 'Validasi error'
                ]);
    }

    public function test_login_with_remember_me_false()
    {
        $loginData = [
            'email' => 'john@example.com',
            'password' => 'password123',
            'remember_me' => false
        ];

        $response = $this->postJson($this->baseApiUrl, $loginData);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'authorization' => [
                        'remember_me' => false
                    ]
                ]);

        $this->user->refresh();
        $this->assertNull($this->user->remember_token);
    }

    public function test_login_with_remember_me_string_true()
    {
        $loginData = [
            'email' => 'john@example.com',
            'password' => 'password123',
            'remember_me' => 'true'
        ];

        $response = $this->postJson($this->baseApiUrl, $loginData);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'success',
                'message',
                'errors' => [
                    'remember_me'
                ]
            ])
            ->assertJson([
                'success' => false,
                'message' => 'Validasi error'
            ]);
    }

    public function test_login_handles_jwt_exception()
    {
        JWTAuth::shouldReceive('attempt')
               ->once()
               ->andThrow(new \Tymon\JWTAuth\Exceptions\JWTException('Could not create token'));

        $loginData = [
            'email' => 'john@example.com',
            'password' => 'password123'
        ];

        $response = $this->postJson($this->baseApiUrl, $loginData);

        $response->assertStatus(500)
                ->assertJsonStructure([
                    'success',
                    'message',
                    'error'
                ])
                ->assertJson([
                    'success' => false,
                    'message' => 'Tidak bisa membuat token'
                ]);
    }

    public function test_login_handles_general_exception()
    {
        JWTAuth::shouldReceive('attempt')
               ->once()
               ->andThrow(new \Exception('General error'));

        $loginData = [
            'email' => 'john@example.com',
            'password' => 'password123'
        ];

        $response = $this->postJson($this->baseApiUrl, $loginData);

        $response->assertStatus(500)
                ->assertJsonStructure([
                    'success',
                    'message',
                    'error'
                ])
                ->assertJson([
                    'success' => false,
                    'message' => 'Login gagal'
                ]);
    }

    public function test_login_token_expiration_time()
    {
        $loginData = [
            'email' => 'john@example.com',
            'password' => 'password123'
        ];

        $response = $this->postJson($this->baseApiUrl, $loginData);

        $response->assertStatus(200);

        $expiresIn = $response->json('authorization.expires_in');
        $defaultTtl = config('jwt.ttl', 60);
        
        $this->assertEquals($defaultTtl * 60, $expiresIn);
    }

    public function test_login_response_structure_completeness()
    {
        $loginData = [
            'email' => 'john@example.com',
            'password' => 'password123'
        ];

        $response = $this->postJson($this->baseApiUrl, $loginData);

        $response->assertStatus(200);

        $responseData = $response->json();

        $this->assertArrayHasKey('success', $responseData);
        $this->assertArrayHasKey('message', $responseData);
        $this->assertArrayHasKey('user', $responseData);
        $this->assertArrayHasKey('authorization', $responseData);

        $user = $responseData['user'];
        $this->assertArrayHasKey('id', $user);
        $this->assertArrayHasKey('name', $user);
        $this->assertArrayHasKey('email', $user);
        $this->assertArrayHasKey('roles', $user);
        $this->assertArrayHasKey('is_admin', $user);

        $auth = $responseData['authorization'];
        $this->assertArrayHasKey('token', $auth);
        $this->assertArrayHasKey('type', $auth);
        $this->assertArrayHasKey('expires_in', $auth);
        $this->assertArrayHasKey('remember_me', $auth);

        $this->assertIsInt($user['id']);
        $this->assertIsString($user['name']);
        $this->assertIsString($user['email']);
        $this->assertIsArray($user['roles']);
        $this->assertIsBool($user['is_admin']);
        $this->assertIsString($auth['token']);
        $this->assertIsString($auth['type']);
        $this->assertIsInt($auth['expires_in']);
        $this->assertIsBool($auth['remember_me']);
    }

    public function test_login_with_case_sensitive_email()
    {
        $loginData = [
            'email' => 'JOHN@EXAMPLE.COM',
            'password' => 'password123'
        ];

        $response = $this->postJson($this->baseApiUrl, $loginData);

        $response->assertStatus(200)
             ->assertJson([
                 'success' => true,
                 'message' => 'Login berhasil',
                 'user' => [
                     'email' => 'john@example.com'
                 ]
             ]);
    }

    public function test_login_user_roles_in_response()
    {
        $loginData = [
            'email' => 'john@example.com',
            'password' => 'password123'
        ];

        $response = $this->postJson($this->baseApiUrl, $loginData);

        $response->assertStatus(200);

        $roles = $response->json('user.roles');
        $this->assertIsArray($roles);
        $this->assertContains('user', $roles);
        $this->assertCount(1, $roles);
    }

    public function test_multiple_role_user_login()
    {
        $adminRole = Role::where('name', 'admin')->first();
        $this->user->roles()->attach($adminRole);

        $loginData = [
            'email' => 'john@example.com',
            'password' => 'password123'
        ];

        $response = $this->postJson($this->baseApiUrl, $loginData);

        $response->assertStatus(200);

        $roles = $response->json('user.roles');
        $this->assertIsArray($roles);
        $this->assertContains('user', $roles);
        $this->assertContains('admin', $roles);
        $this->assertCount(2, $roles);
        
        $this->assertTrue($response->json('user.is_admin'));
    }

    public function test_login_preserves_user_data_integrity()
    {
        $loginData = [
            'email' => 'john@example.com',
            'password' => 'password123'
        ];

        $response = $this->postJson($this->baseApiUrl, $loginData);

        $response->assertStatus(200);

        $responseUser = $response->json('user');
        
        $this->assertEquals($this->user->id, $responseUser['id']);
        $this->assertEquals($this->user->name, $responseUser['name']);
        $this->assertEquals($this->user->email, $responseUser['email']);
        
        $this->assertArrayNotHasKey('password', $responseUser);
    }

}