<?php

namespace App\Jobs;

use App\Models\Option;
use App\Models\OptionPrice;
use App\Services\TaifexApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class FetchOptionDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $date;

    /**
     * 任務最大嘗試次數
     */
    public $tries = 3;

    /**
     * 任務超時時間
     */
    public $timeout = 300;

    /**
     * Create a new job instance.
     *
     * @param string|null $date 日期 (Y-m-d)
     */
    public function __construct($date = null)
    {
        $this->date = $date ?: now()->format('Y-m-d');
    }

    /**
     * Execute the job.
     */
    public function handle(TaifexApiService $taifexApi)
    {
        $startTime = microtime(true);

        Log::info('開始執行選擇權資料爬蟲 (TXO)', [
            'date' => $this->date
        ]);

        try {
            // 檢查是否為交易日
            if (!$this->isTradingDay($this->date)) {
                Log::info('非交易日，跳過執行', ['date' => $this->date]);
                return;
            }

            // ❌ 移除：不再需要創建 TAIEX 記錄
            // $twIndex = $this->ensureTaiwanIndex();

            DB::beginTransaction();

            // 1. 取得選擇權每日交易資料
            $optionsData = $taifexApi->getDailyOptionsReport($this->date);

            if ($optionsData->isEmpty()) {
                Log::warning('無選擇權資料', ['date' => $this->date]);
                DB::rollBack();
                return;
            }

            Log::info("取得 {$optionsData->count()} 筆選擇權資料");

            // 2. 取得 Delta 值資料
            $deltaData = $taifexApi->getDailyOptionsDelta($this->date);
            $deltaMap = $this->buildDeltaMap($deltaData);

            // 3. 處理選擇權資料
            $this->processOptionsData($optionsData, $deltaMap);

            // 4. 取得 Put/Call Ratio (儲存到 log 或其他地方)
            $putCallRatio = $taifexApi->getPutCallRatio($this->date);
            if (!empty($putCallRatio)) {
                $this->logPutCallRatio($putCallRatio);
            }

            DB::commit();

            $duration = round(microtime(true) - $startTime, 2);

            Log::info('選擇權資料爬蟲執行完成', [
                'date' => $this->date,
                'duration' => "{$duration}秒",
                'processed' => $optionsData->count()
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('選擇權資料爬蟲執行失敗', [
                'date' => $this->date,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    // ❌ 移除：不再需要此方法
    // protected function ensureTaiwanIndex() { ... }

    /**
     * 建立 Delta Map (option_code => delta)
     */
    protected function buildDeltaMap($deltaData)
    {
        $map = [];

        foreach ($deltaData as $item) {
            $optionCode = $item['ContractCode'] ?? '';
            $delta = floatval($item['Delta'] ?? 0);

            if ($optionCode) {
                $map[$optionCode] = $delta;
            }
        }

        return $map;
    }

    /**
     * 處理選擇權資料
     * ✅ 修改：不需要 $twIndex 參數
     */
    protected function processOptionsData($optionsData, $deltaMap)
    {
        $insertCount = 0;
        $updateCount = 0;

        foreach ($optionsData as $data) {
            try {
                // 1. 建立或取得選擇權合約
                // ✅ 修改：不再使用 stock_id
                $option = Option::firstOrCreate(
                    ['option_code' => $data['option_code']],
                    [
                        'underlying' => 'TXO',  // ✅ 使用 underlying
                        'option_type' => $data['option_type'],
                        'strike_price' => $data['strike_price'],
                        'expiry_date' => $data['expiry_date'],
                        'contract_size' => '50', // TXO 一口 = 指數 x 50
                        'exercise_style' => 'european', // 歐式
                        'is_active' => true,
                        'meta_data' => [
                            'underlying_name' => '臺指選擇權'
                        ]
                    ]
                );

                // 2. 取得 Delta 值
                $delta = $deltaMap[$data['option_code']] ?? null;

                // 3. 檢查價格資料是否存在
                $existingPrice = OptionPrice::where('option_id', $option->id)
                    ->where('trade_date', $this->date)
                    ->first();

                $priceData = [
                    'bid' => $data['bid'] > 0 ? $data['bid'] : null,
                    'ask' => $data['ask'] > 0 ? $data['ask'] : null,
                    'last' => $data['close'] > 0 ? $data['close'] : null,
                    'settlement' => $data['settlement'] > 0 ? $data['settlement'] : null,
                    'volume' => $data['volume'],
                    'open_interest' => $data['open_interest'],
                    'delta' => $delta,
                ];

                if ($existingPrice) {
                    // 更新
                    $existingPrice->update($priceData);
                    $updateCount++;
                } else {
                    // 新增
                    OptionPrice::create(array_merge($priceData, [
                        'option_id' => $option->id,
                        'trade_date' => $this->date,
                    ]));
                    $insertCount++;
                }
            } catch (\Exception $e) {
                Log::error('處理選擇權資料失敗', [
                    'option_code' => $data['option_code'],
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }

        Log::info('選擇權資料儲存完成', [
            'date' => $this->date,
            'inserted' => $insertCount,
            'updated' => $updateCount
        ]);
    }

    /**
     * ✅ 修改：記錄 Put/Call Ratio 到 log
     * (不再儲存到 stocks 表)
     */
    protected function logPutCallRatio($putCallRatio)
    {
        if (empty($putCallRatio)) {
            return;
        }

        Log::info('當日 Put/Call Ratio', [
            'date' => $this->date,
            'ratio' => $putCallRatio
        ]);

        // TODO: 如果需要，可以建立專門的 put_call_ratios 表
        // 或儲存到其他地方
    }

    /**
     * 檢查是否為交易日
     */
    protected function isTradingDay($date)
    {
        $carbon = Carbon::parse($date);

        // 週末不是交易日
        if ($carbon->isWeekend()) {
            return false;
        }

        // TODO: 檢查國定假日

        return true;
    }

    /**
     * 任務失敗時的處理
     */
    public function failed(\Throwable $exception)
    {
        Log::error('選擇權資料爬蟲任務失敗', [
            'date' => $this->date,
            'error' => $exception->getMessage()
        ]);

        // TODO: 發送通知
    }
}
