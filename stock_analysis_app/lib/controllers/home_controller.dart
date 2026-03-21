import 'package:get/get.dart';
import '../services/api_service.dart';
import '../models/dashboard_model.dart';
import '../models/stock_model.dart';

class HomeController extends GetxController {
  final ApiService _apiService = Get.find<ApiService>();

  final RxBool isLoadingStats = false.obs;
  final RxBool isLoadingTrends = false.obs;
  final RxBool isLoadingVolatility = false.obs;

  final Rxn<DashboardStatsModel> stats = Rxn<DashboardStatsModel>();
  final Rxn<StockTrendModel> stockTrends = Rxn<StockTrendModel>();
  final RxList<VolatilityOverviewModel> volatilityList =
      <VolatilityOverviewModel>[].obs;
  final RxList<AlertModel> alerts = <AlertModel>[].obs;
  final RxInt selectedDays = 30.obs;

  @override
  void onInit() {
    super.onInit();
    loadAll();
  }

  Future<void> loadAll() async {
    await Future.wait([
      loadStats(),
      loadStockTrends(),
      loadVolatilityOverview(),
      loadAlerts(),
    ]);
  }

  // ==========================================
  // GET /api/dashboard/stats
  // ==========================================
  Future<void> loadStats() async {
    isLoadingStats.value = true;
    try {
      final response = await _apiService.getDashboardStats();
      stats.value = DashboardStatsModel.fromJson(response);
    } catch (e) {
      stats.value = DashboardStatsModel(
        totalStocks: 0, activeStocks: 0,
        totalOptions: 0, activeOptions: 0,
        latestUpdate: null, targetStocks: [],
      );
    } finally {
      isLoadingStats.value = false;
    }
  }

  // ==========================================
  // ✅ 修正：GET /api/dashboard/stock-trends
  // 回傳格式: {success, data: {stocks: [...], dates: [...]}}
  // 每個 stock 有 prices: [{trade_date, open, high, low, close, volume}]
  // ==========================================
  Future<void> loadStockTrends() async {
    isLoadingTrends.value = true;
    try {
      final response =
          await _apiService.getStockTrends(days: selectedDays.value);

      final raw = response['data'] ?? response;

      // 取 dates
      final dates = List<String>.from(raw['dates'] ?? []);

      // 取 stocks
      final stocksRaw = raw['stocks'] as List<dynamic>? ?? [];

      final stocks = stocksRaw.map((s) {
        // 每支股票的 prices
        final pricesRaw = s['prices'] as List<dynamic>? ?? [];
        final prices = pricesRaw.map((p) {
          final tradeDate = p['trade_date']?.toString() ?? '';
          final dateStr = tradeDate.length >= 10
              ? tradeDate.substring(0, 10)
              : tradeDate;
          return StockPriceModel(
            id: p['id'] ?? 0,
            stockId: s['id'] ?? 0,
            tradeDate: dateStr,
            openPrice: _toDouble(p['open']),
            highPrice: _toDouble(p['high']),
            lowPrice: _toDouble(p['low']),
            closePrice: _toDouble(p['close']),
            volume: _toInt(p['volume']),
          );
        }).toList();

        // 計算漲跌幅：比較最新和前一筆
        double? changePercent;
        double? currentPrice;
        if (prices.length >= 2) {
          final latest = prices.last.closePrice;
          final prev = prices[prices.length - 2].closePrice;
          currentPrice = latest;
          if (prev > 0) changePercent = (latest - prev) / prev * 100;
        } else if (prices.isNotEmpty) {
          currentPrice = prices.last.closePrice;
        }

        return StockModel(
          id: s['id'] ?? 0,
          symbol: s['symbol']?.toString() ?? '',
          name: s['name']?.toString() ?? '',
          isActive: s['is_active'] ?? true,
          currentPrice: currentPrice,
          changePercent: changePercent,
          prices: prices,
        );
      }).toList();

      stockTrends.value = StockTrendModel(dates: dates, stocks: stocks);
    } catch (e) {
      // 連不到 API 才用 mock
      stockTrends.value = _getMockStockTrends();
    } finally {
      isLoadingTrends.value = false;
    }
  }

  // ==========================================
  // GET /api/dashboard/volatility-overview
  // ==========================================
  Future<void> loadVolatilityOverview() async {
    isLoadingVolatility.value = true;
    try {
      final response = await _apiService.getVolatilityOverview();
      final raw = response['data'];
      final list = raw is List ? raw : [];
      volatilityList.value =
          list.map((v) => VolatilityOverviewModel.fromJson(v)).toList();

      // 如果 API 回空陣列，用預設 mock（只顯示 2330/2317/2454）
      if (volatilityList.isEmpty) {
        volatilityList.value = [
          VolatilityOverviewModel(symbol: '2330', hv: 0, iv: 0),
          VolatilityOverviewModel(symbol: '2317', hv: 0, iv: 0),
          VolatilityOverviewModel(symbol: '2454', hv: 0, iv: 0),
        ];
      }
    } catch (e) {
      volatilityList.value = [
        VolatilityOverviewModel(symbol: '2330', hv: 18.5, iv: 22.3, signal: 'high_iv'),
        VolatilityOverviewModel(symbol: '2317', hv: 15.2, iv: 17.8, signal: 'normal'),
        VolatilityOverviewModel(symbol: '2454', hv: 21.0, iv: 24.5, signal: 'high_iv'),
      ];
    } finally {
      isLoadingVolatility.value = false;
    }
  }

  // ==========================================
  // GET /api/dashboard/alerts
  // ==========================================
  Future<void> loadAlerts() async {
    try {
      final response = await _apiService.getDashboardAlerts();
      final data = response['data'];
      final list = data?['alerts'] as List<dynamic>? ?? [];
      alerts.value = list.map((a) => AlertModel.fromJson(a)).toList();
    } catch (e) {
      alerts.value = [];
    }
  }

  void changeDays(int days) {
    selectedDays.value = days;
    loadStockTrends();
  }

  // ==========================================
  // 工具函式
  // ==========================================
  double _toDouble(dynamic v) {
    if (v == null) return 0.0;
    if (v is double) return v;
    if (v is int) return v.toDouble();
    return double.tryParse(v.toString()) ?? 0.0;
  }

  int _toInt(dynamic v) {
    if (v == null) return 0;
    if (v is int) return v;
    if (v is double) return v.toInt();
    return int.tryParse(v.toString()) ?? 0;
  }

  // Mock（只有 API 完全失敗才用）
  StockTrendModel _getMockStockTrends() {
    final now = DateTime.now();
    final dates = List.generate(30,
        (i) => now.subtract(Duration(days: 29 - i)).toString().substring(0, 10));
    return StockTrendModel(dates: dates, stocks: [
      StockModel(id: 1, symbol: '2330', name: '台積電', isActive: true,
          currentPrice: 935.0, changePercent: 1.2, prices: []),
      StockModel(id: 2, symbol: '2317', name: '鴻海', isActive: true,
          currentPrice: 185.5, changePercent: -0.5, prices: []),
      StockModel(id: 3, symbol: '2454', name: '聯發科', isActive: true,
          currentPrice: 1285.0, changePercent: 0.8, prices: []),
    ]);
  }
}
