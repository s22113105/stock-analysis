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
            $table->decimal('open_price', 10, 2)->nullable()->comment('開盤價');
            $table->decimal('high_price', 10, 2)->nullable()->comment('最高價');
            $table->decimal('low_price', 10, 2)->nullable()->comment('最低價');
            $table->decimal('close_price', 10, 2)->comment('收盤價');
            $table->decimal('settlement_price', 10, 2)->nullable()->comment('結算價');
            $table->bigInteger('volume')->default(0)->comment('成交量');
            $table->integer('open_interest')->nullable()->comment('未平倉量');
            $table->decimal('bid_price', 10, 2)->nullable()->comment('買價');
            $table->decimal('ask_price', 10, 2)->nullable()->comment('賣價');
            $table->decimal('implied_volatility', 8, 4)->nullable()->comment('隱含波動率');
            $table->timestamps();
            
            $table->unique(['option_id', 'trade_date']);
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