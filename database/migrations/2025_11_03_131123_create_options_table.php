<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('options', function (Blueprint $table) {
            $table->id();
            $table->string('underlying', 20)->comment('標的代碼(TXO)');
            $table->string('option_code', 50)->unique()->comment('選擇權代碼');
            $table->enum('option_type', ['call', 'put'])->comment('選擇權類型');
            $table->decimal('strike_price', 12, 2)->comment('履約價');
            $table->date('expiry_date')->comment('到期日');
            $table->integer('contract_size')->default(1)->comment('契約規模');
            $table->string('exercise_style', 20)->default('european')->comment('履約方式');
            $table->boolean('is_active')->default(true)->comment('是否啟用');
            $table->json('meta_data')->nullable()->comment('其他資料');
            $table->timestamps();
            
            $table->index('underlying');
            $table->index('option_type');
            $table->index('strike_price');
            $table->index('expiry_date');
            $table->index(['underlying', 'expiry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('options');
    }
};