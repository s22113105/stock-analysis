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
        Schema::create('data_fetch_logs', function (Blueprint $table) {
            $table->id();
            $table->string('job_name', 100)->comment('Job 名稱');
            $table->enum('status', ['pending', 'running', 'success', 'failed'])->default('pending')->comment('執行狀態');
            $table->date('fetch_date')->comment('抓取資料日期');
            $table->datetime('started_at')->nullable()->comment('開始時間');
            $table->datetime('completed_at')->nullable()->comment('完成時間');
            $table->integer('records_fetched')->default(0)->comment('抓取筆數');
            $table->integer('records_inserted')->default(0)->comment('新增筆數');
            $table->integer('records_updated')->default(0)->comment('更新筆數');
            $table->integer('records_failed')->default(0)->comment('失敗筆數');
            $table->text('error_message')->nullable()->comment('錯誤訊息');
            $table->json('metadata')->nullable()->comment('額外資訊');
            $table->timestamps();
            
            $table->index(['job_name', 'fetch_date']);
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_fetch_logs');
    }
};