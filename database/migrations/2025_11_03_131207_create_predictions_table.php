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
            $table->morphs('predictable'); // 可預測股票或選擇權
            $table->string('model_type', 50)->comment('模型類型(LSTM/ARIMA/GARCH)');
            $table->date('prediction_date')->comment('預測日期');
            $table->integer('prediction_days')->comment('預測天數');
            $table->decimal('predicted_price', 12, 4)->nullable()->comment('預測價格');
            $table->decimal('predicted_volatility', 10, 6)->nullable()->comment('預測波動率');
            $table->decimal('upper_bound', 12, 4)->nullable()->comment('預測上界');
            $table->decimal('lower_bound', 12, 4)->nullable()->comment('預測下界');
            $table->decimal('confidence_level', 5, 2)->default(95)->comment('信心水準(%)');
            $table->decimal('mse', 12, 6)->nullable()->comment('均方誤差');
            $table->decimal('rmse', 12, 6)->nullable()->comment('均方根誤差');
            $table->decimal('mae', 12, 6)->nullable()->comment('平均絕對誤差');
            $table->decimal('accuracy', 5, 2)->nullable()->comment('準確率(%)');
            $table->json('model_parameters')->nullable()->comment('模型參數');
            $table->json('prediction_series')->nullable()->comment('預測序列');
            $table->text('notes')->nullable()->comment('備註');
            $table->timestamps();
            
            $table->index(['predictable_type', 'predictable_id']);
            $table->index('prediction_date');
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