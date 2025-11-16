<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * 執行資料庫填充
     */
    public function run(): void
    {
        // 清空現有使用者 (開發環境使用,生產環境請移除)
        // User::truncate();

        // 建立管理員帳號
        User::create([
            'name' => 'Admin',
            'email' => 'admin@stock.com',
            'password' => Hash::make('admin1234'),
            'email_verified_at' => now(),
        ]);

        // 建立示範帳號
        User::create([
            'name' => 'Demo User',
            'email' => 'demo@stock.com',
            'password' => Hash::make('demo1234'),
            'email_verified_at' => now(),
        ]);

        // 建立測試帳號
        User::create([
            'name' => '張三',
            'email' => 'test@stock.com',
            'password' => Hash::make('test1234'),
            'email_verified_at' => now(),
        ]);

        $this->command->info('✅ 使用者資料填充完成！');
        $this->command->info('');
        $this->command->info('📋 預設帳號清單:');
        $this->command->info('------------------------------------');
        $this->command->info('管理員 - admin@stock.com / admin1234');
        $this->command->info('示範帳號 - demo@stock.com / demo1234');
        $this->command->info('測試帳號 - test@stock.com / test1234');
        $this->command->info('------------------------------------');
    }
}