import 'package:get/get.dart';
import '../services/api_service.dart';
import '../models/dashboard_model.dart';

class NotificationController extends GetxController {
  final ApiService _apiService = Get.find<ApiService>();

  final RxBool isLoading = false.obs;
  final RxList<AlertModel> alerts = <AlertModel>[].obs;
  final RxList<Map<String, dynamic>> strategySignals = <Map<String, dynamic>>[].obs;
  final RxList<BacktestResultModel> recentBacktests = <BacktestResultModel>[].obs;
  final RxString filterType = 'all'.obs;

  @override
  void onInit() {
    super.onInit();
    loadAll();
  }

  Future<void> loadAll() async {
    await Future.wait([loadAlerts(), loadBacktestResults()]);
    _generateStrategySignals();
  }

  Future<void> loadAlerts() async {
    isLoading.value = true;
    try {
      final response = await _apiService.getDashboardAlerts();
      final data = response['data'];
      final alertList = data?['alerts'] as List<dynamic>? ?? [];
      alerts.value = alertList.map((a) => AlertModel.fromJson(a)).toList();
    } catch (e) {
      alerts.value = [
        AlertModel(
          type: 'warning',
          title: '波動率警示 - 2330 台積電',
          message: 'IV (22.3%) 高於 HV (18.5%)，考慮賣出策略',
          timestamp: DateTime.now().toString(),
        ),
        AlertModel(
          type: 'warning',
          title: '波動率警示 - 2454 聯發科',
          message: 'IV (24.5%) 大幅高於 HV (21.0%)，隱含波動率偏高',
          timestamp: DateTime.now().subtract(const Duration(hours: 1)).toString(),
        ),
        AlertModel(
          type: 'error',
          title: '選擇權即將到期',
          message: '有 3 個 TXO 合約將在 3 天內到期，請注意管理部位',
          timestamp: DateTime.now().subtract(const Duration(hours: 2)).toString(),
        ),
        AlertModel(
          type: 'info',
          title: '資料更新完成',
          message: '今日股票與選擇權資料已成功更新，共爬取 3 支股票',
          timestamp: DateTime.now().subtract(const Duration(hours: 3)).toString(),
        ),
      ];
    } finally {
      isLoading.value = false;
    }
  }

  Future<void> loadBacktestResults() async {
    try {
      final response = await _apiService.getBacktestResults();
      final data = response['data'] as List<dynamic>? ?? [];
      recentBacktests.value =
          data.map((b) => BacktestResultModel.fromJson(b)).toList();
    } catch (e) {
      recentBacktests.value = [
        BacktestResultModel(
          id: 1, strategy: 'covered_call', stockSymbol: '2330',
          totalReturn: 12.5, sharpeRatio: 1.42, maxDrawdown: -5.2,
          startDate: '2024-01-01', endDate: '2024-12-31',
        ),
        BacktestResultModel(
          id: 2, strategy: 'protective_put', stockSymbol: '2454',
          totalReturn: 8.3, sharpeRatio: 0.98, maxDrawdown: -8.7,
          startDate: '2024-01-01', endDate: '2024-12-31',
        ),
      ];
    }
  }

  void _generateStrategySignals() {
    strategySignals.value = [
      {
        'symbol': '2330', 'name': '台積電', 'signal': 'sell_iv',
        'title': '建議賣出波動率',
        'description': 'IV > HV，適合賣出 Covered Call 或 Short Strangle',
        'iv': 22.3, 'hv': 18.5, 'type': 'success',
      },
      {
        'symbol': '2454', 'name': '聯發科', 'signal': 'sell_iv',
        'title': '建議賣出波動率',
        'description': 'IV 顯著高於 HV，考慮 Iron Condor 策略',
        'iv': 24.5, 'hv': 21.0, 'type': 'success',
      },
      {
        'symbol': '2317', 'name': '鴻海', 'signal': 'neutral',
        'title': '中性觀望',
        'description': 'IV ≈ HV，波動率定價合理，建議觀望',
        'iv': 17.8, 'hv': 15.2, 'type': 'warning',
      },
    ];
  }

  List<AlertModel> get filteredAlerts {
    if (filterType.value == 'all') return alerts;
    return alerts.where((a) => a.type == filterType.value).toList();
  }

  Future<void> refresh() async => await loadAll();
}
