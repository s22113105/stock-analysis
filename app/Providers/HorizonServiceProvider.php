<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            // 本地環境: 允許所有訪問
            if (app()->environment('local')) {
                return true;
            }

            // 生產環境: 需要登入且為管理員
            // 請根據您的需求調整此邏輯
            return $user !== null && in_array($user->email, [
                'admin@stock-analysis.com',
                'demo@stock.com', // 測試帳號
            ]);
        });
    }
}