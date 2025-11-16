<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class TestLoginCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:login {email=demo@stock.com} {password=demo1234}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '測試登入功能並建立測試帳號';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email');
        $password = $this->argument('password');

        $this->info('🔍 開始測試登入系統...');
        $this->newLine();

        // Step 1: 檢查資料庫連線
        $this->info('Step 1/5: 檢查資料庫連線...');
        try {
            DB::connection()->getPdo();
            $this->info('✓ 資料庫連線正常');
        } catch (\Exception $e) {
            $this->error('✗ 資料庫連線失敗: ' . $e->getMessage());
            return Command::FAILURE;
        }
        $this->newLine();

        // Step 2: 檢查 users 資料表
        $this->info('Step 2/5: 檢查 users 資料表...');
        try {
            $count = User::count();
            $this->info("✓ Users 資料表存在，目前有 {$count} 位使用者");
        } catch (\Exception $e) {
            $this->error('✗ Users 資料表不存在或查詢失敗');
            $this->warn('請執行: php artisan migrate');
            return Command::FAILURE;
        }
        $this->newLine();

        // Step 3: 建立或更新測試帳號
        $this->info('Step 3/5: 建立/更新測試帳號...');
        try {
            $user = User::where('email', $email)->first();
            
            if (!$user) {
                $user = User::create([
                    'name' => 'Demo User',
                    'email' => $email,
                    'password' => Hash::make($password),
                    'email_verified_at' => now(),
                ]);
                $this->info("✓ 測試帳號建立成功: {$email}");
            } else {
                // 更新密碼以確保正確
                $user->update([
                    'password' => Hash::make($password),
                ]);
                $this->info("✓ 測試帳號已存在並更新密碼: {$email}");
            }

            $this->table(
                ['ID', '姓名', 'Email', '建立時間'],
                [[$user->id, $user->name, $user->email, $user->created_at]]
            );
        } catch (\Exception $e) {
            $this->error('✗ 建立測試帳號失敗: ' . $e->getMessage());
            return Command::FAILURE;
        }
        $this->newLine();

        // Step 4: 測試密碼驗證
        $this->info('Step 4/5: 測試密碼驗證...');
        if (Hash::check($password, $user->password)) {
            $this->info('✓ 密碼驗證成功');
        } else {
            $this->error('✗ 密碼驗證失敗');
            return Command::FAILURE;
        }
        $this->newLine();

        // Step 5: 檢查 Sanctum
        $this->info('Step 5/5: 檢查 Laravel Sanctum...');
        try {
            if (DB::getSchemaBuilder()->hasTable('personal_access_tokens')) {
                $this->info('✓ Sanctum personal_access_tokens 資料表存在');
            } else {
                $this->warn('! Sanctum 資料表不存在');
                $this->warn('請執行: php artisan migrate');
            }
        } catch (\Exception $e) {
            $this->error('✗ 檢查 Sanctum 失敗: ' . $e->getMessage());
        }
        $this->newLine();

        // 總結
        $this->info('================================');
        $this->info('✅ 測試完成！');
        $this->info('================================');
        $this->newLine();
        $this->info('🔑 測試帳號資訊:');
        $this->line("   Email: {$email}");
        $this->line("   密碼: {$password}");
        $this->newLine();
        $this->info('📝 測試步驟:');
        $this->line('   1. 訪問 http://127.0.0.1:8000/login');
        $this->line('   2. 使用上面的帳號密碼登入');
        $this->line('   3. 登入成功後應該導向 dashboard');
        $this->newLine();
        $this->info('🧪 手動測試 API:');
        $this->line('   curl -X POST http://127.0.0.1:8000/api/auth/login \\');
        $this->line('     -H "Content-Type: application/json" \\');
        $this->line('     -H "Accept: application/json" \\');
        $this->line("     -d '{\"email\":\"$email\",\"password\":\"$password\"}'");
        $this->newLine();

        return Command::SUCCESS;
    }
}