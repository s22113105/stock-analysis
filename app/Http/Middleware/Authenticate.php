<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        // 如果是 API 請求或期望 JSON 回應,返回 null(會拋出 401)
        if ($request->expectsJson() || $request->is('api/*')) {
            return null;
        }
        
        // 如果是 web 請求,返回前端登入頁面路徑
        // 由於使用 Vue Router,這裡返回前端路由
        return '/login';
    }
}