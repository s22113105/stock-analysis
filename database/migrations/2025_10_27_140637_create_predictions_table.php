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
        Schema::create('predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->onDelete('cascade');
            $table->date('prediction_date')->comment('預測日期');
            $table->date('target_date')->comment('目標日期');
            $table->enum('model_type', ['lstm', 'arima', 'garch'])->comment('模型類型');
            $table->decimal('predicted_price', 10, 2)->nullable()->comment('預測價格');
            $table->decimal('predicted_volatility', 8, 4)->nullable()->comment('預測波動率');
            $table->decimal('confidence_upper', 10, 2)->nullable()->comment('信賴區間上界');
            $table->decimal('confidence_lower', 10, 2)->nullable()->comment('信賴區間下界');
            $table->decimal('accuracy', 8, 4)->nullable()->comment('準確度');
            $table->json('model_params')->nullable()->comment('模型參數');
            $table->timestamps();
            
            $table->index(['stock_id', 'prediction_date']);
            $table->index(['stock_id', 'target_date']);
            $table->index('model_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('predictions');
    }
};