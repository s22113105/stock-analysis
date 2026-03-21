import 'package:get/get.dart';
import '../services/api_service.dart';
import '../models/stock_model.dart';

class ChartController extends GetxController {
  final ApiService _apiService = Get.find<ApiService>();

  final RxString selectedSymbol = '2330'.obs;
  final RxString selectedStockName = '台積電'.obs;
  final RxString chartType = 'price'.obs;
  final RxInt selectedDays = 30.obs;
  final RxBool isLoading = false.obs;

  final RxList<StockPriceModel> priceData = <StockPriceModel>[].obs;
  final RxList<double> hvData = <double>[].obs;
  final RxList<double> ivData = <double>[].obs;
  final RxList<String> dateLabels = <String>[].obs;
  final Rxn<Map<String, dynamic>> predictionData = Rxn<Map<String, dynamic>>();

  final List<Map<String, String>> availableStocks = [
    {'symbol': '2330', 'name': '台積電'},
    {'symbol': '2317', 'name': '鴻海'},
    {'symbol': '2454', 'name': '聯發科'},
  ];

  @override
  void onInit() {
    super.onInit();
    loadChartData();
  }

  void selectStock(String symbol, String name) {
    selectedSymbol.value = symbol;
    selectedStockName.value = name;
    loadChartData();
  }

  void changeChartType(String type) {
    chartType.value = type;
    if (type == 'prediction') {
      loadPrediction();
    } else if (type == 'hv' || type == 'iv') {
      loadVolatility();
    }
  }

  void changeDays(int days) {
    selectedDays.value = days;
    loadChartData();
  }

  Future<void> loadChartData() async {
    isLoading.value = true;
    try {
      final response = await _apiService.getStockTrends(days: selectedDays.value);
      final trendData = response['data'] ?? response;
      final stocks = trendData['stocks'] as List<dynamic>? ?? [];
      dateLabels.value = List<String>.from(trendData['dates'] ?? []);

      final stockData = stocks.firstWhereOrNull(
        (s) => s['symbol'] == selectedSymbol.value,
      );

      if (stockData != null) {
        priceData.value = (stockData['prices'] as List<dynamic>? ?? [])
            .map((p) => StockPriceModel.fromJson(p))
            .toList();
      } else {
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

  Future<void> loadVolatility() async {
    isLoading.value = true;
    try {
      final response = await _apiService.calculateVolatility({
        'symbol': selectedSymbol.value,
        'period': selectedDays.value,
        'method': 'Close-to-Close',
      });
      final data = response['data'] ?? {};
      hvData.value = List<double>.from(
          (data['hv_series'] as List<dynamic>?)?.map((v) => (v as num).toDouble()) ?? []);
      ivData.value = List<double>.from(
          (data['iv_series'] as List<dynamic>?)?.map((v) => (v as num).toDouble()) ?? []);
      if (hvData.isEmpty) {
        hvData.value = _getMockVolatility(18.5);
        ivData.value = _getMockVolatility(22.3);
      }
    } catch (e) {
      hvData.value = _getMockVolatility(18.5);
      ivData.value = _getMockVolatility(22.3);
    } finally {
      isLoading.value = false;
    }
  }

  Future<void> loadPrediction() async {
    isLoading.value = true;
    try {
      final response = await _apiService.runPrediction({
        'stock_symbol': selectedSymbol.value,
        'model_type': 'arima',
        'prediction_days': 7,
      });
      predictionData.value = response['data'];
    } catch (e) {
      predictionData.value = {
        'predicted_prices': [932.0, 938.5, 945.0, 940.2, 952.0, 958.0, 963.5],
        'confidence_upper': [945.0, 955.0, 965.0, 960.0, 975.0, 982.0, 990.0],
        'confidence_lower': [919.0, 922.0, 925.0, 920.5, 929.0, 934.0, 937.0],
      };
    } finally {
      isLoading.value = false;
    }
  }

  List<StockPriceModel> _getMockPrices() {
    final basePrice = selectedSymbol.value == '2330'
        ? 930.0
        : selectedSymbol.value == '2454'
            ? 1280.0
            : 185.0;
    return List.generate(selectedDays.value, (i) {
      final price = basePrice + (i % 5 - 2) * basePrice * 0.01;
      return StockPriceModel(
        id: i, stockId: 1,
        tradeDate: DateTime.now()
            .subtract(Duration(days: selectedDays.value - i - 1))
            .toString()
            .substring(0, 10),
        openPrice: price - 2, highPrice: price + 5,
        lowPrice: price - 5, closePrice: price,
        volume: 20000000,
      );
    });
  }

  List<String> _getMockDates() {
    return List.generate(
      selectedDays.value,
      (i) => DateTime.now()
          .subtract(Duration(days: selectedDays.value - i - 1))
          .toString()
          .substring(0, 10),
    );
  }

  List<double> _getMockVolatility(double base) {
    return List.generate(
        selectedDays.value, (i) => base + (i % 7 - 3) * 0.5);
  }
}
