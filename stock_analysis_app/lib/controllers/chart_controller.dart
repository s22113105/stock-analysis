import 'package:get/get.dart';
import '../services/api_service.dart';
import '../models/stock_model.dart';

class ChartController extends GetxController {
  final ApiService _apiService = Get.find<ApiService>();

  final RxString selectedSymbol = '2330'.obs;
  final RxString selectedStockName = '台積電'.obs;
  final RxInt selectedStockId = 1.obs;

  // 圖表類型：price / hv / iv（移除 prediction）
  final RxString chartType = 'price'.obs;
  final RxInt selectedDays = 30.obs;
  final RxBool isLoading = false.obs;
  final RxBool isLoadingStocks = false.obs;

  final RxList<StockPriceModel> priceData = <StockPriceModel>[].obs;
  final RxList<String> dateLabels = <String>[].obs;

  // HV 真實數值
  final RxDouble hvValue = 0.0.obs;
  final RxString hvPercentage = ''.obs;
  final RxDouble rvValue = 0.0.obs;
  final RxString rvPercentage = ''.obs;
  final RxBool hvLoaded = false.obs;

  // IV 數值（只有 2330/2317/2454）
  final RxDouble ivValue = 0.0.obs;
  final RxString ivPercentage = ''.obs;
  final RxBool ivLoaded = false.obs;
  final RxBool ivAvailable = false.obs;

  // 全部股票清單
  final RxList<Map<String, dynamic>> availableStocks =
      <Map<String, dynamic>>[].obs;

  // 有 IV 資料的股票（對應 TARGET_STOCKS）
  static const List<String> ivSupportedSymbols = ['2330', '2317', '2454'];

  @override
  void onInit() {
    super.onInit();
    loadAllStocks();
  }

  // ==========================================
  // 載入全部股票
  // ==========================================
  Future<void> loadAllStocks() async {
    isLoadingStocks.value = true;
    try {
      final response = await _apiService.getStocks(perPage: 100);
      final raw = response['data'];

      List<dynamic> list = [];
      if (raw is Map && raw['data'] is List) {
        list = raw['data'];
      } else if (raw is List) {
        list = raw;
      }

      if (list.isNotEmpty) {
        availableStocks.value = list
            .where((s) => s['symbol'] != null)
            .map((s) => {
                  'symbol': s['symbol'].toString(),
                  'name': s['name']?.toString() ?? s['symbol'].toString(),
                  'id': s['id'] ?? 0,
                })
            .toList();

        selectedSymbol.value = availableStocks[0]['symbol'];
        selectedStockName.value = availableStocks[0]['name'];
        selectedStockId.value = availableStocks[0]['id'];
        loadChartData();
      } else {
        _setDefaultStocks();
      }
    } catch (e) {
      _setDefaultStocks();
    } finally {
      isLoadingStocks.value = false;
    }
  }

  void _setDefaultStocks() {
    availableStocks.value = [
      {'symbol': '2330', 'name': '台積電', 'id': 1},
      {'symbol': '2317', 'name': '鴻海', 'id': 2},
      {'symbol': '2454', 'name': '聯發科', 'id': 3},
    ];
    loadChartData();
  }

  // 切換股票
  void selectStock(String symbol, String name, int id) {
    selectedSymbol.value = symbol;
    selectedStockName.value = name;
    selectedStockId.value = id;
    hvLoaded.value = false;
    ivLoaded.value = false;
    ivAvailable.value = false;
    // 切換回股價頁
    chartType.value = 'price';
    loadChartData();
  }

  // 切換圖表類型
  void changeChartType(String type) {
    chartType.value = type;
    if (type == 'hv' && !hvLoaded.value) loadHV();
    if (type == 'iv' && !ivLoaded.value) loadIV();
  }

  // 切換期間
  void changeDays(int days) {
    selectedDays.value = days;
    hvLoaded.value = false;
    ivLoaded.value = false;
    loadChartData();
    if (chartType.value == 'hv') loadHV();
    if (chartType.value == 'iv') loadIV();
  }

