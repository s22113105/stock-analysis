class DashboardStatsModel {
  final int totalStocks;
  final int activeStocks;
  final int totalOptions;
  final int activeOptions;
  final String? latestUpdate;
  final List<String> targetStocks;

  DashboardStatsModel({
    required this.totalStocks,
    required this.activeStocks,
    required this.totalOptions,
    required this.activeOptions,
    this.latestUpdate,
    required this.targetStocks,
  });

  factory DashboardStatsModel.fromJson(Map<String, dynamic> json) {
    final data = json['data'] ?? json;
    final stocksData = data['stocks'] ?? {};
    final optionsData = data['options'] ?? {};
    return DashboardStatsModel(
      totalStocks: stocksData['total'] ?? data['total_stocks'] ?? 0,
      activeStocks: stocksData['active'] ?? 0,
      totalOptions: optionsData['total'] ?? data['total_options'] ?? 0,
      activeOptions: optionsData['active'] ?? 0,
      latestUpdate: data['latest_update']?.toString(),
      targetStocks: List<String>.from(data['target_stocks'] ?? []),
    );
  }
}

class AlertModel {
  final String type;
  final String title;
  final String message;
  final String timestamp;

  AlertModel({
    required this.type,
    required this.title,
    required this.message,
    required this.timestamp,
  });

  factory AlertModel.fromJson(Map<String, dynamic> json) {
    return AlertModel(
      type: json['type'] ?? 'info',
      title: json['title'] ?? '',
      message: json['message'] ?? '',
      timestamp: json['timestamp'] ?? '',
    );
  }
}

class VolatilityOverviewModel {
  final String symbol;
  final double? hv;
  final double? iv;
  final String? signal;

  VolatilityOverviewModel({
    required this.symbol,
    this.hv,
    this.iv,
    this.signal,
  });

  factory VolatilityOverviewModel.fromJson(Map<String, dynamic> json) {
    return VolatilityOverviewModel(
      symbol: json['symbol'] ?? '',
      hv: (json['hv'] as num?)?.toDouble(),
      iv: (json['iv'] as num?)?.toDouble(),
      signal: json['signal'],
    );
  }
}

class PredictionModel {
  final int id;
  final String stockSymbol;
  final String modelType;
  final double predictedValue;
  final double? actualValue;
  final String predictionDate;
  final double? accuracy;

  PredictionModel({
    required this.id,
    required this.stockSymbol,
    required this.modelType,
    required this.predictedValue,
    this.actualValue,
    required this.predictionDate,
    this.accuracy,
  });

  factory PredictionModel.fromJson(Map<String, dynamic> json) {
    return PredictionModel(
      id: json['id'] ?? 0,
      stockSymbol: json['stock_symbol'] ?? json['underlying'] ?? '',
      modelType: json['model_type'] ?? '',
      predictedValue: (json['predicted_value'] as num?)?.toDouble() ?? 0,
      actualValue: (json['actual_value'] as num?)?.toDouble(),
      predictionDate: json['prediction_date'] ?? '',
      accuracy: (json['accuracy'] as num?)?.toDouble(),
    );
  }
}

class BacktestResultModel {
  final int id;
  final String strategy;
  final String stockSymbol;
  final double totalReturn;
  final double sharpeRatio;
  final double maxDrawdown;
  final String startDate;
  final String endDate;

  BacktestResultModel({
    required this.id,
    required this.strategy,
    required this.stockSymbol,
    required this.totalReturn,
    required this.sharpeRatio,
    required this.maxDrawdown,
    required this.startDate,
    required this.endDate,
  });

  factory BacktestResultModel.fromJson(Map<String, dynamic> json) {
    return BacktestResultModel(
      id: json['id'] ?? 0,
      strategy: json['strategy'] ?? '',
      stockSymbol: json['stock_symbol'] ?? '',
      totalReturn: (json['total_return'] as num?)?.toDouble() ?? 0,
      sharpeRatio: (json['sharpe_ratio'] as num?)?.toDouble() ?? 0,
      maxDrawdown: (json['max_drawdown'] as num?)?.toDouble() ?? 0,
      startDate: json['start_date'] ?? '',
      endDate: json['end_date'] ?? '',
    );
  }
}
