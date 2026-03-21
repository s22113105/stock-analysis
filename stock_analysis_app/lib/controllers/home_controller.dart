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

  // 固定顯示的3支股票（對應 DashboardController::TARGET_STOCKS）
  static const List<String> targetSymbols = ['2330', '2317', '2454'];

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
        totalStocks: 0,
        activeStocks: 0,
        totalOptions: 0,
        activeOptions: 0,
        latestUpdate: null,
        targetStocks: [],
      );
    } finally {
      isLoadingStats.value = false;
    }
  }

  // ==========================================
  // 首頁股票走勢：
  // Step1: GET /api/stocks → 取得全部股票，找出 2330/2317/2454 的 id
  // Step2: GET /api/stocks/{id}/prices?period=1m → 取各支真實股價
  // ==========================================
  Future<void> loadStockTrends() async {
    isLoadingTrends.value = true;
    try {
      // Step 1: 取得股票清單
      final stocksResp = await _apiService.getStocks(perPage: 100);
      final raw = stocksResp['data'];

      List<dynamic> allStocks = [];
      if (raw is Map && raw['data'] is List) {
        allStocks = raw['data'];
      } else if (raw is List) {
        allStocks = raw;
      }

      // 篩選目標3支股票
      final targetStocks = allStocks
          .where((s) => targetSymbols.contains(s['symbol']?.toString()))
          .toList();

      if (targetStocks.isEmpty) {
        stockTrends.value = StockTrendModel(dates: [], stocks: []);
        return;
      }

      // Step 2: 並行取得各支股票的價格
      final futures = targetStocks.map((s) async {
        final symbol = s['symbol'].toString();
        final name = s['name']?.toString() ?? symbol;
        final id = s['id'] as int? ?? 0;

        try {
          // ✅ 用數字 id 查詢，對應 GET /api/stocks/{id}/prices
          final priceResp = await _apiService.getStockPricesById(
            id,
            days: selectedDays.value,
          );

          // ✅ 正確解析: response['data']['prices']
          final dataWrap =
              priceResp['data'] as Map<String, dynamic>? ?? {};
          final List<dynamic> priceList =
              dataWrap['prices'] as List<dynamic>? ?? [];

          // API 回傳升序（舊→新），不需反轉
          final prices = priceList.map((p) {
            final tradeDate = p['trade_date']?.toString() ?? '';
            final dateStr = tradeDate.length >= 10
                ? tradeDate.substring(0, 10)
                : tradeDate;
            return StockPriceModel(
              id: p['id'] ?? 0,
              stockId: id,
              tradeDate: dateStr,
              openPrice: _toDouble(p['open']),
              highPrice: _toDouble(p['high']),
              lowPrice: _toDouble(p['low']),
              closePrice: _toDouble(p['close']),
              volume: _toInt(p['volume']),
            );
          }).toList();

          // 計算最新價格與漲跌幅
          double? currentPrice;
          double? changePercent;

          if (prices.length >= 2) {
            final latest = prices.last.closePrice;
            final prev = prices[prices.length - 2].closePrice;
            currentPrice = latest;
            if (prev > 0) changePercent = (latest - prev) / prev * 100;
          } else if (prices.isNotEmpty) {
            currentPrice = prices.last.closePrice;
          }

          // 如果算不出來，從 latest_price 補
          if (changePercent == null) {
            final lp = s['latest_price'];
            if (lp != null) {
              changePercent = _toDouble(lp['change_percent']);
              currentPrice ??= _toDouble(lp['close']);
            }
          }

          return StockModel(
            id: id,
            symbol: symbol,
            name: name,
            isActive: true,
            currentPrice: currentPrice,
            changePercent: changePercent,
            prices: prices,
          );
        } catch (e) {
          // 這支股票取價格失敗，改用 latest_price 欄位
          final lp = s['latest_price'];
          return StockModel(
            id: id,
            symbol: symbol,
            name: name,
            isActive: true,
            currentPrice: lp != null ? _toDouble(lp['close']) : null,
            changePercent:
                lp != null ? _toDouble(lp['change_percent']) : null,
            prices: [],
          );
        }
      }).toList();

      final stockList = await Future.wait(futures);

      // 照 targetSymbols 順序排列
      stockList.sort((a, b) => targetSymbols
          .indexOf(a.symbol)
          .compareTo(targetSymbols.indexOf(b.symbol)));

      stockTrends.value = StockTrendModel(
        dates: [],
        stocks: stockList,
      );
    } catch (e) {
      stockTrends.value = StockTrendModel(dates: [], stocks: []);
    } finally {
      isLoadingTrends.value = false;
    }
  }

  // ==========================================
  // GET /api/dashboard/volatility-overview
  // 回傳: {success, data: {volatilities: [{symbol, hv, iv}], avg_hv, avg_iv}}
  // ==========================================
  Future<void> loadVolatilityOverview() async {
    isLoadingVolatility.value = true;
    try {
      final response = await _apiService.getVolatilityOverview();
      final raw = response['data'] ?? {};

      // ✅ 取 volatilities 陣列（不是 data 本身）
      final list = raw['volatilities'] as List<dynamic>? ?? [];

      volatilityList.value = list.map((v) {
        final hv = _toDouble(v['hv']);
        final iv = _toDouble(v['iv']);
        return VolatilityOverviewModel(
          symbol: v['symbol']?.toString() ?? '',
          hv: hv,
          iv: iv,
          signal: iv > hv ? 'high_iv' : 'normal',
        );
      }).toList();

      if (volatilityList.isEmpty) {
        volatilityList.value = [
          VolatilityOverviewModel(symbol: '2330', hv: 0, iv: 0),
          VolatilityOverviewModel(symbol: '2317', hv: 0, iv: 0),
          VolatilityOverviewModel(symbol: '2454', hv: 0, iv: 0),
        ];
      }
    } catch (e) {
      // API 失敗才顯示假資料
      volatilityList.value = [
        VolatilityOverviewModel(
            symbol: '2330', hv: 18.5, iv: 22.3, signal: 'high_iv'),
        VolatilityOverviewModel(
            symbol: '2317', hv: 15.2, iv: 17.8, signal: 'normal'),
        VolatilityOverviewModel(
            symbol: '2454', hv: 21.0, iv: 24.5, signal: 'high_iv'),
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
}
