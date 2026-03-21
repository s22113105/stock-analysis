class StockModel {
  final int id;
  final String symbol;
  final String name;
  final bool isActive;
  final double? currentPrice;
  final double? changePercent;
  final List<StockPriceModel> prices;

  StockModel({
    required this.id,
    required this.symbol,
    required this.name,
    required this.isActive,
    this.currentPrice,
    this.changePercent,
    this.prices = const [],
  });

  factory StockModel.fromJson(Map<String, dynamic> json) {
    return StockModel(
      id: json['id'] ?? 0,
      symbol: json['symbol'] ?? '',
      name: json['name'] ?? '',
      isActive: json['is_active'] ?? true,
      currentPrice: (json['current_price'] as num?)?.toDouble(),
      changePercent: (json['change_percent'] as num?)?.toDouble(),
      prices: (json['prices'] as List<dynamic>?)
              ?.map((p) => StockPriceModel.fromJson(p))
              .toList() ??
          [],
    );
  }
}

class StockPriceModel {
  final int id;
  final int stockId;
  final String tradeDate;
  final double openPrice;
  final double highPrice;
  final double lowPrice;
  final double closePrice;
  final int volume;

  StockPriceModel({
    required this.id,
    required this.stockId,
    required this.tradeDate,
    required this.openPrice,
    required this.highPrice,
    required this.lowPrice,
    required this.closePrice,
    required this.volume,
  });

  factory StockPriceModel.fromJson(Map<String, dynamic> json) {
    return StockPriceModel(
      id: json['id'] ?? 0,
      stockId: json['stock_id'] ?? 0,
      tradeDate: json['trade_date'] ?? '',
      openPrice: (json['open_price'] as num?)?.toDouble() ?? 0,
      highPrice: (json['high_price'] as num?)?.toDouble() ?? 0,
      lowPrice: (json['low_price'] as num?)?.toDouble() ?? 0,
      closePrice: (json['close_price'] as num?)?.toDouble() ?? 0,
      volume: json['volume'] ?? 0,
    );
  }
}

class StockTrendModel {
  final List<String> dates;
  final List<StockModel> stocks;

  StockTrendModel({required this.dates, required this.stocks});

  factory StockTrendModel.fromJson(Map<String, dynamic> json) {
    final data = json['data'] ?? json;
    return StockTrendModel(
      dates: List<String>.from(data['dates'] ?? []),
      stocks: (data['stocks'] as List<dynamic>?)
              ?.map((s) => StockModel.fromJson(s))
              .toList() ??
          [],
    );
  }
}
