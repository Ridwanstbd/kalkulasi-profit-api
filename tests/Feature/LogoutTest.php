<?php

namespace Tests\Feature;

use App\Models\User; 
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException; 
use Tymon\JWTAuth\Token;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $tokenString;

    protected string $baseApiUrl = '/api/logout';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->tokenString = JWTAuth::fromUser($this->user);

        $this->user->remember_token = 'some-remember-token-example';
        $this->user->save();
    }

    public function test_successful_logout()
    {
        JWTAuth::shouldReceive('parseToken->authenticate')
            ->once()
            ->andReturn($this->user);

        JWTAuth::shouldReceive('user')
            ->once()
            ->andReturn($this->user); 
        $mockTokenObject = new Token($this->tokenString);
        JWTAuth::shouldReceive('getToken')
            ->once()
            ->andReturn($mockTokenObject);
        JWTAuth::shouldReceive('invalidate')
            ->once() 
            ->with($mockTokenObject)
            ->andReturnNull(); 

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->postJson($this->baseApiUrl);

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'message' => 'Berhasil Keluar'
                 ]);

        $this->user->refresh();
        $this->assertNull($this->user->remember_token);

        JWTAuth::shouldReceive('parseToken->authenticate')
               ->once() 
               ->andThrow(new TokenInvalidException('Token has been invalidated'));

        $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
             ->getJson('/api/me')
             ->assertStatus(401); 
    }

    public function test_logout_fails_without_token()
    {

        $response = $this->postJson($this->baseApiUrl);

        $response->assertStatus(401)
                 ->assertJson([
                     'success' => false,
                     'message' => 'Authorization header not found'
                 ]);
    }

    public function test_logout_handles_jwt_exception_on_invalidate()
    {
        JWTAuth::shouldReceive('parseToken->authenticate')->once()->andReturn($this->user);

        JWTAuth::shouldReceive('user')->once()->andReturn($this->user);

        $mockTokenObject = new Token($this->tokenString);
        JWTAuth::shouldReceive('getToken')->once()->andReturn($mockTokenObject);

        JWTAuth::shouldReceive('invalidate')
               ->once()
               ->with($mockTokenObject) 
               ->andThrow(new JWTException('Simulated JWTException during invalidate'));

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenString)
                         ->postJson($this->baseApiUrl);

        $response->assertStatus(500)
                 ->assertJson([
                     'success' => false,
                     'message' => 'Maaf pengguna gagal keluar'
                 ]);
    }

}
