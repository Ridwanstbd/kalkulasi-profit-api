<?php

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;

class JWTAuthController extends Controller
{
    public function register(Request $request) {
        $validator = Validator::make($request->all(),[
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6|confirmed',
        ]);
        
        if ($validator->fails()){
            return response()->json(['errors' => $validator->errors()], 422);
        }
        try {
            DB::beginTransaction();

            $user = User::create([
                'name' => $request->get('name'),
                'email' => $request->get('email'),
                'password' => Hash::make($request->password),
            ]);
            $userRole = Role::where('name','user')->first();
            if (!$userRole) {
                throw new \Exception('Registration failed: Role "user" not found.');
            }
            $user->roles()->attach($userRole);

            DB::commit();

            $token = JWTAuth::fromUser($user);
            return response()->json([
                'success' => true,
                'message' => 'User registered successfully',
                'data' => [
                    'user' => $user,
                    'token' => $token
                ]
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Registration failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function login(Request $request) {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
            'remember_me' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi error',
                'errors' => $validator->errors()
            ], 422);
        }
        try {
            $ttl = $request->remember_me ? config('jwt.refresh_ttl') : config('jwt.ttl');
            $credentials = [
                'email' => strtolower($request->email),
                'password' => $request->password
            ];
            $remember = $request->input('remember_me', false);
                      
            if (!$token = JWTAuth::attempt($credentials)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kredensial tidak valid'
                ], 401);
            }
            $user = JWTAuth::user();
            if ($remember) {
                $user->remember_token = hash('sha256', $token);
                $user->save();
            }
            
           
            return response()->json([
                'success' => true,
                'message' => 'Login berhasil',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->roles->pluck('name'),
                    'is_admin' => $user->isAdmin()
                ],
                'authorization' => [
                    'token' => $token,
                    'type' => 'Bearer',
                    'expires_in' => $ttl * 60,
                    'remember_me' => (bool) $remember
                ]
            ]);
        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak bisa membuat token',
                'error' => $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Login gagal',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function logout(Request $request) {
        try {
            $user = JWTAuth::user();
            if ($user) {
                $user->remember_token = null;
                $user->save();
            }
            JWTAuth::invalidate(JWTAuth::getToken());
            return response()->json([
                'success' => true,
                'message' => 'Berhasil Keluar'
            ]);
        } catch (JWTException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Maaf pengguna gagal keluar'
            ], 500);
        }
    }
    public function refresh(){
        try {
            $token = JWTAuth::refresh(JWTAuth::getToken());
            $user = JWTAuth::user();
            $ttl = config('jwt.ttl');

            if($user && $user->remember_token){
                $user->remember_token = hash('sha256', $token);
                $user->save();
            }

            return response()->json([
                'success' => true,
                'message' => 'Token sukses diperbarui',
                'authorization' => [
                    'token' => $token,
                    'type' => 'Bearer',
                    'expires_in' => $ttl * 60
                ]
            ]);
        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token not valid',
                'error' => $e->getMessage()
            ], 401);
        }
    }
    public function forgotPassword(Request $request){
        $validator = Validator::make($request->all(),[
            'email' => 'required|email|exists:users'
        ]);
        
        if($validator->fails()){
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ],400);
        }
        
        try {
            $status = Password::sendResetLink(
                $request->only('email')
            );
            
            \Log::info('Password reset attempt', [
                'email' => $request->email,
                'status' => $status,
                'status_string' => __($status)
            ]);
            
            if($status === Password::RESET_LINK_SENT){
                return response()->json([
                    'success' => true,
                    'message' => 'Link perubahan password sudah kami kirimkan ke email kamu'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Kami gagal mengirim link reset',
                    'debug_info' => __($status) 
                ],500);
            }
        } catch (\Exception $e){
            \Log::error('Password reset exception', [
                'email' => $request->email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses permintaan perubahan password',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function resetPassword(Request $request){
        $validator = Validator::make($request->all(), [
            'token' => 'required',
            'email' => 'required|email|exists:users',
            'password' => 'required|string|min:6|confirmed',
            'password_confirmation' => 'required|string|min:6'
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi Error',
                'errors' => $validator->errors()
            ], 400);
        }
        try {
            $status = Password::reset(
                $request->only('email', 'password', 'password_confirmation', 'token'),
                function ($user, $password) {
                    $user->forceFill([
                        'password' => Hash::make($password)
                    ]);
    
                    $user->save();
    
                    event(new PasswordReset($user));
                }
            );
            if ($status === Password::PASSWORD_RESET) {
                $credentials = $request->only('email', 'password');
                $token = JWTAuth::attempt($credentials);
                
                $user = JWTAuth::user();
                $ttl = config('jwt.ttl');
                
                return response()->json([
                    'success' => true,
                    'message' => 'Kata sandi berhasil direset',
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'roles' => $user->roles->pluck('name'),
                        'is_admin' => $user->isAdmin()
                    ],
                    'authorization' => [
                        'token' => $token,
                        'type' => 'Bearer',
                        'expires_in' => $ttl * 60
                    ]
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Gagal untuk reset kata sandi'
                ], 400);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal untuk reset kata sandi',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function me(){
        try {
            $user = JWTAuth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pengguna tidak ditemukan'
                ], 404);
            }
            
            return response()->json([
                'success' => true,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->roles->pluck('name'),
                    'is_admin' => $user->isAdmin()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error mengambil detail pengguna',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
