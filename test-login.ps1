# 登入系統測試腳本 (Windows PowerShell)
# 使用方式: .\test-login.ps1

Write-Host "🔍 開始測試登入系統..." -ForegroundColor Blue
Write-Host ""

# ==========================================
# Step 1: 測試基本連線
# ==========================================
Write-Host "Step 1/5: 測試 Laravel 基本連線..." -ForegroundColor Cyan

try {
    $healthResponse = Invoke-WebRequest -Uri "http://127.0.0.1:8000/api/public/health" -Method GET -UseBasicParsing
    Write-Host "✓ Laravel 正常運行" -ForegroundColor Green
    Write-Host "回應: $($healthResponse.Content)" -ForegroundColor Gray
}
catch {
    Write-Host "✗ 無法連接到 Laravel" -ForegroundColor Red
    Write-Host "錯誤: $($_.Exception.Message)" -ForegroundColor Yellow
    Write-Host "請確認:" -ForegroundColor Yellow
    Write-Host "  1. XAMPP 的 Apache 已啟動" -ForegroundColor Yellow
    Write-Host "  2. Laravel 在 http://127.0.0.1:8000 運行" -ForegroundColor Yellow
    exit 1
}

Write-Host ""

# ==========================================
# Step 2: 檢查認證路由
# ==========================================
Write-Host "Step 2/5: 檢查認證路由..." -ForegroundColor Cyan

# 執行 artisan 指令
$routeList = php artisan route:list --name=auth --json 2>$null
if ($LASTEXITCODE -eq 0) {
    Write-Host "✓ 認證路由已註冊" -ForegroundColor Green
} else {
    Write-Host "! 無法列出路由" -ForegroundColor Yellow
}

Write-Host ""

# ==========================================
# Step 3: 建立測試帳號
# ==========================================
Write-Host "Step 3/5: 建立/檢查測試帳號..." -ForegroundColor Cyan

$createUserCommand = @"
`$email = 'demo@stock.com';
`$user = App\Models\User::where('email', `$email)->first();
if (!`$user) {
    `$user = App\Models\User::create([
        'name' => 'Demo User',
        'email' => `$email,
        'password' => Hash::make('demo1234'),
        'email_verified_at' => now(),
    ]);
    echo '✓ 測試帳號建立成功';
} else {
    `$user->update(['password' => Hash::make('demo1234')]);
    echo '✓ 測試帳號已存在,密碼已更新';
}
"@

php artisan tinker --execute=$createUserCommand 2>$null
Write-Host ""

# ==========================================
# Step 4: 測試登入 API
# ==========================================
Write-Host "Step 4/5: 測試登入 API..." -ForegroundColor Cyan

$loginData = @{
    email = "demo@stock.com"
    password = "demo1234"
} | ConvertTo-Json

try {
    $loginResponse = Invoke-WebRequest `
        -Uri "http://127.0.0.1:8000/api/auth/login" `
        -Method POST `
        -ContentType "application/json" `
        -Body $loginData `
        -UseBasicParsing

    $responseData = $loginResponse.Content | ConvertFrom-Json

    if ($responseData.success -eq $true) {
        Write-Host "✓ 登入 API 測試成功!" -ForegroundColor Green
        Write-Host ""
        Write-Host "使用者資訊:" -ForegroundColor Cyan
        Write-Host "  姓名: $($responseData.data.user.name)" -ForegroundColor Gray
        Write-Host "  Email: $($responseData.data.user.email)" -ForegroundColor Gray
        Write-Host "  Token: $($responseData.data.token.Substring(0, 20))..." -ForegroundColor Gray
    } else {
        Write-Host "✗ 登入失敗" -ForegroundColor Red
        Write-Host "回應: $($loginResponse.Content)" -ForegroundColor Yellow
    }
}
catch {
    Write-Host "✗ 登入 API 測試失敗" -ForegroundColor Red
    Write-Host "錯誤: $($_.Exception.Message)" -ForegroundColor Yellow
    
    if ($_.Exception.Response) {
        $statusCode = $_.Exception.Response.StatusCode.value__
        Write-Host "狀態碼: $statusCode" -ForegroundColor Yellow
        
        # 讀取錯誤回應
        $reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
        $responseBody = $reader.ReadToEnd()
        Write-Host "回應內容: $responseBody" -ForegroundColor Yellow
    }
}

Write-Host ""

# ==========================================
# Step 5: 檢查 Sanctum 安裝
# ==========================================
Write-Host "Step 5/5: 檢查 Sanctum..." -ForegroundColor Cyan

$checkSanctum = php artisan tinker --execute="echo DB::getSchemaBuilder()->hasTable('personal_access_tokens') ? '✓ Sanctum 已安裝' : '✗ Sanctum 未安裝';" 2>$null
Write-Host $checkSanctum

Write-Host ""

# ==========================================
# 總結
# ==========================================
Write-Host "================================" -ForegroundColor Green
Write-Host "測試完成！" -ForegroundColor Green
Write-Host "================================" -ForegroundColor Green
Write-Host ""
Write-Host "🔑 測試帳號資訊:" -ForegroundColor Cyan
Write-Host "   Email: demo@stock.com"
Write-Host "   密碼: demo1234"
Write-Host ""
Write-Host "📝 下一步:" -ForegroundColor Cyan
Write-Host "   1. 開啟瀏覽器訪問: http://127.0.0.1:8000/login"
Write-Host "   2. 使用上面的帳號密碼登入"
Write-Host "   3. 登入成功後應該會導向 dashboard"
Write-Host ""

# 詢問是否開啟瀏覽器
$openBrowser = Read-Host "是否要開啟瀏覽器測試登入? (y/n)"
if ($openBrowser -eq "y" -or $openBrowser -eq "Y") {
    Start-Process "http://127.0.0.1:8000/login"
}