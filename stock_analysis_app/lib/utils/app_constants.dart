class AppConstants {
  // ==========================================
  // API Base URL
  // ==========================================
  static const String baseUrl = 'http://localhost/api';
  // Android 模擬器: 'http://10.0.2.2/api'
  // 線上伺服器: 'https://your-domain.com/api'

  // ==========================================
  // Auth API (對應 AuthController)
  // ==========================================
  static const String loginUrl = '/auth/login';
  static const String registerUrl = '/auth/register';
  static const String logoutUrl = '/auth/logout';
  static const String userUrl = '/user';

  // ==========================================
  // Dashboard API (對應 DashboardController)
  // ==========================================
  static const String dashboardStatsUrl = '/dashboard/stats';
  static const String dashboardStockTrendsUrl = '/dashboard/stock-trends';
  static const String dashboardVolatilityOverviewUrl = '/dashboard/volatility-overview';
  static const String dashboardAlertsUrl = '/dashboard/alerts';
  static const String dashboardPortfolioUrl = '/dashboard/portfolio';
  static const String dashboardPerformanceUrl = '/dashboard/performance';

  // ==========================================
  // Stock API (對應 StockController)
  // ==========================================
  static const String stocksUrl = '/stocks';

  // ==========================================
  // Volatility API (對應 VolatilityController)
  // ==========================================
  static const String volatilityCalculateUrl = '/volatility/calculate';

  // ==========================================
  // Prediction API (對應 PredictionController)
  // ==========================================
  static const String predictionRunUrl = '/predictions/run';
  static const String predictionHistoryUrl = '/predictions/history';

  // ==========================================
  // Backtest API (對應 BacktestController)
  // ==========================================
  static const String backtestRunUrl = '/backtest/run';
  static const String backtestResultsUrl = '/backtest/results';
  static const String backtestStrategiesUrl = '/backtest/strategies';

  // ==========================================
  // Black-Scholes API (對應 BlackScholesController)
  // ==========================================
  static const String blackScholesCalculateUrl = '/black-scholes/calculate';

  // ==========================================
  // 固定顯示股票 (對應 DashboardController::TARGET_STOCKS)
  // ==========================================
  static const List<String> targetStocks = ['2330', '2317', '2454'];

  // ==========================================
  // SharedPreferences Keys
  // ==========================================
  static const String tokenKey = 'authToken';
  static const String userNameKey = 'userName';
  static const String userEmailKey = 'userEmail';

  // ==========================================
  // Route Names
  // ==========================================
  static const String routeLogin = '/login';
  static const String routeHome = '/home';
  static const String routeChart = '/chart';
  static const String routeNotification = '/notification';
  static const String routeBacktest = '/backtest';
  static const String routePrediction = '/prediction';
  static const String routeBlackScholes = '/black-scholes';
}
