# Stock_analysis API 測試腳本

Write-Host "====================================" -ForegroundColor Cyan
Write-Host "Stock_analysis API Testing" -ForegroundColor Cyan
Write-Host "====================================" -ForegroundColor Cyan
Write-Host ""

# Test 1: Basic Connection
Write-Host "[1/6] Testing Laravel connection..." -ForegroundColor Yellow
try {
    $response = Invoke-RestMethod -Uri "http://localhost:8000" -Method Get -TimeoutSec 5
    Write-Host "      ✓ Laravel is running" -ForegroundColor Green
} catch {
    Write-Host "      ✗ Laravel connection failed" -ForegroundColor Red
    Write-Host "      Error: $($_.Exception.Message)" -ForegroundColor Red
}
Write-Host ""

# Test 2: Stocks API
Write-Host "[2/6] Testing Stocks API..." -ForegroundColor Yellow
try {
    $response = Invoke-RestMethod -Uri "http://localhost:8000/api/stocks" -Method Get -TimeoutSec 5
    Write-Host "      ✓ Stocks API working" -ForegroundColor Green
    Write-Host "      Found $($response.data.Count) stocks" -ForegroundColor Gray
} catch {
    Write-Host "      ✗ Stocks API failed" -ForegroundColor Red
    Write-Host "      Error: $($_.Exception.Message)" -ForegroundColor Red
}
Write-Host ""

# Test 3: Database Check
Write-Host "[3/6] Checking database tables..." -ForegroundColor Yellow
try {
    $tables = docker-compose exec -T db mysql -ularavel -psecret -e "USE stock_analysis; SHOW TABLES;" 2>&1
    if ($LASTEXITCODE -eq 0) {
        Write-Host "      ✓ Database accessible" -ForegroundColor Green
    } else {
        Write-Host "      ✗ Database check failed" -ForegroundColor Red
    }
} catch {
    Write-Host "      ✗ Database check failed" -ForegroundColor Red
}
Write-Host ""

# Test 4: Check Seeder Data
Write-Host "[4/6] Checking seeded data..." -ForegroundColor Yellow
try {
    $count = docker-compose exec -T db mysql -ularavel -psecret -e "USE stock_analysis; SELECT COUNT(*) as count FROM stocks;" 2>&1
    if ($count -match "\d+") {
        Write-Host "      ✓ Seeded data exists" -ForegroundColor Green
    }
} catch {
    Write-Host "      ✗ Could not verify seeded data" -ForegroundColor Yellow
}
Write-Host ""

# Test 5: phpMyAdmin
Write-Host "[5/6] Testing phpMyAdmin..." -ForegroundColor Yellow
try {
    $response = Invoke-WebRequest -Uri "http://localhost:8080" -Method Get -TimeoutSec 5 -UseBasicParsing
    Write-Host "      ✓ phpMyAdmin accessible" -ForegroundColor Green
} catch {
    Write-Host "      ✗ phpMyAdmin not accessible" -ForegroundColor Red
}
Write-Host ""

# Test 6: Migration Status
Write-Host "[6/6] Checking migration status..." -ForegroundColor Yellow
docker-compose exec app php artisan migrate:status
Write-Host ""

# Summary
Write-Host "====================================" -ForegroundColor Cyan
Write-Host "Test Summary" -ForegroundColor Cyan
Write-Host "====================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Service URLs:" -ForegroundColor Cyan
Write-Host "  Laravel:    http://localhost:8000" -ForegroundColor White
Write-Host "  phpMyAdmin: http://localhost:8080" -ForegroundColor White
Write-Host ""
Write-Host "Database Info:" -ForegroundColor Cyan
Write-Host "  Host:     localhost:3307" -ForegroundColor White
Write-Host "  Database: stock_analysis" -ForegroundColor White
Write-Host "  User:     laravel" -ForegroundColor White
Write-Host "  Password: secret" -ForegroundColor White
Write-Host ""
Write-Host "Next Steps:" -ForegroundColor Cyan
Write-Host "  1. Visit http://localhost:8080 to view database" -ForegroundColor Gray
Write-Host "  2. Test API with Postman or Insomnia" -ForegroundColor Gray
Write-Host "  3. Start developing your features!" -ForegroundColor Gray
Write-Host ""