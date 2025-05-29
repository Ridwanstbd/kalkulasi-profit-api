<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected string $baseApiUrl = '/api'; 

    protected function setUp(): void
    {
        parent::setUp();

        if (!Role::where('name', 'user')->exists()) {
            Role::create(['name' => 'user', 'display_name' => 'User Biasa', 'description' => 'Role untuk pengguna biasa']);
        }


        $this->user = User::factory()->create([
            'email' => 'testuser@example.com',
            'password' => Hash::make('password123'),
        ]);
        $userRole = Role::where('name', 'user')->first();
        if ($userRole) {
            $this->user->roles()->attach($userRole);
        }
    }


    public function test_can_refresh_token_successfully()
    {
        $credentials = ['email' => $this->user->email, 'password' => 'password123'];
        $loginResponse = $this->postJson("{$this->baseApiUrl}/login", $credentials);
        $oldToken = $loginResponse->json('authorization.token');

        $response = $this->withHeader('Authorization', 'Bearer ' . $oldToken)
                         ->postJson("{$this->baseApiUrl}/refresh");

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'success',
                     'message',
                     'authorization' => ['token', 'type', 'expires_in']
                 ])
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('message', 'Token sukses diperbarui');

        $newToken = $response->json('authorization.token');
        $this->assertNotNull($newToken);
        $this->assertNotEquals($oldToken, $newToken);

        $this->withHeader('Authorization', 'Bearer ' . $oldToken)
             ->getJson("{$this->baseApiUrl}/me") 
             ->assertStatus(401);
    }

    public function test_refresh_token_fails_with_invalid_token()
    {
        $response = $this->withHeader('Authorization', 'Bearer invalidtoken123')
                         ->postJson("{$this->baseApiUrl}/refresh");

        $response->assertStatus(401)
                 ->assertJsonPath('success', false)
                 ->assertJsonPath('message', 'Token not valid');
    }

    public function test_refresh_token_updates_user_remember_token_if_set()
    {
        $credentials = ['email' => $this->user->email, 'password' => 'password123', 'remember_me' => true];
        $loginResponse = $this->postJson("{$this->baseApiUrl}/login", $credentials);
        $initialToken = $loginResponse->json('authorization.token');
        
        $this->user->refresh(); 
        $initialRememberToken = $this->user->remember_token;
        $this->assertNotNull($initialRememberToken);

        $refreshResponse = $this->withHeader('Authorization', 'Bearer ' . $initialToken)
                                ->postJson("{$this->baseApiUrl}/refresh");
        
        $refreshResponse->assertStatus(200);
        $newToken = $refreshResponse->json('authorization.token');

        $this->user->refresh();
        $newRememberToken = $this->user->remember_token;

        $this->assertNotNull($newRememberToken);
        $this->assertNotEquals($initialRememberToken, $newRememberToken);
        $this->assertEquals(hash('sha256', $newToken), $newRememberToken);
    }



    public function test_can_request_password_reset_link_successfully()
    {
        $response = $this->postJson("{$this->baseApiUrl}/forgot-password", [
            'email' => $this->user->email,
        ]);

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'message' => 'Link perubahan password sudah kami kirimkan ke email kamu'
                 ]);

        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => $this->user->email,
        ]);
    }

    public function test_forgot_password_fails_if_email_does_not_exist()
    {
        $response = $this->postJson("{$this->baseApiUrl}/forgot-password", [
            'email' => 'nonexistentuser@example.com',
        ]);

        $response->assertStatus(400) 
                 ->assertJsonPath('success', false)
                 ->assertJsonValidationErrors(['email']);
    }

    public function test_forgot_password_fails_with_invalid_email_format()
    {
        $response = $this->postJson("{$this->baseApiUrl}/forgot-password", [
            'email' => 'invalid-email',
        ]);

        $response->assertStatus(400)
                 ->assertJsonPath('success', false)
                 ->assertJsonValidationErrors(['email']);
    }


    public function test_can_reset_password_successfully_with_valid_token()
    {
        $token = Password::broker()->createToken($this->user);

        $newPassword = 'newSecurePassword123';
        $response = $this->postJson("{$this->baseApiUrl}/reset-password", [
            'token' => $token,
            'email' => $this->user->email,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ]);
        
        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('message', 'Kata sandi berhasil direset')
                 ->assertJsonStructure([
                     'success',
                     'message',
                     'user' => ['id', 'name', 'email'],
                     'authorization' => ['token', 'type', 'expires_in']
                 ]);

        $this->user->refresh();
        $this->assertTrue(Hash::check($newPassword, $this->user->password));

        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => $this->user->email,
        ]);
    }

    public function test_reset_password_fails_with_invalid_token()
    {
        $newPassword = 'newSecurePassword123';
        $response = $this->postJson("{$this->baseApiUrl}/reset-password", [
            'token' => 'invalid-reset-token',
            'email' => $this->user->email,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ]);

        $response->assertStatus(400) 
                 ->assertJsonPath('success', false)
                 ->assertJsonPath('message', 'Gagal untuk reset kata sandi');
    }

    public function test_reset_password_fails_if_email_does_not_exist()
    {
        $token = Password::broker()->createToken($this->user);
        $newPassword = 'newSecurePassword123';

        $response = $this->postJson("{$this->baseApiUrl}/reset-password", [
            'token' => $token,
            'email' => 'nonexistent@example.com', 
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ]);

        $response->assertStatus(400)
                 ->assertJsonPath('success', false)
                 ->assertJsonValidationErrors(['email']); 
    }

    public function test_reset_password_fails_if_passwords_do_not_match()
    {
        $token = Password::broker()->createToken($this->user);
        $response = $this->postJson("{$this->baseApiUrl}/reset-password", [
            'token' => $token,
            'email' => $this->user->email,
            'password' => 'newPassword123',
            'password_confirmation' => 'differentPassword456',
        ]);

        $response->assertStatus(400)
                 ->assertJsonPath('success', false)
                 ->assertJsonValidationErrors(['password']); 
    }

    public function test_reset_password_fails_if_password_is_too_short()
    {
        $token = Password::broker()->createToken($this->user);
        $response = $this->postJson("{$this->baseApiUrl}/reset-password", [
            'token' => $token,
            'email' => $this->user->email,
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertStatus(400)
                 ->assertJsonPath('success', false)
                 ->assertJsonValidationErrors(['password']); 
    }
}
