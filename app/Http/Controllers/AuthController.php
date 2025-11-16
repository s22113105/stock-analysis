<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * 認證控制器
 * 
 * 處理使用者登入、註冊、登出等功能
 * 使用 Laravel Sanctum 進行 API Token 認證
 */
class AuthController extends Controller
{
    /**
     * 使用者註冊
     * 
     * POST /api/auth/register
     */
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'name.required' => '姓名為必填',
            'email.required' => 'Email 為必填',
            'email.email' => 'Email 格式不正確',
            'email.unique' => '此 Email 已被使用',
            'password.required' => '密碼為必填',
            'password.confirmed' => '密碼確認不一致',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => '註冊成功',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ],
                    'token' => $token,
                    'token_type' => 'Bearer'
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '註冊失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 使用者登入
     * 
     * POST /api/auth/login
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'password' => ['required'],
        ], [
            'email.required' => 'Email 為必填',
            'email.email' => 'Email 格式不正確',
            'password.required' => '密碼為必填',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // 驗證帳號密碼
            if (!Auth::attempt($request->only('email', 'password'))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email 或密碼錯誤'
                ], 401);
            }

            $user = Auth::user();

            // 刪除舊的 token (可選)
            // $user->tokens()->delete();

            // 建立新的 token
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => '登入成功',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ],
                    'token' => $token,
                    'token_type' => 'Bearer'
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '登入失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 使用者登出
     * 
     * POST /api/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            // 刪除當前使用的 token
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'success' => true,
                'message' => '登出成功'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '登出失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得當前使用者資訊
     * 
     * GET /api/auth/user
     */
    public function user(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at,
                    'created_at' => $user->created_at,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '取得使用者資訊失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 更新個人資料
     * 
     * PUT /api/auth/profile
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email,' . $request->user()->id],
        ], [
            'name.required' => '姓名為必填',
            'email.required' => 'Email 為必填',
            'email.email' => 'Email 格式不正確',
            'email.unique' => '此 Email 已被使用',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = $request->user();
            $user->update([
                'name' => $request->name,
                'email' => $request->email,
            ]);

            return response()->json([
                'success' => true,
                'message' => '個人資料更新成功',
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '更新失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 變更密碼
     * 
     * PUT /api/auth/password
     */
    public function changePassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'current_password' => ['required'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'current_password.required' => '目前密碼為必填',
            'password.required' => '新密碼為必填',
            'password.confirmed' => '密碼確認不一致',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = $request->user();

            // 驗證目前密碼
            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => '目前密碼錯誤'
                ], 401);
            }

            // 更新密碼
            $user->update([
                'password' => Hash::make($request->password)
            ]);

            // 刪除所有 token (強制重新登入)
            $user->tokens()->delete();

            return response()->json([
                'success' => true,
                'message' => '密碼變更成功，請重新登入'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '變更密碼失敗: ' . $e->getMessage()
            ], 500);
        }
    }
}