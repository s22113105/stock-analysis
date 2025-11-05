<?php

// @formatter:off
// phpcs:ignoreFile
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */


namespace App\Models{
/**
 * @property-read mixed $performance_score
 * @property-read mixed $profit
 * @property-read mixed $profit_loss_ratio
 * @property-read mixed $return_percent
 * @property-read \App\Models\Stock|null $stock
 * @method static \Illuminate\Database\Eloquent\Builder|BacktestResult byStrategy(string $strategyName)
 * @method static \Illuminate\Database\Eloquent\Builder|BacktestResult dateRange($startDate, $endDate)
 * @method static \Illuminate\Database\Eloquent\Builder|BacktestResult newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|BacktestResult newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|BacktestResult query()
 */
	class BacktestResult extends \Eloquent {}
}

namespace App\Models{
/**
 * @property-read mixed $days_to_expiry
 * @property-read mixed $time_to_expiry
 * @property-read \App\Models\OptionPrice|null $latestPrice
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Prediction> $predictions
 * @property-read int|null $predictions_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OptionPrice> $prices
 * @property-read int|null $prices_count
 * @property-read \App\Models\Stock|null $stock
 * @method static \Illuminate\Database\Eloquent\Builder|Option call()
 * @method static \Illuminate\Database\Eloquent\Builder|Option newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Option newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Option notExpired()
 * @method static \Illuminate\Database\Eloquent\Builder|Option put()
 * @method static \Illuminate\Database\Eloquent\Builder|Option query()
 * @method static \Illuminate\Database\Eloquent\Builder|Option strike($price)
 */
	class Option extends \Eloquent {}
}

namespace App\Models{
/**
 * @property-read mixed $mid_price
 * @property-read mixed $spread
 * @property-read \App\Models\Option|null $option
 * @method static \Illuminate\Database\Eloquent\Builder|OptionPrice dateRange($startDate, $endDate)
 * @method static \Illuminate\Database\Eloquent\Builder|OptionPrice newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|OptionPrice newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|OptionPrice query()
 */
	class OptionPrice extends \Eloquent {}
}

namespace App\Models{
/**
 * @property-read mixed $prediction_range
 * @property-read mixed $trend
 * @property-read \App\Models\Stock|null $stock
 * @method static \Illuminate\Database\Eloquent\Builder|Prediction byModel(string $modelType)
 * @method static \Illuminate\Database\Eloquent\Builder|Prediction forPredictionDate($date)
 * @method static \Illuminate\Database\Eloquent\Builder|Prediction forTargetDate($date)
 * @method static \Illuminate\Database\Eloquent\Builder|Prediction latest()
 * @method static \Illuminate\Database\Eloquent\Builder|Prediction newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Prediction newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Prediction query()
 */
	class Prediction extends \Eloquent {}
}

namespace App\Models{
/**
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\BacktestResult> $backtestResults
 * @property-read int|null $backtest_results_count
 * @property-read \App\Models\StockPrice|null $latestPrice
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Option> $options
 * @property-read int|null $options_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Prediction> $predictions
 * @property-read int|null $predictions_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\StockPrice> $prices
 * @property-read int|null $prices_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Volatility> $volatilities
 * @property-read int|null $volatilities_count
 * @method static \Illuminate\Database\Eloquent\Builder|Stock active()
 * @method static \Illuminate\Database\Eloquent\Builder|Stock byExchange($exchange)
 * @method static \Illuminate\Database\Eloquent\Builder|Stock newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Stock newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Stock query()
 */
	class Stock extends \Eloquent {}
}

namespace App\Models{
/**
 * @property-read mixed $daily_return
 * @property-read mixed $log_return
 * @property-read mixed $true_range
 * @property-read \App\Models\Stock|null $stock
 * @method static \Illuminate\Database\Eloquent\Builder|StockPrice dateRange($startDate, $endDate)
 * @method static \Illuminate\Database\Eloquent\Builder|StockPrice newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|StockPrice newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|StockPrice query()
 */
	class StockPrice extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property mixed $password
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Laravel\Sanctum\PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|User query()
 * @method static \Illuminate\Database\Eloquent\Builder|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereUpdatedAt($value)
 */
	class User extends \Eloquent {}
}

namespace App\Models{
/**
 * @property-read \App\Models\Stock|null $stock
 * @method static \Illuminate\Database\Eloquent\Builder|Volatility byPeriod($period)
 * @method static \Illuminate\Database\Eloquent\Builder|Volatility latest($stockId = null)
 * @method static \Illuminate\Database\Eloquent\Builder|Volatility newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Volatility newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Volatility query()
 */
	class Volatility extends \Eloquent {}
}

