import 'package:dio/dio.dart';
import 'package:get/get.dart' hide Response;
import 'package:shared_preferences/shared_preferences.dart';
import '../utils/app_constants.dart';

class ApiService extends GetxService {
  late Dio _dio;

  @override
  void onInit() {
    super.onInit();
    _initDio();
  }

  void _initDio() {
    _dio = Dio(BaseOptions(
      baseUrl: AppConstants.baseUrl,
      connectTimeout: const Duration(seconds: 15),
      receiveTimeout: const Duration(seconds: 30),
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
      },
    ));

    _dio.interceptors.add(InterceptorsWrapper(
      onRequest: (options, handler) async {
        final prefs = await SharedPreferences.getInstance();
        final token = prefs.getString(AppConstants.tokenKey);
        if (token != null) {
          options.headers['Authorization'] = 'Bearer $token';
        }
        return handler.next(options);
      },
      onError: (DioException e, handler) {
        if (e.response?.statusCode == 401) {
          Get.offAllNamed(AppConstants.routeLogin);
        }
        return handler.next(e);
      },
    ));
  }

  // ==========================================
  // Auth API
  // ==========================================
  Future<Map<String, dynamic>> login(String email, String password) async {
    final response = await _dio.post(AppConstants.loginUrl, data: {
      'email': email,
      'password': password,
    });
    return response.data;
  }

  Future<Map<String, dynamic>> register(
      String name, String email, String password) async {
    final response = await _dio.post(AppConstants.registerUrl, data: {
      'name': name,
      'email': email,
      'password': password,
      'password_confirmation': password,
    });
    return response.data;
  }

  Future<void> logout() async {
    try {
      await _dio.post(AppConstants.logoutUrl);
    } catch (_) {}
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(AppConstants.tokenKey);
    await prefs.remove(AppConstants.userNameKey);
    await prefs.remove(AppConstants.userEmailKey);
  }

  // ==========================================
  // Dashboard API
  // ==========================================
  Future<Map<String, dynamic>> getDashboardStats() async {
    final response = await _dio.get(AppConstants.dashboardStatsUrl);
    return response.data;
  }

  // ✅ 首頁股票走勢 - 對應 DashboardController::stockTrends
  Future<Map<String, dynamic>> getStockTrends({int days = 30}) async {
    final response = await _dio.get(
      AppConstants.dashboardStockTrendsUrl,
      queryParameters: {'days': days},
    );
    return response.data;
  }

  Future<Map<String, dynamic>> getVolatilityOverview() async {
    final response =
        await _dio.get(AppConstants.dashboardVolatilityOverviewUrl);
    return response.data;
  }

  Future<Map<String, dynamic>> getDashboardAlerts() async {
    final response = await _dio.get(AppConstants.dashboardAlertsUrl);
    return response.data;
  }

  // ==========================================
  // Stock API
  // GET /api/stocks — 全部股票清單
  // ==========================================
  Future<Map<String, dynamic>> getStocks({int perPage = 50}) async {
    final response = await _dio.get(
      AppConstants.stocksUrl,
      queryParameters: {'per_page': perPage, 'is_active': true},
    );
    return response.data;
  }

  // ==========================================
  // ✅ 修正：Stock Prices
  // GET /api/stocks/{symbol}/prices?days=30&paginate=false
  // StockPrice model 欄位: open, high, low, close, trade_date
  // ==========================================
  Future<Map<String, dynamic>> getStockPrices(
    String symbol, {
    int days = 30,
  }) async {
    final response = await _dio.get(
      '/stocks/$symbol/prices',
      queryParameters: {
        'days': days,
        'paginate': false,
      },
    );
    return response.data;
  }

  // ==========================================
  // Volatility API
  // GET /api/volatility/historical/{stockId}
  // ==========================================
  Future<Map<String, dynamic>> getHistoricalVolatility(
    int stockId, {
    int period = 30,
  }) async {
    final response = await _dio.get(
      '/volatility/historical/$stockId',
      queryParameters: {'period': period},
    );
    return response.data;
  }

  // ==========================================
  // Prediction API
  // ==========================================
  Future<Map<String, dynamic>> runPrediction(
      Map<String, dynamic> params) async {
    final response =
        await _dio.post(AppConstants.predictionRunUrl, data: params);
    return response.data;
  }

  Future<Map<String, dynamic>> getPredictionHistory() async {
    final response = await _dio.get(AppConstants.predictionHistoryUrl);
    return response.data;
  }

  // ==========================================
  // Backtest API
  // ==========================================
  Future<Map<String, dynamic>> runBacktest(
      Map<String, dynamic> params) async {
    final response =
        await _dio.post(AppConstants.backtestRunUrl, data: params);
    return response.data;
  }

  Future<Map<String, dynamic>> getBacktestResults() async {
    final response = await _dio.get(AppConstants.backtestResultsUrl);
    return response.data;
  }

  Future<Map<String, dynamic>> getBacktestStrategies() async {
    final response = await _dio.get(AppConstants.backtestStrategiesUrl);
    return response.data;
  }

  // ==========================================
  // Black-Scholes API
  // ==========================================
  Future<Map<String, dynamic>> calculateBlackScholes(
      Map<String, dynamic> params) async {
    final response =
        await _dio.post(AppConstants.blackScholesCalculateUrl, data: params);
    return response.data;
  }

  // ==========================================
  // Volatility Calculate (舊版相容)
  // ==========================================
  Future<Map<String, dynamic>> calculateVolatility(
      Map<String, dynamic> params) async {
    final response =
        await _dio.post(AppConstants.volatilityCalculateUrl, data: params);
    return response.data;
  }
}
