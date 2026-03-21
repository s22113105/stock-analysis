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
  final RxList<VolatilityOverviewModel> volatilityList = <VolatilityOverviewModel>[].obs;
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

  Future<void> loadStats() async {
    isLoadingStats.value = true;
    try {
      final response = await _apiService.getDashboardStats();
      stats.value = DashboardStatsModel.fromJson(response);
    } catch (e) {
      stats.value = DashboardStatsModel(
        totalStocks: 3, activeStocks: 3,
        totalOptions: 150, activeOptions: 142,
        latestUpdate: DateTime.now().toString(),
        targetStocks: ['2330', '2317', '2454'],
      );
    } finally {
      isLoadingStats.value = false;
    }
  }

  Future<void> loadStockTrends() async {
    isLoadingTrends.value = true;
    try {
      final response = await _apiService.getStockTrends(days: selectedDays.value);
      stockTrends.value = StockTrendModel.fromJson(response);
    } catch (e) {
      stockTrends.value = _getMockStockTrends();
    } finally {
      isLoadingTrends.value = false;
    }
  }

  Future<void> loadVolatilityOverview() async {
    isLoadingVolatility.value = true;
    try {
      final response = await _apiService.getVolatilityOverview();
      final data = response['data'] as List<dynamic>? ?? [];
      volatilityList.value = data.map((v) => VolatilityOverviewModel.fromJson(v)).toList();
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

  Future<void> loadAlerts() async {
    try {
      final response = await _apiService.getDashboardAlerts();
      final data = response['data'];
      final alertList = data?['alerts'] as List<dynamic>? ?? [];
      alerts.value = alertList.map((a) => AlertModel.fromJson(a)).toList();
    } catch (e) {
      alerts.value = [];
    }
  }

  void changeDays(int days) {
    selectedDays.value = days;
    loadStockTrends();
  }

  StockTrendModel _getMockStockTrends() {
    final now = DateTime.now();
    final dates = List.generate(30,
        (i) => now.subtract(Duration(days: 29 - i)).toString().substring(0, 10));
    return StockTrendModel(
      dates: dates,
      stocks: [
        StockModel(
          id: 1, symbol: '2330', name: '台積電', isActive: true,
          currentPrice: 935.0, changePercent: 1.2,
          prices: List.generate(30, (i) => StockPriceModel(
            id: i, stockId: 1, tradeDate: dates[i],
            openPrice: 920 + i * 0.5, highPrice: 940 + i * 0.5,
            lowPrice: 915 + i * 0.5, closePrice: 930 + i * 0.5,
            volume: 25000000,
          )),
        ),
        StockModel(
          id: 2, symbol: '2317', name: '鴻海', isActive: true,
          currentPrice: 185.5, changePercent: -0.5,
          prices: List.generate(30, (i) => StockPriceModel(
            id: i, stockId: 2, tradeDate: dates[i],
            openPrice: 182 + i * 0.1, highPrice: 188 + i * 0.1,
            lowPrice: 180 + i * 0.1, closePrice: 185 + i * 0.1,
            volume: 40000000,
          )),
        ),
        StockModel(
          id: 3, symbol: '2454', name: '聯發科', isActive: true,
          currentPrice: 1285.0, changePercent: 0.8,
          prices: List.generate(30, (i) => StockPriceModel(
            id: i, stockId: 3, tradeDate: dates[i],
            openPrice: 1260 + i * 1.0, highPrice: 1295 + i * 1.0,
            lowPrice: 1250 + i * 1.0, closePrice: 1280 + i * 1.0,
            volume: 8000000,
          )),
        ),
      ],
    );
  }
}
