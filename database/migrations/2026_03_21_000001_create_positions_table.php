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
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // 持倉標的
            $table->string('symbol', 30)->comment('代碼 (股票: 2330 / 選擇權: TXO_2025_01_C_20000)');
            $table->string('name', 100)->comment('名稱');
            $table->enum('type', ['stock', 'option'])->default('stock')->comment('持倉類型');

            // 選擇權專屬欄位
            $table->date('expiry_date')->nullable()->comment('到期日 (選擇權用)');
            $table->enum('option_type', ['call', 'put'])->nullable()->comment('選擇權類型');
            $table->decimal('strike_price', 12, 2)->nullable()->comment('履約價');

            // 持倉數量與成本
            $table->integer('quantity')->default(0)->comment('持有股數/口數');
            $table->decimal('avg_price', 12, 4)->comment('平均成本價');
            $table->decimal('cost', 15, 2)->comment('總成本');

            // 即時市值 (由 API 更新)
            $table->decimal('current_price', 12, 4)->nullable()->comment('目前市價');
            $table->decimal('market_value', 15, 2)->nullable()->comment('市值');
            $table->decimal('unrealized_pnl', 15, 2)->nullable()->comment('未實現損益');
            $table->decimal('unrealized_pnl_percent', 8, 4)->nullable()->comment('未實現損益率(%)');

            $table->boolean('is_active')->default(true)->comment('是否有效持倉 (數量>0)');
            $table->timestamps();

            // Index
            $table->index('user_id');
            $table->index(['user_id', 'symbol']);
            $table->index(['user_id', 'is_active']);
            $table->unique(['user_id', 'symbol', 'type'], 'positions_user_symbol_type_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
