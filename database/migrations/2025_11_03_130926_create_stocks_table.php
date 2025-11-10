<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 20)->unique()->comment('股票代碼');
            $table->string('name', 100)->comment('股票名稱');
            $table->string('exchange', 20)->default('TWSE')->comment('交易所');
            $table->string('industry', 100)->nullable()->comment('產業別');
            $table->decimal('market_cap', 20, 2)->nullable()->comment('市值');
            $table->decimal('shares_outstanding', 20, 2)->nullable()->comment('流通股數');
            $table->boolean('is_active')->default(true)->comment('是否啟用');
            $table->json('meta_data')->nullable()->comment('其他資料');
            $table->timestamps();
            
            $table->index('symbol');
            $table->index('exchange');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};