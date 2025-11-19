# ==========================================
# Test JSON Format
# ==========================================

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "JSON Format Test" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Create test data
$testData = @{
    prices = @(100, 101, 102, 103, 104, 105)
    dates = @("2025-01-01", "2025-01-02", "2025-01-03", "2025-01-04", "2025-01-05", "2025-01-06")
    base_date = "2025-11-18"
    prediction_days = 1
    stock_symbol = "TEST"
    auto_select = $true
}

Write-Host "Test 1: PowerShell ConvertTo-Json" -ForegroundColor Yellow
Write-Host ""

$json1 = $testData | ConvertTo-Json -Compress
Write-Host "JSON Output:" -ForegroundColor Cyan
Write-Host $json1 -ForegroundColor Gray
Write-Host ""
Write-Host "First 50 chars: $($json1.Substring(0, [Math]::Min(50, $json1.Length)))" -ForegroundColor Gray
Write-Host ""

# Save to temp file and test with Python
$tempFile = [System.IO.Path]::GetTempFileName()
$json1 | Out-File -FilePath $tempFile -Encoding UTF8 -NoNewline

Write-Host "Test 2: Python Read from File" -ForegroundColor Yellow
Write-Host ""

$pythonCode = @"
import json
import sys

try:
    with open('$($tempFile.Replace('\', '/'))', 'r', encoding='utf-8') as f:
        data = json.load(f)
    print('SUCCESS: JSON parsed from file')
    print(f'Keys: {list(data.keys())}')
    print(f'Prices count: {len(data["prices"])}')
except Exception as e:
    print(f'ERROR: {e}')
"@

& C:\Python313\python.exe -c $pythonCode

Write-Host ""

Write-Host "Test 3: Python Parse from Command Line Argument" -ForegroundColor Yellow
Write-Host ""

# Method 1: Direct string
Write-Host "Method 1: Direct String" -ForegroundColor Cyan
$result1 = & C:\Python313\python.exe -c "import sys, json; print(json.loads(sys.argv[1]))" $json1 2>&1
Write-Host "Result: $result1" -ForegroundColor $(if ($LASTEXITCODE -eq 0) { "Green" } else { "Red" })
Write-Host ""

# Method 2: Single quotes
Write-Host "Method 2: With Single Quotes" -ForegroundColor Cyan
$result2 = & C:\Python313\python.exe -c "import sys, json; print(json.loads(sys.argv[1]))" "'$json1'" 2>&1
Write-Host "Result: $result2" -ForegroundColor $(if ($LASTEXITCODE -eq 0) { "Green" } else { "Red" })
Write-Host ""

# Method 3: Escape quotes
Write-Host "Method 3: Escaped JSON" -ForegroundColor Cyan
$jsonEscaped = $json1.Replace('"', '\"')
$result3 = & C:\Python313\python.exe -c "import sys, json; print(json.loads(sys.argv[1]))" $jsonEscaped 2>&1
Write-Host "Result: $result3" -ForegroundColor $(if ($LASTEXITCODE -eq 0) { "Green" } else { "Red" })
Write-Host ""

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Test Laravel's Method (Temp File)" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "Creating temp file with JSON..." -ForegroundColor Yellow

# Create temp file like Laravel does
$tempJsonFile = [System.IO.Path]::GetTempFileName()
$json1 | Out-File -FilePath $tempJsonFile -Encoding UTF8 -NoNewline

Write-Host "Temp file: $tempJsonFile" -ForegroundColor Gray
Write-Host ""

# Test reading from temp file (like Laravel should do)
$testScript = @"
import sys
import json

try:
    # Read from temp file
    with open(sys.argv[1], 'r', encoding='utf-8') as f:
        input_data = json.load(f)

    print(json.dumps({
        'success': True,
        'message': 'JSON parsed successfully',
        'keys': list(input_data.keys()),
        'prices_count': len(input_data['prices'])
    }))
except Exception as e:
    import traceback
    print(json.dumps({
        'success': False,
        'error': str(e),
        'traceback': traceback.format_exc()
    }))
"@

$testScriptFile = "test_json_parse.py"
$testScript | Out-File -FilePath $testScriptFile -Encoding UTF8

Write-Host "Testing Python script with temp file..." -ForegroundColor Yellow
$output = & C:\Python313\python.exe $testScriptFile $tempJsonFile 2>&1
Write-Host "Output: $output" -ForegroundColor $(if ($LASTEXITCODE -eq 0) { "Green" } else { "Red" })

# Cleanup
Remove-Item $tempFile -ErrorAction SilentlyContinue
Remove-Item $tempJsonFile -ErrorAction SilentlyContinue
Remove-Item $testScriptFile -ErrorAction SilentlyContinue

Write-Host ""
Write-Host "========================================" -ForegroundColor Green
Write-Host "Analysis" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
Write-Host ""

Write-Host "The issue is likely in how Laravel passes JSON to Python." -ForegroundColor Yellow
Write-Host ""
Write-Host "Solution: Laravel should:" -ForegroundColor Cyan
Write-Host "  1. Write JSON to a temp file" -ForegroundColor Gray
Write-Host "  2. Pass the file path to Python" -ForegroundColor Gray
Write-Host "  3. Python reads from the file" -ForegroundColor Gray
Write-Host ""
Write-Host "This avoids command-line argument escaping issues." -ForegroundColor Yellow
