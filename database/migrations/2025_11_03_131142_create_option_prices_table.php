<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('option_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('option_id')->constrained()->onDelete('cascade');
            $table->date('trade_date')->comment('交易日期');

            // 價格資訊
            $table->decimal('open', 10, 3)->nullable()->comment('開盤價');
            $table->decimal('high', 10, 3)->nullable()->comment('最高價');
            $table->decimal('low', 10, 3)->nullable()->comment('最低價');
            $table->decimal('close', 10, 3)->nullable()->comment('收盤價');
            $table->decimal('settlement', 10, 3)->nullable()->comment('結算價');
            $table->decimal('change', 10, 3)->nullable()->comment('漲跌');
            $table->decimal('change_percent', 8, 2)->nullable()->comment('漲跌幅(%)');

            // 買賣報價
            $table->decimal('bid', 10, 3)->nullable()->comment('買價');
            $table->decimal('ask', 10, 3)->nullable()->comment('賣價');
            $table->bigInteger('bid_volume')->nullable()->comment('買量');
            $table->bigInteger('ask_volume')->nullable()->comment('賣量');

            // 計算欄位
            $table->decimal('spread', 10, 3)->nullable()->comment('價差');
            $table->decimal('mid_price', 10, 3)->nullable()->comment('中間價');

            // 交易量資訊
            $table->bigInteger('volume')->default(0)->comment('總成交量');
            $table->bigInteger('volume_general')->nullable()->comment('一般交易量');
            $table->bigInteger('volume_afterhours')->nullable()->comment('盤後交易量');
            $table->bigInteger('open_interest')->default(0)->comment('未平倉量');

            // Greeks (選填，供未來計算使用)
            $table->decimal('implied_volatility', 8, 4)->nullable()->comment('隱含波動率');
            $table->decimal('delta', 8, 5)->nullable()->comment('Delta值');
            $table->decimal('gamma', 8, 5)->nullable()->comment('Gamma值');
            $table->decimal('theta', 8, 5)->nullable()->comment('Theta值');
            $table->decimal('vega', 8, 5)->nullable()->comment('Vega值');
            $table->decimal('rho', 8, 5)->nullable()->comment('Rho值');
            $table->decimal('theoretical_value', 10, 3)->nullable()->comment('理論價值');

            $table->timestamps();

            $table->unique(['option_id', 'trade_date']);
            $table->index(['option_id', 'trade_date']);
            $table->index('trade_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('option_prices');
    }
};
