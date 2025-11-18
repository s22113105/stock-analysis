# ==========================================
# 快速測試 - 直接複製貼上執行
# ==========================================

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "預測 API 快速測試" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

$baseUrl = "http://localhost:8000/api"

# 步驟 1: 註冊測試帳號
Write-Host "Step 1: 註冊測試帳號..." -ForegroundColor Yellow
$registerBody = @{
    name = "Test User"
    email = "test@stock.com"
    password = "test1234"
    password_confirmation = "test1234"
} | ConvertTo-Json

try {
    Invoke-RestMethod -Uri "$baseUrl/auth/register" -Method POST -ContentType "application/json" -Body $registerBody -ErrorAction SilentlyContinue
    Write-Host "✓ 註冊成功" -ForegroundColor Green
} catch {
    Write-Host "! 帳號已存在,直接登入" -ForegroundColor Yellow
}
Write-Host ""

# 步驟 2: 登入取得 Token
Write-Host "Step 2: 登入取得 Token..." -ForegroundColor Yellow
$loginBody = @{
    email = "test@stock.com"
    password = "test1234"
} | ConvertTo-Json

try {
    $loginResponse = Invoke-RestMethod -Uri "$baseUrl/auth/login" -Method POST -ContentType "application/json" -Body $loginBody
    $token = $loginResponse.data.token
    Write-Host "✓ 登入成功" -ForegroundColor Green
    Write-Host "Token: $($token.Substring(0, 30))..." -ForegroundColor Gray
} catch {
    Write-Host "✗ 登入失敗: $($_.Exception.Message)" -ForegroundColor Red
    exit
}
Write-Host ""

# 步驟 3: 測試 TXO 預測
Write-Host "Step 3: 測試 TXO 預測..." -ForegroundColor Yellow
$txoBody = @{
    underlying = "TXO"
    model_type = "lstm"
    prediction_days = 1
    parameters = @{
        epochs = 20
        units = 64
        historical_days = 180
    }
} | ConvertTo-Json

$headers = @{
    "Authorization" = "Bearer $token"
    "Content-Type" = "application/json"
}

try {
    $txoResponse = Invoke-RestMethod -Uri "$baseUrl/predictions/run" -Method POST -Headers $headers -Body $txoBody
    Write-Host "✓ TXO 預測成功" -ForegroundColor Green
    Write-Host "結果:" -ForegroundColor Cyan
    $txoResponse | ConvertTo-Json -Depth 10
} catch {
    Write-Host "✗ TXO 預測失敗" -ForegroundColor Red
    if ($_.Exception.Response) {
        $statusCode = $_.Exception.Response.StatusCode.value__
        Write-Host "狀態碼: $statusCode" -ForegroundColor Yellow
        $reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
        $responseBody = $reader.ReadToEnd()
        Write-Host "回應: $responseBody" -ForegroundColor Yellow
    }
}
Write-Host ""

Write-Host "========================================" -ForegroundColor Green
Write-Host "測試完成!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
