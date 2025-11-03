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
            $table->string('strategy_name', 100)->comment('策略名稱');
            $table->foreignId('stock_id')->nullable()->constrained()->onDelete('cascade');
            $table->date('start_date')->comment('回測開始日期');
            $table->date('end_date')->comment('回測結束日期');
            $table->decimal('initial_capital', 15, 2)->comment('初始資金');
            $table->decimal('final_capital', 15, 2)->comment('最終資金');
            $table->decimal('total_return', 10, 2)->comment('總報酬率(%)');
            $table->decimal('annual_return', 10, 2)->nullable()->comment('年化報酬率(%)');
            $table->decimal('sharpe_ratio', 8, 4)->nullable()->comment('夏普比率');
            $table->decimal('sortino_ratio', 8, 4)->nullable()->comment('索提諾比率');
            $table->decimal('max_drawdown', 10, 2)->nullable()->comment('最大回撤(%)');
            $table->decimal('win_rate', 5, 2)->nullable()->comment('勝率(%)');
            $table->integer('total_trades')->default(0)->comment('總交易次數');
            $table->integer('winning_trades')->default(0)->comment('獲利次數');
            $table->integer('losing_trades')->default(0)->comment('虧損次數');
            $table->decimal('avg_win', 10, 2)->nullable()->comment('平均獲利');
            $table->decimal('avg_loss', 10, 2)->nullable()->comment('平均虧損');
            $table->decimal('profit_factor', 8, 4)->nullable()->comment('獲利因子');
            $table->decimal('volatility', 10, 4)->nullable()->comment('策略波動率');
            $table->json('strategy_parameters')->nullable()->comment('策略參數');
            $table->json('equity_curve')->nullable()->comment('權益曲線');
            $table->json('trade_history')->nullable()->comment('交易歷史');
            $table->json('performance_metrics')->nullable()->comment('績效指標');
            $table->text('notes')->nullable()->comment('備註');
            $table->timestamps();
            
            $table->index('strategy_name');
            $table->index(['start_date', 'end_date']);
            $table->index('stock_id');
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