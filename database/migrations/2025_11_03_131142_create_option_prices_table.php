<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('option_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('option_id')->constrained()->onDelete('cascade');
            $table->date('trade_date')->comment('交易日期');
            $table->decimal('open', 12, 4)->nullable()->comment('開盤價');
            $table->decimal('high', 12, 4)->nullable()->comment('最高價');
            $table->decimal('low', 12, 4)->nullable()->comment('最低價');
            $table->decimal('close', 12, 4)->nullable()->comment('收盤價');
            $table->integer('volume')->default(0)->comment('成交量');
            $table->integer('open_interest')->nullable()->comment('未平倉量');
            $table->decimal('implied_volatility', 10, 6)->nullable()->comment('隱含波動率');
            $table->decimal('delta', 10, 6)->nullable()->comment('Delta');
            $table->decimal('gamma', 10, 6)->nullable()->comment('Gamma');
            $table->decimal('theta', 10, 6)->nullable()->comment('Theta');
            $table->decimal('vega', 10, 6)->nullable()->comment('Vega');
            $table->decimal('rho', 10, 6)->nullable()->comment('Rho');
            $table->timestamps();
            
            $table->unique(['option_id', 'trade_date']);
            $table->index('trade_date');
            $table->index(['option_id', 'trade_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('option_prices');
    }
};