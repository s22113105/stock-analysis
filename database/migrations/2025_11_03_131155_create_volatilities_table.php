<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('volatilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->onDelete('cascade');
            $table->date('calculation_date')->comment('計算日期');
            $table->integer('period_days')->comment('計算期間(天)');
            $table->decimal('historical_volatility', 10, 6)->nullable()->comment('歷史波動率');
            $table->decimal('realized_volatility', 10, 6)->nullable()->comment('實現波動率');
            $table->decimal('implied_volatility', 10, 6)->nullable()->comment('隱含波動率');
            $table->decimal('volatility_rank', 5, 2)->nullable()->comment('波動率排名');
            $table->json('volatility_cone')->nullable()->comment('波動率錐');
            $table->json('calculation_params')->nullable()->comment('計算參數');
            $table->timestamps();
            
            $table->unique(['stock_id', 'calculation_date', 'period_days']);
            $table->index('calculation_date');
            $table->index(['stock_id', 'calculation_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('volatilities');
    }
};