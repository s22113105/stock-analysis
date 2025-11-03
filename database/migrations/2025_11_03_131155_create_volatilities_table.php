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
        Schema::create('volatilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->onDelete('cascade');
            $table->date('calculation_date')->comment('計算日期');
            $table->integer('period_days')->comment('計算期間(天數)');
            $table->decimal('historical_volatility', 10, 6)->nullable()->comment('歷史波動率');
            $table->decimal('implied_volatility_call', 10, 6)->nullable()->comment('Call隱含波動率');
            $table->decimal('implied_volatility_put', 10, 6)->nullable()->comment('Put隱含波動率');
            $table->decimal('volatility_skew', 10, 6)->nullable()->comment('波動率偏斜');
            $table->decimal('volatility_smile', 10, 6)->nullable()->comment('波動率微笑');
            $table->decimal('garch_volatility', 10, 6)->nullable()->comment('GARCH模型波動率');
            $table->decimal('realized_volatility', 10, 6)->nullable()->comment('實現波動率');
            $table->json('volatility_surface')->nullable()->comment('波動率曲面數據');
            $table->json('meta_data')->nullable()->comment('其他資料');
            $table->timestamps();
            
            $table->unique(['stock_id', 'calculation_date', 'period_days']);
            $table->index(['stock_id', 'calculation_date']);
            $table->index('calculation_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('volatilities');
    }
};