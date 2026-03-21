import 'package:get/get.dart';
import '../services/api_service.dart';
import '../models/stock_model.dart';

class ChartController extends GetxController {
  final ApiService _apiService = Get.find<ApiService>();

  final RxString selectedSymbol = '2330'.obs;
  final RxString selectedStockName = '台積電'.obs;
  final RxInt selectedStockId = 1.obs;

  final RxString chartType = 'price'.obs;
  final RxInt selectedDays = 30.obs;
  final RxBool isLoading = false.obs;
  final RxBool isLoadingStocks = false.obs;

  final RxList<StockPriceModel> priceData = <StockPriceModel>[].obs;
  final RxList<double> hvData = <double>[].obs;
  final RxList<double> ivData = <double>[].obs;
  final RxList<String> dateLabels = <String>[].obs;
  final Rxn<Map<String, dynamic>> predictionData = Rxn<Map<String, dynamic>>();

  // 全部股票清單
  final RxList<Map<String, dynamic>> availableStocks =
      <Map<String, dynamic>>[].obs;

  @override
  void onInit() {
    super.onInit();
    loadAllStocks();
  }

  // ==========================================
  // 載入全部股票 GET /api/stocks
  // ==========================================
  Future<void> loadAllStocks() async {
    isLoadingStocks.value = true;
    try {
      final response = await _apiService.getStocks(perPage: 100);
      final raw = response['data'];

      List<dynamic> list = [];
      if (raw is Map && raw['data'] is List) {
        list = raw['data'];       // 分頁格式
      } else if (raw is List) {
        list = raw;               // 直接陣列
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

        // 預設選第一支
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
    hvData.clear();
    ivData.clear();
    predictionData.value = null;
    loadChartData();
  }

  // 切換圖表類型
  void changeChartType(String type) {
    chartType.value = type;
    if (type == 'prediction') loadPrediction();
    if (type == 'hv') loadHV();
    if (type == 'iv') loadIV();
  }

  // 切換期間
  void changeDays(int days) {
    selectedDays.value = days;
    loadChartData();
  }

  // ==========================================
  // ✅ 修正：載入股價
  // GET /api/stocks/{symbol}/prices
  // StockPrice 欄位: open, high, low, close, trade_date
  // ==========================================
  Future<void> loadChartData() async {
    isLoading.value = true;
    try {
      final response = await _apiService.getStockPrices(
        selectedSymbol.value,
        days: selectedDays.value,
      );

      final raw = response['data'];
      List<dynamic> list = [];
      if (raw is Map && raw['data'] is List) {
        list = raw['data'];
      } else if (raw is List) {
        list = raw;
      }

      if (list.isNotEmpty) {
        // API 回傳降序，反轉為升序（舊→新）
        final sorted = list.reversed.toList();

        priceData.value = sorted.map((p) {
          // ✅ 正確欄位名稱: open, high, low, close, trade_date
          final tradeDate = p['trade_date']?.toString() ?? '';
          // trade_date 可能是 "2026-03-20T00:00:00.000000Z"，只取前10碼
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
        // 沒資料才用 mock
        priceData.value = _getMockPrices();
        dateLabels.value = _getMockDates();
      }
    } catch (e) {
      priceData.value = _getMockPrices();
      dateLabels.value = _getMockDates();
    } finally {
      isLoading.value = false;
    }
  }

  // ==========================================
  // 載入 HV - GET /api/volatility/historical/{stockId}
  // ==========================================
  Future<void> loadHV() async {
    isLoading.value = true;
    try {
      final response = await _apiService.getHistoricalVolatility(
        selectedStockId.value,
        period: selectedDays.value,
      );
      final data = response['data'] ?? {};

      final hvSeries = data['hv_series'] as List<dynamic>?;
      if (hvSeries != null && hvSeries.isNotEmpty) {
        hvData.value = hvSeries.map((v) => _toDouble(v)).toList();
      } else {
        final hv = _toDouble(data['historical_volatility'] ?? data['hv']);
        hvData.value = hv > 0
            ? List.generate(selectedDays.value, (_) => hv * 100)
            : _getMockVolatility(18.5);
      }
    } catch (e) {
      hvData.value = _getMockVolatility(18.5);
    } finally {
      isLoading.value = false;
    }
  }

  // ==========================================
  // 載入 IV - 從 volatility-overview
  // ==========================================
  Future<void> loadIV() async {
    isLoading.value = true;
    try {
      final response = await _apiService.getVolatilityOverview();
      final raw = response['data'];
      final list = raw is List ? raw : [];
      final stockData = list.firstWhereOrNull(
          (v) => v['symbol'] == selectedSymbol.value);

      if (stockData != null) {
        final iv = _toDouble(stockData['iv']);
        ivData.value = iv > 0
            ? List.generate(selectedDays.value, (_) => iv)
            : _getMockVolatility(22.3);
      } else {
        ivData.value = _getMockVolatility(22.3);
      }
    } catch (e) {
      ivData.value = _getMockVolatility(22.3);
    } finally {
      isLoading.value = false;
    }
  }

  // 舊版相容（chart_view 呼叫到）
  Future<void> loadVolatility() async {
    if (chartType.value == 'hv') {
      await loadHV();
    } else {
      await loadIV();
    }
  }

  // ==========================================
  // 載入預測
  // ==========================================
  Future<void> loadPrediction() async {
    isLoading.value = true;
    try {
      final response = await _apiService.runPrediction({
        'stock_symbol': selectedSymbol.value,
        'model_type': 'arima',
        'prediction_days': 7,
      });
      predictionData.value = response['data'] != null
          ? Map<String, dynamic>.from(response['data'])
          : _getMockPrediction();
    } catch (e) {
      predictionData.value = _getMockPrediction();
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

  List<StockPriceModel> _getMockPrices() {
    final base = selectedSymbol.value == '2330'
        ? 930.0
        : selectedSymbol.value == '2454'
            ? 1280.0
            : 185.0;
    return List.generate(selectedDays.value, (i) {
      final p = base + (i % 5 - 2) * base * 0.01;
      return StockPriceModel(
        id: i, stockId: 1,
        tradeDate: DateTime.now()
            .subtract(Duration(days: selectedDays.value - i - 1))
            .toString().substring(0, 10),
        openPrice: p - 2, highPrice: p + 5,
        lowPrice: p - 5, closePrice: p,
        volume: 20000000,
      );
    });
  }

  List<String> _getMockDates() => List.generate(
      selectedDays.value,
      (i) => DateTime.now()
          .subtract(Duration(days: selectedDays.value - i - 1))
          .toString().substring(0, 10));

  List<double> _getMockVolatility(double base) =>
      List.generate(selectedDays.value, (i) => base + (i % 7 - 3) * 0.5);

  Map<String, dynamic> _getMockPrediction() => {
        'predicted_prices': [932.0, 938.5, 945.0, 940.2, 952.0, 958.0, 963.5],
        'confidence_upper': [945.0, 955.0, 965.0, 960.0, 975.0, 982.0, 990.0],
        'confidence_lower': [919.0, 922.0, 925.0, 920.5, 929.0, 934.0, 937.0],
      };
}
