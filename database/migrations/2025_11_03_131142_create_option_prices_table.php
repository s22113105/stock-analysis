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
            $table->decimal('bid', 10, 3)->nullable()->comment('買價');
            $table->decimal('ask', 10, 3)->nullable()->comment('賣價');
            $table->decimal('last', 10, 3)->nullable()->comment('成交價');
            $table->decimal('settlement', 10, 3)->nullable()->comment('結算價');
            $table->bigInteger('volume')->default(0)->comment('成交量');
            $table->bigInteger('open_interest')->default(0)->comment('未平倉量');
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