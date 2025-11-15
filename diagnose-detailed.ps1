# ========================================
# Detailed Laravel Detection
# ========================================

Write-Host ""
Write-Host "=====================================" -ForegroundColor Cyan
Write-Host "Laravel Server Diagnostic Tool" -ForegroundColor Cyan
Write-Host "=====================================" -ForegroundColor Cyan
Write-Host ""

# Check 1: Common ports
Write-Host "Test 1: Checking common Laravel ports..." -ForegroundColor Yellow
Write-Host ""

$ports = @(8000, 80, 8080, 3000, 5000, 8888, 9000)
$foundUrl = $null

foreach ($port in $ports) {
    $urls = @(
        "http://localhost:$port",
        "http://127.0.0.1:$port"
    )

    foreach ($url in $urls) {
        Write-Host "  Testing: $url" -ForegroundColor Gray -NoNewline
        try {
            $testUrl = "$url/api/options?per_page=1"
            $response = Invoke-WebRequest -Uri $testUrl -Method GET -TimeoutSec 1 -UseBasicParsing -ErrorAction Stop

            if ($response.StatusCode -eq 200) {
                Write-Host " [OK]" -ForegroundColor Green
                $foundUrl = $url
                break
            }
        } catch {
            Write-Host " [X]" -ForegroundColor Red
        }
    }

    if ($foundUrl) { break }
}

Write-Host ""

if ($foundUrl) {
    Write-Host "=====================================" -ForegroundColor Green
    Write-Host "[SUCCESS] Found Laravel!" -ForegroundColor Green
    Write-Host "=====================================" -ForegroundColor Green
    Write-Host ""
    Write-Host "URL: $foundUrl" -ForegroundColor White
    Write-Host ""

    # Save to file
    $foundUrl | Out-File -FilePath "laravel-url.txt" -Encoding UTF8 -Force
    Write-Host "Saved to: laravel-url.txt" -ForegroundColor Gray
    Write-Host ""

    # Test API endpoint
    Write-Host "Testing API endpoint..." -ForegroundColor Yellow
    try {
        $response = Invoke-RestMethod -Uri "$foundUrl/api/options?per_page=1"
        if ($response.success) {
            Write-Host "[OK] API is working" -ForegroundColor Green
            Write-Host "Total options: $($response.data.total)" -ForegroundColor Gray
        }
    } catch {
        Write-Host "[ERROR] API test failed" -ForegroundColor Red
    }

} else {
    Write-Host "=====================================" -ForegroundColor Red
    Write-Host "[ERROR] Laravel Not Found" -ForegroundColor Red
    Write-Host "=====================================" -ForegroundColor Red
    Write-Host ""

    # Additional diagnostics
    Write-Host "Let's check if Laravel is actually running..." -ForegroundColor Yellow
    Write-Host ""

    # Check 2: Process check
    Write-Host "Test 2: Checking PHP processes..." -ForegroundColor Yellow
    try {
        $phpProcesses = Get-Process -Name php -ErrorAction SilentlyContinue
        if ($phpProcesses) {
            Write-Host "[OK] Found PHP processes running:" -ForegroundColor Green
            foreach ($proc in $phpProcesses) {
                Write-Host "  PID: $($proc.Id)" -ForegroundColor Gray
            }
            Write-Host ""
            Write-Host "PHP is running but cannot connect to API" -ForegroundColor Yellow
        } else {
            Write-Host "[X] No PHP processes found" -ForegroundColor Red
            Write-Host ""
        }
    } catch {
        Write-Host "[X] Cannot check processes" -ForegroundColor Red
    }

    Write-Host ""

    # Check 3: Manual URL test
    Write-Host "Test 3: Manual URL test" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Please enter your Laravel URL manually" -ForegroundColor White
    Write-Host "(Example: http://localhost:8000 or http://stock-analysis.test)" -ForegroundColor Gray
    Write-Host ""
    $manualUrl = Read-Host "Laravel URL"

    if ($manualUrl) {
        Write-Host ""
        Write-Host "Testing: $manualUrl" -ForegroundColor Gray
        try {
            $testUrl = "$manualUrl/api/options?per_page=1"
            $response = Invoke-RestMethod -Uri $testUrl -Method GET -TimeoutSec 5

            if ($response.success) {
                Write-Host "[OK] Connection successful!" -ForegroundColor Green
                Write-Host ""
                $manualUrl | Out-File -FilePath "laravel-url.txt" -Encoding UTF8 -Force
                Write-Host "URL saved to: laravel-url.txt" -ForegroundColor Gray
                $foundUrl = $manualUrl
            }
        } catch {
            Write-Host "[ERROR] Connection failed" -ForegroundColor Red
            Write-Host "Error: $($_.Exception.Message)" -ForegroundColor Yellow
        }
    }
}

Write-Host ""

if (-not $foundUrl) {
    Write-Host "=====================================" -ForegroundColor Yellow
    Write-Host "Troubleshooting Steps:" -ForegroundColor Yellow
    Write-Host "=====================================" -ForegroundColor Yellow
    Write-Host ""

    Write-Host "1. Check if Laravel is running:" -ForegroundColor White
    Write-Host "   a) Open new terminal" -ForegroundColor Gray
    Write-Host "   b) Navigate to project:" -ForegroundColor Gray
    Write-Host "      cd C:\Users\user\Documents\GitHub\stock-analysis" -ForegroundColor Cyan
    Write-Host "   c) Start Laravel:" -ForegroundColor Gray
    Write-Host "      php artisan serve" -ForegroundColor Cyan
    Write-Host "   d) You should see:" -ForegroundColor Gray
    Write-Host "      'Laravel development server started: http://127.0.0.1:8000'" -ForegroundColor Green
    Write-Host ""

    Write-Host "2. If using different server (Apache/Nginx):" -ForegroundColor White
    Write-Host "   - Check your virtual host configuration" -ForegroundColor Gray
    Write-Host "   - Verify the URL in your browser" -ForegroundColor Gray
    Write-Host "   - Make sure API routes are accessible" -ForegroundColor Gray
    Write-Host ""

    Write-Host "3. Check .env file:" -ForegroundColor White
    Write-Host "   APP_URL=http://localhost:8000" -ForegroundColor Gray
    Write-Host ""

    Write-Host "4. Check if API routes exist:" -ForegroundColor White
    Write-Host "   php artisan route:list | findstr api/options" -ForegroundColor Cyan
    Write-Host ""

    Write-Host "5. Test in browser:" -ForegroundColor White
    Write-Host "   Open: http://localhost:8000/api/options" -ForegroundColor Cyan
    Write-Host "   Should see JSON response" -ForegroundColor Gray
    Write-Host ""

    Write-Host "=====================================" -ForegroundColor Yellow
    Write-Host ""
}

Write-Host ""
