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
            $table->decimal('hv_10', 8, 4)->nullable()->comment('10日歷史波動率');
            $table->decimal('hv_20', 8, 4)->nullable()->comment('20日歷史波動率');
            $table->decimal('hv_30', 8, 4)->nullable()->comment('30日歷史波動率');
            $table->decimal('hv_60', 8, 4)->nullable()->comment('60日歷史波動率');
            $table->decimal('iv_call', 8, 4)->nullable()->comment('Call 平均隱含波動率');
            $table->decimal('iv_put', 8, 4)->nullable()->comment('Put 平均隱含波動率');
            $table->decimal('iv_atm', 8, 4)->nullable()->comment('價平隱含波動率');
            $table->timestamps();
            
            $table->unique(['stock_id', 'calculation_date']);
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