<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes - Stock_Analysis 系統
|--------------------------------------------------------------------------
*/

// SPA 主要入口 - 所有路由都導向 Vue 應用
Route::get('/{any}', function () {
    return view('app');  // 改為使用 app.blade.php
})->where('any', '.*');

// 或者如果您想要區分 API 和前端路由
// Route::get('/', function () {
//     return view('app');
// });
//
// Route::get('/dashboard', function () {
//     return view('app');
// });
//
// Route::get('/stocks', function () {
//     return view('app');
// });
//
// Route::get('/options', function () {
//     return view('app');
// });
//
// Route::get('/predictions', function () {
//     return view('app');
// });
//
// Route::get('/black-scholes', function () {
//     return view('app');
// });
//
// Route::get('/volatility', function () {
//     return view('app');
// });
//
// Route::get('/backtest', function () {
//     return view('app');
// });
