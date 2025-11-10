<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->onDelete('cascade');
            $table->date('trade_date')->comment('交易日期');
            $table->decimal('open', 12, 2)->comment('開盤價');
            $table->decimal('high', 12, 2)->comment('最高價');
            $table->decimal('low', 12, 2)->comment('最低價');
            $table->decimal('close', 12, 2)->comment('收盤價');
            $table->bigInteger('volume')->default(0)->comment('成交量');
            $table->decimal('turnover', 20, 2)->nullable()->comment('成交金額');
            $table->decimal('change', 12, 2)->nullable()->comment('漲跌');
            $table->decimal('change_percent', 10, 2)->nullable()->comment('漲跌幅(%)');
            $table->integer('transactions')->nullable()->comment('成交筆數');
            $table->timestamps();
            
            $table->unique(['stock_id', 'trade_date']);
            $table->index('trade_date');
            $table->index(['stock_id', 'trade_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_prices');
    }
};