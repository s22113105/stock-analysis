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
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 10)->unique()->comment('股票代碼');
            $table->string('name', 50)->comment('股票名稱');
            $table->string('market', 20)->default('TWSE')->comment('市場別：TWSE/OTC');
            $table->string('industry', 50)->nullable()->comment('產業別');
            $table->boolean('is_active')->default(true)->comment('是否啟用');
            $table->timestamps();
            
            $table->index('symbol');
            $table->index(['market', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};