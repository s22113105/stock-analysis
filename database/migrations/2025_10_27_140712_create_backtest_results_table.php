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
        Schema::create('backtest_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->onDelete('cascade');
            $table->string('strategy_name', 50)->comment('策略名稱');
            $table->date('start_date')->comment('回測開始日期');
            $table->date('end_date')->comment('回測結束日期');
            $table->decimal('initial_capital', 15, 2)->comment('初始資金');
            $table->decimal('final_capital', 15, 2)->comment('最終資金');
            $table->decimal('total_return', 10, 4)->comment('總報酬率');
            $table->decimal('sharpe_ratio', 8, 4)->nullable()->comment('夏普比率');
            $table->decimal('max_drawdown', 10, 4)->nullable()->comment('最大回撤');
            $table->integer('total_trades')->default(0)->comment('總交易次數');
            $table->integer('winning_trades')->default(0)->comment('獲利交易次數');
            $table->integer('losing_trades')->default(0)->comment('虧損交易次數');
            $table->decimal('win_rate', 8, 4)->nullable()->comment('勝率');
            $table->decimal('avg_profit', 10, 2)->nullable()->comment('平均獲利');
            $table->decimal('avg_loss', 10, 2)->nullable()->comment('平均虧損');
            $table->json('strategy_params')->nullable()->comment('策略參數');
            $table->json('daily_returns')->nullable()->comment('每日報酬');
            $table->timestamps();
            
            $table->index(['stock_id', 'strategy_name']);
            $table->index(['start_date', 'end_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backtest_results');
    }
};