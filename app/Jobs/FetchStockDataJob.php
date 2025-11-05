<?php

namespace App\Jobs;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\TwseApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class FetchStockDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $date;
    protected $symbol;

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
     */
    public function __construct($date = null, $symbol = null)
    {
        $this->date = $date ?: now()->format('Y-m-d');
        $this->symbol = $symbol;
    }

    /**
     * Execute the job.
     */
    public function handle(TwseApiService $twseApi)
    {
        Log::info('開始執行股票資料爬蟲', [
            'date' => $this->date,
            'symbol' => $this->symbol
        ]);

        try {
            // 如果是交易日才執行
            if (!$this->isTradingDay($this->date)) {
                Log::info('非交易日，跳過執行', ['date' => $this->date]);
                return;
            }

            DB::beginTransaction();

            // 更新股票基本資料
            if (!$this->symbol || $this->shouldUpdateCompanyData()) {
                $this->updateCompanyData($twseApi);
            }

            // 更新股票價格資料
            $this->updateStockPrices($twseApi);

            // 更新本益比、殖利率等資料
            $this->updateStockRatios($twseApi);

            // 更新融資融券資料
            $this->updateMarginTrading($twseApi);

            DB::commit();

            Log::info('股票資料爬蟲執行完成', [
                'date' => $this->date,
                'symbol' => $this->symbol
            ]);

            // 觸發相關事件（需要先建立 Event 類別）
            // event(new \App\Events\StockDataUpdated($this->date, $this->symbol));

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('股票資料爬蟲執行失敗', [
                'date' => $this->date,
                'symbol' => $this->symbol,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * 更新公司基本資料
     */
    protected function updateCompanyData(TwseApiService $twseApi)
    {
        $companies = $twseApi->getListedCompanies();

        foreach ($companies as $company) {
            if ($this->symbol && $company['symbol'] !== $this->symbol) {
                continue;
            }

            Stock::updateOrCreate(
                ['symbol' => $company['symbol']],
                [
                    'name' => $company['name'],
                    'industry' => $company['industry'],
                    'is_active' => true,
                    'meta_data' => [
                        'name_en' => $company['name_en'],
                        'address' => $company['address'],
                        'chairman' => $company['chairman'],
                        'general_manager' => $company['general_manager'],
                        'spokesperson' => $company['spokesperson'],
                        'establishment_date' => $company['establishment_date'],
                        'listing_date' => $company['listing_date'],
                        'website' => $company['website'],
                    ]
                ]
            );
        }

        Log::info('公司基本資料更新完成', ['count' => $companies->count()]);
    }

    /**
     * 更新股票價格資料
     */
    protected function updateStockPrices(TwseApiService $twseApi)
    {
        $dateString = Carbon::parse($this->date)->format('Ymd');
        $priceData = $twseApi->getStockDayAll($dateString);

        $insertData = [];

        foreach ($priceData as $data) {
            if ($this->symbol && $data['symbol'] !== $this->symbol) {
                continue;
            }

            $stock = Stock::where('symbol', $data['symbol'])->first();

            if (!$stock) {
                // 如果股票不存在，先建立
                $stock = Stock::create([
                    'symbol' => $data['symbol'],
                    'name' => $data['name'],
                    'is_active' => true
                ]);
            }

            $change = $data['change'];
            if ($data['change_sign'] === '－') {
                $change = -abs($change);
            }

            $changePercent = 0;
            if ($data['close'] > 0 && $change != 0) {
                $previousClose = $data['close'] - $change;
                if ($previousClose > 0) {
                    $changePercent = ($change / $previousClose) * 100;
                }
            }

            $insertData[] = [
                'stock_id' => $stock->id,
                'trade_date' => $this->date,
                'open' => $data['open'],
                'high' => $data['high'],
                'low' => $data['low'],
                'close' => $data['close'],
                'volume' => $data['volume'],
                'turnover' => $data['turnover'],
                'change' => $change,
                'change_percent' => round($changePercent, 2),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // 批量插入或更新
        if (!empty($insertData)) {
            StockPrice::upsert(
                $insertData,
                ['stock_id', 'trade_date'],
                ['open', 'high', 'low', 'close', 'volume', 'turnover', 'change', 'change_percent', 'updated_at']
            );
        }

        Log::info('股票價格資料更新完成', [
            'date' => $this->date,
            'count' => count($insertData)
        ]);
    }

    /**
     * 更新股票本益比等資料
     */
    protected function updateStockRatios(TwseApiService $twseApi)
    {
        $dateString = Carbon::parse($this->date)->format('Ymd');
        $ratioData = $twseApi->getStockPERatio($dateString);

        foreach ($ratioData as $data) {
            if ($this->symbol && $data['symbol'] !== $this->symbol) {
                continue;
            }

            $stock = Stock::where('symbol', $data['symbol'])->first();

            if ($stock) {
                $stockPrice = $stock->prices()
                    ->where('trade_date', $this->date)
                    ->first();

                if ($stockPrice) {
                    // 將比率資料存入 meta_data
                    $metaData = $stock->meta_data ?? [];
                    $metaData['pe_ratio'] = $data['pe_ratio'];
                    $metaData['pb_ratio'] = $data['pb_ratio'];
                    $metaData['dividend_yield'] = $data['dividend_yield'];
                    $metaData['dividend_year'] = $data['dividend_year'];

                    $stock->update(['meta_data' => $metaData]);
                }
            }
        }

        Log::info('股票比率資料更新完成', [
            'date' => $this->date,
            'count' => $ratioData->count()
        ]);
    }

    /**
     * 更新融資融券資料
     */
    protected function updateMarginTrading(TwseApiService $twseApi)
    {
        $dateString = Carbon::parse($this->date)->format('Ymd');
        $marginData = $twseApi->getMarginTrading($dateString);

        foreach ($marginData as $data) {
            if ($this->symbol && $data['symbol'] !== $this->symbol) {
                continue;
            }

            $stock = Stock::where('symbol', $data['symbol'])->first();

            if ($stock) {
                // 將融資融券資料存入 meta_data
                $metaData = $stock->meta_data ?? [];
                $metaData['margin_trading'] = [
                    'date' => $this->date,
                    'margin_buy' => $data['margin_buy'],
                    'margin_sell' => $data['margin_sell'],
                    'margin_balance' => $data['margin_balance'],
                    'short_sell' => $data['short_sell'],
                    'short_cover' => $data['short_cover'],
                    'short_balance' => $data['short_balance'],
                    'margin_limit' => $data['margin_limit'],
                    'short_limit' => $data['short_limit'],
                ];

                $stock->update(['meta_data' => $metaData]);
            }
        }

        Log::info('融資融券資料更新完成', [
            'date' => $this->date,
            'count' => $marginData->count()
        ]);
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

        // 這裡可以加入節假日的檢查
        // 可以從資料庫或 API 取得休市日資料

        return true;
    }

    /**
     * 是否需要更新公司基本資料
     */
    protected function shouldUpdateCompanyData()
    {
        // 每週一更新一次公司基本資料
        return Carbon::parse($this->date)->isMonday();
    }

    /**
     * 任務失敗時的處理
     */
    public function failed(\Throwable $exception)
    {
        Log::error('股票資料爬蟲任務失敗', [
            'date' => $this->date,
            'symbol' => $this->symbol,
            'error' => $exception->getMessage()
        ]);

        // 發送通知給管理員
        // Notification::send($admins, new JobFailedNotification($exception));
    }
}
