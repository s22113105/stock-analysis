<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class DiagnoseOpenApiCommand extends Command
{
    protected $signature = 'diagnose:openapi';
    protected $description = '診斷 OpenAPI 返回的資料結構';

    public function handle()
    {
        $this->info('========================================');
        $this->info('🔍 診斷 OpenAPI 資料結構');
        $this->info('========================================');
        $this->newLine();

        $url = 'https://openapi.taifex.com.tw/v1/DailyMarketReportOpt';

        try {
            $this->line('⏳ 正在呼叫 API...');

            $response = Http::timeout(30)->get($url);

            if (!$response->successful()) {
                $this->error('❌ API 請求失敗: ' . $response->status());
                return Command::FAILURE;
            }

            $data = $response->json();

            $this->info("✅ 取得 " . count($data) . " 筆資料");
            $this->newLine();

            // 分析第一筆資料的結構
            if (!empty($data)) {
                $this->info('📊 第一筆資料結構:');
                $this->line('----------------------------------------');

                $first = $data[0];
                foreach ($first as $key => $value) {
                    $valueStr = is_array($value) ? json_encode($value) : $value;
                    if (strlen($valueStr) > 50) {
                        $valueStr = substr($valueStr, 0, 50) . '...';
                    }
                    $this->line("  {$key}: {$valueStr}");
                }

                $this->newLine();
            }

            // 統計 TXO 相關資料
            $this->info('🎯 尋找 TXO 資料...');
            $this->line('----------------------------------------');

            $possibleKeys = ['商品代號', 'ContractCode', '契約', 'Code', '代碼', 'Symbol'];
            $foundKeys = [];

            foreach ($possibleKeys as $key) {
                if (isset($data[0][$key])) {
                    $foundKeys[] = $key;
                    $this->info("✅ 找到欄位: {$key}");
                }
            }

            if (empty($foundKeys)) {
                $this->warn('⚠️  沒有找到標準欄位名稱');
                $this->line('所有欄位名稱:');
                foreach (array_keys($data[0]) as $key) {
                    $this->line("  - {$key}");
                }
            }

            $this->newLine();

            // 搜尋包含 TXO 的記錄
            $this->info('🔍 搜尋包含 TXO 的記錄...');
            $this->line('----------------------------------------');

            $txoCount = 0;
            $samples = [];

            foreach ($data as $item) {
                // 檢查所有欄位
                foreach ($item as $key => $value) {
                    if (is_string($value) && stripos($value, 'TXO') !== false) {
                        $txoCount++;
                        if (count($samples) < 5) {
                            $samples[] = [
                                'key' => $key,
                                'value' => $value,
                                'full_record' => $item
                            ];
                        }
                        break;
                    }
                }

                if ($txoCount >= 100) break; // 只檢查前面部分
            }

            $this->info("找到 {$txoCount}+ 筆包含 TXO 的記錄");
            $this->newLine();

            if (!empty($samples)) {
                $this->info('📝 TXO 樣本資料:');
                $this->line('----------------------------------------');

                foreach ($samples as $index => $sample) {
                    $this->line("\n樣本 " . ($index + 1) . ":");
                    $this->line("  欄位名稱: {$sample['key']}");
                    $this->line("  值: {$sample['value']}");

                    // 顯示完整記錄（部分欄位）
                    $this->line("  完整記錄:");
                    foreach (array_slice($sample['full_record'], 0, 10) as $k => $v) {
                        $vStr = is_string($v) ? $v : json_encode($v);
                        if (strlen($vStr) > 40) $vStr = substr($vStr, 0, 40) . '...';
                        $this->line("    {$k}: {$vStr}");
                    }
                }
            }

            $this->newLine();

            // 儲存完整資料供分析
            Storage::put('debug/openapi_full_response.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            Storage::put('debug/openapi_first_record.json', json_encode($data[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            if (!empty($samples)) {
                Storage::put('debug/openapi_txo_samples.json', json_encode($samples, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }

            $this->info('💾 完整資料已儲存:');
            $this->line('  - storage/app/debug/openapi_full_response.json');
            $this->line('  - storage/app/debug/openapi_first_record.json');
            if (!empty($samples)) {
                $this->line('  - storage/app/debug/openapi_txo_samples.json');
            }

            $this->newLine();

            // 建議
            $this->info('========================================');
            $this->info('💡 建議');
            $this->info('========================================');
            $this->newLine();

            if (!empty($samples)) {
                $keyName = $samples[0]['key'];
                $this->info("✅ TXO 資料的欄位名稱是: {$keyName}");
                $this->line("請在 TaifexOpenApiService.php 中使用此欄位名稱");
                $this->newLine();

                $this->line("修改位置:");
                $this->line("  \$tradingCode = \$item['{$keyName}'] ?? '';");
            } else {
                $this->warn("⚠️  沒有找到 TXO 資料");
                $this->line("請檢查 storage/app/debug/ 中的 JSON 檔案");
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('❌ 發生錯誤: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
