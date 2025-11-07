# Stock_analysis - MySQL Configuration Fix Script
# Resolves MYSQL_USER="root" error

Write-Host "==================================" -ForegroundColor Cyan
Write-Host "Stock_analysis MySQL Fix Tool" -ForegroundColor Cyan
Write-Host "==================================" -ForegroundColor Cyan
Write-Host ""

# Step 1: Stop and clean containers
Write-Host "[1/8] Stopping containers..." -ForegroundColor Yellow
docker-compose down -v
if ($LASTEXITCODE -eq 0) {
    Write-Host "      OK - Containers stopped" -ForegroundColor Green
} else {
    Write-Host "      Warning - Some errors occurred" -ForegroundColor Yellow
}
Write-Host ""

# Step 2: Backup existing files
Write-Host "[2/8] Backing up files..." -ForegroundColor Yellow
$timestamp = Get-Date -Format "yyyyMMdd_HHmmss"

if (Test-Path "docker-compose.yml") {
    Copy-Item "docker-compose.yml" "docker-compose.yml.backup_$timestamp" -Force
    Write-Host "      OK - docker-compose.yml backed up" -ForegroundColor Green
}

if (Test-Path ".env") {
    Copy-Item ".env" ".env.backup_$timestamp" -Force
    Write-Host "      OK - .env backed up" -ForegroundColor Green
}
Write-Host ""

# Step 3: Configuration reminder
Write-Host "[3/8] Configuration Check" -ForegroundColor Yellow
Write-Host ""
Write-Host "      Please ensure your .env file has:" -ForegroundColor Cyan
Write-Host "      DB_HOST=db" -ForegroundColor White
Write-Host "      DB_PORT=3306" -ForegroundColor White
Write-Host "      DB_DATABASE=stock_analysis" -ForegroundColor White
Write-Host "      DB_USERNAME=laravel" -ForegroundColor White
Write-Host "      DB_PASSWORD=secret" -ForegroundColor White
Write-Host "      MYSQL_ROOT_PASSWORD=rootpassword" -ForegroundColor White
Write-Host ""
Write-Host "      Press any key to continue..." -ForegroundColor Yellow
$null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")
Write-Host ""

# Step 4: Start containers
Write-Host "[4/8] Starting containers..." -ForegroundColor Yellow
docker-compose up -d --build

if ($LASTEXITCODE -eq 0) {
    Write-Host "      OK - Containers started" -ForegroundColor Green
} else {
    Write-Host "      ERROR - Failed to start containers" -ForegroundColor Red
    Write-Host "      Run: docker-compose logs" -ForegroundColor Yellow
    exit 1
}
Write-Host ""

# Step 5: Wait for MySQL
Write-Host "[5/8] Waiting for MySQL initialization..." -ForegroundColor Yellow
Write-Host "      This may take 30-60 seconds..." -ForegroundColor Gray

for ($i = 30; $i -gt 0; $i--) {
    Write-Progress -Activity "Waiting for MySQL" -Status "$i seconds remaining" -PercentComplete ((30-$i)/30*100)
    Start-Sleep -Seconds 1
}
Write-Progress -Activity "Waiting for MySQL" -Completed
Write-Host "      OK - Wait completed" -ForegroundColor Green
Write-Host ""

# Step 6: Check container status
Write-Host "[6/8] Container Status" -ForegroundColor Yellow
docker-compose ps
Write-Host ""

# Step 7: Check MySQL logs
Write-Host "[7/8] MySQL Logs (last 10 lines)" -ForegroundColor Yellow
docker-compose logs --tail=10 db
Write-Host ""

# Step 8: Test MySQL connection
Write-Host "[8/8] Testing MySQL connection..." -ForegroundColor Yellow

try {
    # Test with docker exec
    $result = docker-compose exec -T db mysqladmin ping -ularavel -psecret 2>&1
    
    if ($result -match "mysqld is alive") {
        Write-Host "      OK - MySQL is running and accessible" -ForegroundColor Green
        
        # Try to show databases
        Write-Host ""
        Write-Host "      Available databases:" -ForegroundColor Cyan
        docker-compose exec -T db mysql -ularavel -psecret -e "SHOW DATABASES;" 2>&1 | Write-Host
    } else {
        Write-Host "      Warning - MySQL may not be fully ready" -ForegroundColor Yellow
        Write-Host "      Result: $result" -ForegroundColor Gray
    }
} catch {
    Write-Host "      Warning - Could not test connection" -ForegroundColor Yellow
    Write-Host "      Error: $($_.Exception.Message)" -ForegroundColor Gray
}

Write-Host ""
Write-Host "==================================" -ForegroundColor Cyan
Write-Host "Fix Process Completed!" -ForegroundColor Green
Write-Host "==================================" -ForegroundColor Cyan
Write-Host ""

# Summary
Write-Host "Container Names:" -ForegroundColor Cyan
Write-Host "  - stock-analysis-app" -ForegroundColor White
Write-Host "  - stock-analysis-nginx" -ForegroundColor White
Write-Host "  - stock-analysis-mysql" -ForegroundColor White
Write-Host "  - stock-analysis-redis" -ForegroundColor White
Write-Host ""

Write-Host "Database Info:" -ForegroundColor Cyan
Write-Host "  Database: stock_analysis" -ForegroundColor White
Write-Host "  App User: laravel / secret" -ForegroundColor White
Write-Host "  Root User: root / rootpassword" -ForegroundColor White
Write-Host ""

Write-Host "Service URLs:" -ForegroundColor Cyan
Write-Host "  Laravel:    http://localhost:8000" -ForegroundColor White
Write-Host "  phpMyAdmin: http://localhost:8080" -ForegroundColor White
Write-Host "  MySQL:      localhost:3307" -ForegroundColor White
Write-Host ""

Write-Host "Next Steps:" -ForegroundColor Cyan
Write-Host "  1. docker-compose exec app composer install" -ForegroundColor Gray
Write-Host "  2. docker-compose exec app php artisan key:generate" -ForegroundColor Gray
Write-Host "  3. docker-compose exec app php artisan migrate" -ForegroundColor Gray
Write-Host "  4. docker-compose exec app php artisan db:seed" -ForegroundColor Gray
Write-Host ""

Write-Host "View Logs:" -ForegroundColor Cyan
Write-Host "  docker-compose logs -f app" -ForegroundColor Gray
Write-Host "  docker-compose logs -f db" -ForegroundColor Gray
Write-Host ""