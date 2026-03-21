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
        Schema::create('trade_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // 交易標的
            $table->string('symbol', 30)->comment('代碼');
            $table->string('name', 100)->comment('名稱');
            $table->enum('type', ['stock', 'option'])->default('stock')->comment('標的類型');
            $table->enum('order_type', ['buy', 'sell'])->comment('買/賣');

            // 交易資訊
            $table->date('trade_date')->comment('交易日期');
            $table->integer('quantity')->comment('股數/口數');
            $table->decimal('price', 12, 4)->comment('成交價格');
            $table->decimal('amount', 15, 2)->comment('成交金額 (quantity * price)');

            // 費用
            $table->decimal('commission', 10, 2)->default(0)->comment('手續費');
            $table->decimal('tax', 10, 2)->default(0)->comment('交易稅');
            $table->decimal('net_amount', 15, 2)->comment('淨額 (含手續費/稅)');

            // 損益 (僅賣出時有值)
            $table->decimal('realized_pnl', 15, 2)->nullable()->comment('實現損益');
            $table->decimal('realized_pnl_percent', 8, 4)->nullable()->comment('實現損益率(%)');

            // 備注
            $table->string('note', 255)->nullable()->comment('備注');

            $table->timestamps();

            // Index
            $table->index('user_id');
            $table->index(['user_id', 'trade_date']);
            $table->index(['user_id', 'symbol']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trade_histories');
    }
};