  // ==========================================
  // 載入股價
  // GET /api/stocks/{id}/prices?period=1m
  // ==========================================
  Future<void> loadChartData() async {
    isLoading.value = true;
    try {
      final response = await _apiService.getStockPricesById(
        selectedStockId.value,
        days: selectedDays.value,
      );

      final dataWrap = response['data'] as Map<String, dynamic>? ?? {};
      final List<dynamic> list =
          dataWrap['prices'] as List<dynamic>? ?? [];

      if (list.isNotEmpty) {
        priceData.value = list.map((p) {
          final tradeDate = p['trade_date']?.toString() ?? '';
          final dateStr = tradeDate.length >= 10
              ? tradeDate.substring(0, 10)
              : tradeDate;
          return StockPriceModel(
            id: p['id'] ?? 0,
            stockId: p['stock_id'] ?? 0,
            tradeDate: dateStr,
            openPrice: _toDouble(p['open']),
            highPrice: _toDouble(p['high']),
            lowPrice: _toDouble(p['low']),
            closePrice: _toDouble(p['close']),
            volume: _toInt(p['volume']),
          );
        }).toList();
        dateLabels.value = priceData.map((p) => p.tradeDate).toList();
      } else {
        priceData.value = [];
        dateLabels.value = [];
      }
    } catch (e) {
      priceData.value = [];
      dateLabels.value = [];
    } finally {
      isLoading.value = false;
    }
  }

  // ==========================================
  // 載入 HV（真實計算）
  // GET /api/volatility/historical/{stockId}?period=30
  // 回傳: {data: {historical_volatility: 0.185, ...}}
  // ==========================================
  Future<void> loadHV() async {
    isLoading.value = true;
    hvLoaded.value = false;
    try {
      final response = await _apiService.getHistoricalVolatility(
        selectedStockId.value,
        period: selectedDays.value,
      );
      final data = response['data'] ?? {};

      // historical_volatility 是小數（0.185 = 18.5%）
      final hv = _toDouble(data['historical_volatility']);
      final rv = _toDouble(data['realized_volatility']);

      hvValue.value = hv * 100; // 轉成百分比
      hvPercentage.value =
          data['historical_volatility_percentage']?.toString() ??
              '${(hv * 100).toStringAsFixed(2)}%';

      rvValue.value = rv * 100;
      rvPercentage.value =
          data['realized_volatility_percentage']?.toString() ??
              (rv > 0 ? '${(rv * 100).toStringAsFixed(2)}%' : '無資料');

      hvLoaded.value = hvValue.value > 0;
    } catch (e) {
      hvValue.value = 0;
      hvPercentage.value = '計算失敗';
      hvLoaded.value = false;
    } finally {
      isLoading.value = false;
    }
  }

  // ==========================================
  // 載入 IV
  // 從 /api/dashboard/volatility-overview 取得
  // 只有 2330/2317/2454 有 IV 資料
  // ==========================================
  Future<void> loadIV() async {
    isLoading.value = true;
    ivLoaded.value = false;

    // 檢查是否支援 IV
    if (!ivSupportedSymbols.contains(selectedSymbol.value)) {
      ivAvailable.value = false;
      ivLoaded.value = true;
      isLoading.value = false;
      return;
    }

    try {
      final response = await _apiService.getVolatilityOverview();
      final raw = response['data'] ?? {};
      final list = raw['volatilities'] as List<dynamic>? ?? [];
      final stockData = list.firstWhereOrNull(
          (v) => v['symbol'] == selectedSymbol.value);

      if (stockData != null) {
        final iv = _toDouble(stockData['iv']);
        final hv = _toDouble(stockData['hv']);
        ivValue.value = iv;
        ivPercentage.value = '${iv.toStringAsFixed(2)}%';
        // 也更新 HV（如果還沒載入）
        if (!hvLoaded.value) {
          hvValue.value = hv;
          hvPercentage.value = '${hv.toStringAsFixed(2)}%';
        }
        ivAvailable.value = iv > 0;
      } else {
        ivAvailable.value = false;
      }
      ivLoaded.value = true;
    } catch (e) {
      ivAvailable.value = false;
      ivLoaded.value = true;
    } finally {
      isLoading.value = false;
    }
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
