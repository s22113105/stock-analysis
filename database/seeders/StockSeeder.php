<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Stock;
use Carbon\Carbon;

class StockSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $stocks = [
            [
                'symbol' => '2330',
                'name' => '台積電',
                'exchange' => 'TWSE',
                'industry' => '半導體業',
                'market_cap' => 15000000000000,
                'shares_outstanding' => 25930000000,
                'is_active' => true,
                'meta_data' => [
                    'name_en' => 'TSMC',
                    'chairman' => '劉德音',
                    'website' => 'https://www.tsmc.com',
                    'listing_date' => '1994-09-05'
                ]
            ],
            [
                'symbol' => '2317',
                'name' => '鴻海',
                'exchange' => 'TWSE',
                'industry' => '其他電子業',
                'market_cap' => 1500000000000,
                'shares_outstanding' => 13860000000,
                'is_active' => true,
                'meta_data' => [
                    'name_en' => 'Hon Hai',
                    'chairman' => '劉揚偉',
                    'website' => 'https://www.foxconn.com',
                    'listing_date' => '1991-06-18'
                ]
            ],
            [
                'symbol' => '2454',
                'name' => '聯發科',
                'exchange' => 'TWSE',
                'industry' => 'IC設計業',
                'market_cap' => 1200000000000,
                'shares_outstanding' => 1590000000,
                'is_active' => true,
                'meta_data' => [
                    'name_en' => 'MediaTek',
                    'chairman' => '蔡明介',
                    'website' => 'https://www.mediatek.com',
                    'listing_date' => '2001-07-23'
                ]
            ],
            [
                'symbol' => '2412',
                'name' => '中華電',
                'exchange' => 'TWSE',
                'industry' => '通信網路業',
                'market_cap' => 900000000000,
                'shares_outstanding' => 7760000000,
                'is_active' => true,
                'meta_data' => [
                    'name_en' => 'Chunghwa Telecom',
                    'chairman' => '郭水義',
                    'website' => 'https://www.cht.com.tw',
                    'listing_date' => '2000-10-27'
                ]
            ],
            [
                'symbol' => '2882',
                'name' => '國泰金',
                'exchange' => 'TWSE',
                'industry' => '金融保險業',
                'market_cap' => 600000000000,
                'shares_outstanding' => 13200000000,
                'is_active' => true,
                'meta_data' => [
                    'name_en' => 'Cathay Financial Holdings',
                    'chairman' => '蔡宏圖',
                    'website' => 'https://www.cathayholdings.com',
                    'listing_date' => '2001-12-19'
                ]
            ],
            [
                'symbol' => '1301',
                'name' => '台塑',
                'exchange' => 'TWSE',
                'industry' => '塑膠工業',
                'market_cap' => 500000000000,
                'shares_outstanding' => 6360000000,
                'is_active' => true,
                'meta_data' => [
                    'name_en' => 'Formosa Plastics',
                    'chairman' => '林健男',
                    'website' => 'https://www.fpc.com.tw',
                    'listing_date' => '1964-02-24'
                ]
            ],
            [
                'symbol' => '2303',
                'name' => '聯電',
                'exchange' => 'TWSE',
                'industry' => '半導體業',
                'market_cap' => 500000000000,
                'shares_outstanding' => 12400000000,
                'is_active' => true,
                'meta_data' => [
                    'name_en' => 'UMC',
                    'chairman' => '洪嘉聰',
                    'website' => 'https://www.umc.com',
                    'listing_date' => '1985-07-08'
                ]
            ],
            [
                'symbol' => '2308',
                'name' => '台達電',
                'exchange' => 'TWSE',
                'industry' => '電子零組件業',
                'market_cap' => 800000000000,
                'shares_outstanding' => 2600000000,
                'is_active' => true,
                'meta_data' => [
                    'name_en' => 'Delta Electronics',
                    'chairman' => '海英俊',
                    'website' => 'https://www.deltaww.com',
                    'listing_date' => '1988-10-21'
                ]
            ],
            [
                'symbol' => '2886',
                'name' => '兆豐金',
                'exchange' => 'TWSE',
                'industry' => '金融保險業',
                'market_cap' => 500000000000,
                'shares_outstanding' => 14100000000,
                'is_active' => true,
                'meta_data' => [
                    'name_en' => 'Mega Financial Holdings',
                    'chairman' => '雷仲達',
                    'website' => 'https://www.megaholdings.com.tw',
                    'listing_date' => '2002-02-04'
                ]
            ],
            [
                'symbol' => '2002',
                'name' => '中鋼',
                'exchange' => 'TWSE',
                'industry' => '鋼鐵工業',
                'market_cap' => 400000000000,
                'shares_outstanding' => 15700000000,
                'is_active' => true,
                'meta_data' => [
                    'name_en' => 'China Steel',
                    'chairman' => '翁朝棟',
                    'website' => 'https://www.csc.com.tw',
                    'listing_date' => '1971-02-09'
                ]
            ],
            [
                'symbol' => '0050',
                'name' => '元大台灣50',
                'exchange' => 'TWSE',
                'industry' => 'ETF',
                'market_cap' => 250000000000,
                'shares_outstanding' => 1800000000,
                'is_active' => true,
                'meta_data' => [
                    'name_en' => 'Yuanta Taiwan 50 ETF',
                    'issuer' => '元大投信',
                    'website' => 'https://www.yuantaetfs.com',
                    'listing_date' => '2003-06-25'
                ]
            ],
            [
                'symbol' => '2881',
                'name' => '富邦金',
                'exchange' => 'TWSE',
                'industry' => '金融保險業',
                'market_cap' => 900000000000,
                'shares_outstanding' => 10500000000,
                'is_active' => true,
                'meta_data' => [
                    'name_en' => 'Fubon Financial Holdings',
                    'chairman' => '蔡明興',
                    'website' => 'https://www.fubon.com',
                    'listing_date' => '2001-12-19'
                ]
            ],
            [
                'symbol' => '3008',
                'name' => '大立光',
                'exchange' => 'TWSE',
                'industry' => '光電業',
                'market_cap' => 300000000000,
                'shares_outstanding' => 134000000,
                'is_active' => true,
                'meta_data' => [
                    'name_en' => 'Largan Precision',
                    'chairman' => '林恩平',
                    'website' => 'https://www.largan.com.tw',
                    'listing_date' => '2002-06-03'
                ]
            ],
            [
                'symbol' => '2301',
                'name' => '光寶科',
                'exchange' => 'TWSE',
                'industry' => '電腦及週邊設備業',
                'market_cap' => 150000000000,
                'shares_outstanding' => 2340000000,
                'is_active' => true,
                'meta_data' => [
                    'name_en' => 'Lite-On Technology',
                    'chairman' => '宋明峰',
                    'website' => 'https://www.liteon.com',
                    'listing_date' => '1983-05-30'
                ]
            ],
            [
                'symbol' => '2357',
                'name' => '華碩',
                'exchange' => 'TWSE',
                'industry' => '電腦及週邊設備業',
                'market_cap' => 300000000000,
                'shares_outstanding' => 740000000,
                'is_active' => true,
                'meta_data' => [
                    'name_en' => 'ASUS',
                    'chairman' => '施崇棠',
                    'website' => 'https://www.asus.com',
                    'listing_date' => '1996-06-24'
                ]
            ]
        ];

        foreach ($stocks as $stock) {
            Stock::updateOrCreate(
                ['symbol' => $stock['symbol']],
                $stock
            );
        }

        $this->command->info('已建立 ' . count($stocks) . ' 筆股票資料');
    }
}
