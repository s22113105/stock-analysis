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
        Schema::create('options', function (Blueprint $table) {
            $table->id();
            // ❌ 移除 stock_id 外鍵
            // $table->foreignId('stock_id')->constrained()->onDelete('cascade');

            // ✅ 加入 underlying 欄位來記錄標的
            $table->string('underlying', 20)->default('TXO')->comment('標的代碼 (TXO=台指)');
            $table->string('option_code', 50)->unique()->comment('選擇權代碼');
            $table->enum('option_type', ['call', 'put'])->comment('選擇權類型');
            $table->decimal('strike_price', 10, 2)->comment('履約價');
            $table->date('expiry_date')->comment('到期日');
            $table->string('contract_size', 20)->default('50')->comment('契約規模');
            $table->enum('exercise_style', ['american', 'european'])->default('european')->comment('履約方式');
            $table->boolean('is_active')->default(true)->comment('是否啟用');
            $table->json('meta_data')->nullable()->comment('其他資料');
            $table->timestamps();

            // ✅ 更新索引（移除 stock_id 相關）
            $table->index('underlying');
            $table->index(['option_type', 'strike_price']);
            $table->index('expiry_date');
            $table->index('option_code');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('options');
    }
};
