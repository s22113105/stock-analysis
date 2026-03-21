// ============================================
// C2. Chart View - 股價與波動率折線圖
// 對應 StockController & VolatilityController
// ============================================

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:fl_chart/fl_chart.dart';
import '../../controllers/chart_controller.dart';
import '../../widgets/bottom_nav_widget.dart';

class ChartView extends GetView<ChartController> {
  const ChartView({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF0A0E1A),
      body: SafeArea(
        child: Column(
          children: [
            _buildHeader(),
            _buildStockSelector(),
            _buildChartTypeSelector(),
            Expanded(
              child: Obx(() {
                if (controller.isLoading.value) {
                  return const Center(
                    child: CircularProgressIndicator(color: Color(0xFF00D2FF)),
                  );
                }
                return SingleChildScrollView(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    children: [
                      _buildMainChart(),
                      const SizedBox(height: 16),
                      _buildChartLegend(),
                      const SizedBox(height: 16),
                      _buildStockInfoCard(),
                      const SizedBox(height: 80),
                    ],
                  ),
                );
              }),
            ),
          ],
        ),
      ),
      bottomNavigationBar: const BottomNavWidget(currentIndex: 1),
    );
  }

  Widget _buildHeader() {
    return Container(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
      decoration: const BoxDecoration(
        color: Color(0xFF141824),
        border: Border(bottom: BorderSide(color: Color(0xFF1E2536))),
      ),
      child: Row(
        children: [
          const Icon(Icons.show_chart, color: Color(0xFF00D2FF), size: 22),
          const SizedBox(width: 10),
          const Text(
            '圖表分析',
            style: TextStyle(
              color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold,
            ),
          ),
          const Spacer(),
          Obx(() => _buildCompactDaysSelector()),
        ],
      ),
    );
  }

  Widget _buildCompactDaysSelector() {
    return Row(
      children: [7, 30, 60].map((days) => GestureDetector(
        onTap: () => controller.changeDays(days),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
          margin: const EdgeInsets.only(left: 4),
          decoration: BoxDecoration(
            color: controller.selectedDays.value == days
                ? const Color(0xFF0066FF)
                : const Color(0xFF0A0E1A),
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: const Color(0xFF1E2536)),
          ),
          child: Text(
            '${days}D',
            style: TextStyle(
              color: controller.selectedDays.value == days
                  ? Colors.white
                  : const Color(0xFF8892A4),
              fontSize: 12,
            ),
          ),
        ),
      )).toList(),
    );
  }

  Widget _buildStockSelector() {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
      color: const Color(0xFF141824),
      child: Obx(() => Row(
        children: controller.availableStocks.map((stock) {
          final isSelected = controller.selectedSymbol.value == stock['symbol'];
          return Expanded(
            child: GestureDetector(
              onTap: () => controller.selectStock(stock['symbol']!, stock['name']!),
              child: Container(
                margin: const EdgeInsets.only(right: 6),
                padding: const EdgeInsets.symmetric(vertical: 8),
                decoration: BoxDecoration(
                  gradient: isSelected
                      ? const LinearGradient(
                          colors: [Color(0xFF0066FF), Color(0xFF00D2FF)],
                        )
                      : null,
                  color: isSelected ? null : const Color(0xFF0A0E1A),
                  borderRadius: BorderRadius.circular(10),
                  border: Border.all(
                    color: isSelected ? Colors.transparent : const Color(0xFF1E2536),
                  ),
                ),
                child: Column(
                  children: [
                    Text(
                      stock['symbol']!,
                      style: TextStyle(
                        color: isSelected ? Colors.white : const Color(0xFF8892A4),
                        fontWeight: FontWeight.bold,
                        fontSize: 14,
                      ),
                    ),
                    Text(
                      stock['name']!,
                      style: TextStyle(
                        color: isSelected ? Colors.white70 : const Color(0xFF3D4759),
                        fontSize: 11,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          );
        }).toList(),
      )),
    );
  }

  Widget _buildChartTypeSelector() {
    final types = [
      {'key': 'price', 'label': '股價', 'icon': Icons.show_chart},
      {'key': 'hv', 'label': 'HV', 'icon': Icons.bar_chart},
      {'key': 'iv', 'label': 'IV', 'icon': Icons.stacked_line_chart},
      {'key': 'prediction', 'label': '預測', 'icon': Icons.auto_graph},
    ];
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      color: const Color(0xFF0A0E1A),
      child: Obx(() => Row(
        children: types.map((t) {
          final isSelected = controller.chartType.value == t['key'];
          return Expanded(
            child: GestureDetector(
              onTap: () => controller.changeChartType(t['key'] as String),
              child: Container(
                margin: const EdgeInsets.only(right: 6),
                padding: const EdgeInsets.symmetric(vertical: 7),
                decoration: BoxDecoration(
                  color: isSelected
                      ? const Color(0xFF0066FF)
                      : const Color(0xFF141824),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Column(
                  children: [
                    Icon(
                      t['icon'] as IconData,
                      color: isSelected ? Colors.white : const Color(0xFF8892A4),
                      size: 16,
                    ),
                    const SizedBox(height: 2),
                    Text(
                      t['label'] as String,
                      style: TextStyle(
                        color: isSelected ? Colors.white : const Color(0xFF8892A4),
                        fontSize: 11,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          );
        }).toList(),
      )),
    );
  }

  Widget _buildMainChart() {
    return Container(
      height: 280,
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: const Color(0xFF141824),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFF1E2536)),
      ),
      child: Obx(() {
        final type = controller.chartType.value;
        if (type == 'price') return _buildPriceLineChart();
        if (type == 'hv' || type == 'iv') return _buildVolatilityChart();
        if (type == 'prediction') return _buildPredictionChart();
        return _buildPriceLineChart();
      }),
    );
  }

  Widget _buildPriceLineChart() {
    final prices = controller.priceData;
    if (prices.isEmpty) {
      return const Center(
        child: Text('無資料', style: TextStyle(color: Color(0xFF8892A4))),
      );
    }
    final spots = prices.asMap().entries
        .map((e) => FlSpot(e.key.toDouble(), e.value.closePrice))
        .toList();
    final minY = prices.map((p) => p.closePrice).reduce((a, b) => a < b ? a : b);
    final maxY = prices.map((p) => p.closePrice).reduce((a, b) => a > b ? a : b);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Obx(() => Text(
          '${controller.selectedStockName.value} (${controller.selectedSymbol.value}) - 收盤價',
          style: const TextStyle(
            color: Colors.white, fontSize: 13, fontWeight: FontWeight.w600,
          ),
        )),
        const SizedBox(height: 12),
        Expanded(
          child: LineChart(LineChartData(
            gridData: FlGridData(
              show: true,
              getDrawingHorizontalLine: (_) =>
                  const FlLine(color: Color(0xFF1E2536), strokeWidth: 1),
              getDrawingVerticalLine: (_) =>
                  const FlLine(color: Color(0xFF1E2536), strokeWidth: 0.5),
            ),
            titlesData: FlTitlesData(
              leftTitles: AxisTitles(
                sideTitles: SideTitles(
                  showTitles: true,
                  reservedSize: 56,
                  getTitlesWidget: (value, _) => Text(
                    value.toStringAsFixed(0),
                    style: const TextStyle(color: Color(0xFF8892A4), fontSize: 10),
                  ),
                ),
              ),
              bottomTitles: AxisTitles(
                sideTitles: SideTitles(
                  showTitles: true,
                  interval: (prices.length / 4).ceil().toDouble(),
                  getTitlesWidget: (value, _) {
                    final idx = value.toInt();
                    if (idx >= 0 && idx < prices.length) {
                      return Text(
                        prices[idx].tradeDate.substring(5),
                        style: const TextStyle(color: Color(0xFF8892A4), fontSize: 9),
                      );
                    }
                    return const SizedBox.shrink();
                  },
                ),
              ),
              topTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
              rightTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
            ),
            borderData: FlBorderData(show: false),
            minY: minY * 0.995,
            maxY: maxY * 1.005,
            lineBarsData: [
              LineChartBarData(
                spots: spots,
                isCurved: true,
                color: const Color(0xFF00D2FF),
                barWidth: 2,
                dotData: const FlDotData(show: false),
                belowBarData: BarAreaData(
                  show: true,
                  gradient: LinearGradient(
                    colors: [
                      // ✅ 修改：withOpacity → withValues
                      const Color(0xFF00D2FF).withValues(alpha: 0.3),
                      const Color(0xFF00D2FF).withValues(alpha: 0.0),
                    ],
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                  ),
                ),
              ),
            ],
          )),
        ),
      ],
    );
  }

  Widget _buildVolatilityChart() {
    final isHv = controller.chartType.value == 'hv';
    final data = isHv ? controller.hvData : controller.ivData;
    if (data.isEmpty) {
      return Center(
        child: ElevatedButton(
          onPressed: controller.loadVolatility,
          style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFF0066FF)),
          child: const Text('載入波動率'),
        ),
      );
    }
    final spots = data.asMap().entries
        .map((e) => FlSpot(e.key.toDouble(), e.value))
        .toList();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          isHv ? '歷史波動率 (HV) %' : '隱含波動率 (IV) %',
          style: const TextStyle(
            color: Colors.white, fontSize: 13, fontWeight: FontWeight.w600,
          ),
        ),
        const SizedBox(height: 12),
        Expanded(
          child: LineChart(LineChartData(
            gridData: FlGridData(
              show: true,
              getDrawingHorizontalLine: (_) =>
                  const FlLine(color: Color(0xFF1E2536), strokeWidth: 1),
            ),
            titlesData: FlTitlesData(
              leftTitles: AxisTitles(
                sideTitles: SideTitles(
                  showTitles: true,
                  reservedSize: 40,
                  getTitlesWidget: (v, _) => Text(
                    '${v.toStringAsFixed(1)}%',
                    style: const TextStyle(color: Color(0xFF8892A4), fontSize: 10),
                  ),
                ),
              ),
              bottomTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
              topTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
              rightTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
            ),
            borderData: FlBorderData(show: false),
            lineBarsData: [
              LineChartBarData(
                spots: spots,
                isCurved: true,
                color: isHv ? const Color(0xFF2ECC71) : const Color(0xFFFF6B35),
                barWidth: 2.5,
                dotData: const FlDotData(show: false),
                belowBarData: BarAreaData(
                  show: true,
                  // ✅ 修改：withOpacity → withValues
                  color: (isHv
                      ? const Color(0xFF2ECC71)
                      : const Color(0xFFFF6B35))
                      .withValues(alpha: 0.15),
                ),
              ),
            ],
          )),
        ),
      ],
    );
  }

  Widget _buildPredictionChart() {
    final predData = controller.predictionData.value;
    if (predData == null) {
      return Center(
        child: ElevatedButton(
          onPressed: controller.loadPrediction,
          style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFF0066FF)),
          child: const Text('載入預測'),
        ),
      );
    }
    final predicted = (predData['predicted_prices'] as List<dynamic>?)
            ?.map((v) => (v as num).toDouble())
            .toList() ?? [];
    final upper = (predData['confidence_upper'] as List<dynamic>?)
            ?.map((v) => (v as num).toDouble())
            .toList() ?? [];
    final lower = (predData['confidence_lower'] as List<dynamic>?)
            ?.map((v) => (v as num).toDouble())
            .toList() ?? [];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text(
          'ARIMA 預測 (未來 7 天)',
          style: TextStyle(color: Colors.white, fontSize: 13, fontWeight: FontWeight.w600),
        ),
        const SizedBox(height: 12),
        Expanded(
          child: LineChart(LineChartData(
            gridData: FlGridData(
              show: true,
              getDrawingHorizontalLine: (_) =>
                  const FlLine(color: Color(0xFF1E2536), strokeWidth: 1),
            ),
            titlesData: FlTitlesData(
              leftTitles: AxisTitles(
                sideTitles: SideTitles(
                  showTitles: true,
                  reservedSize: 56,
                  getTitlesWidget: (v, _) => Text(
                    v.toStringAsFixed(0),
                    style: const TextStyle(color: Color(0xFF8892A4), fontSize: 10),
                  ),
                ),
              ),
              bottomTitles: AxisTitles(
                sideTitles: SideTitles(
                  showTitles: true,
                  getTitlesWidget: (v, _) => Text(
                    '+${v.toInt() + 1}天',
                    style: const TextStyle(color: Color(0xFF8892A4), fontSize: 9),
                  ),
                ),
              ),
              topTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
              rightTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
            ),
            borderData: FlBorderData(show: false),
            lineBarsData: [
              LineChartBarData(
                spots: predicted.asMap().entries
                    .map((e) => FlSpot(e.key.toDouble(), e.value))
                    .toList(),
                isCurved: true,
                color: const Color(0xFFFFC300),
                barWidth: 2.5,
                dotData: const FlDotData(show: true),
                dashArray: [6, 3],
              ),
              if (upper.isNotEmpty)
                LineChartBarData(
                  spots: upper.asMap().entries
                      .map((e) => FlSpot(e.key.toDouble(), e.value))
                      .toList(),
                  isCurved: true,
                  // ✅ 修改：withOpacity → withValues
                  color: const Color(0xFF0066FF).withValues(alpha: 0.5),
                  barWidth: 1,
                  dotData: const FlDotData(show: false),
                  dashArray: [4, 4],
                ),
              if (lower.isNotEmpty)
                LineChartBarData(
                  spots: lower.asMap().entries
                      .map((e) => FlSpot(e.key.toDouble(), e.value))
                      .toList(),
                  isCurved: true,
                  // ✅ 修改：withOpacity → withValues
                  color: const Color(0xFF0066FF).withValues(alpha: 0.5),
                  barWidth: 1,
                  dotData: const FlDotData(show: false),
                  dashArray: [4, 4],
                  belowBarData: BarAreaData(
                    show: true,
                    // ✅ 修改：withOpacity → withValues
                    color: const Color(0xFF0066FF).withValues(alpha: 0.08),
                  ),
                ),
            ],
          )),
        ),
      ],
    );
  }

  Widget _buildChartLegend() {
    return Obx(() {
      final type = controller.chartType.value;
      if (type == 'price') {
        return _buildLegendRow([
          {'color': const Color(0xFF00D2FF), 'label': '收盤價'},
        ]);
      } else if (type == 'hv') {
        return _buildLegendRow([
          {'color': const Color(0xFF2ECC71), 'label': '歷史波動率 (HV)'},
        ]);
      } else if (type == 'iv') {
        return _buildLegendRow([
          {'color': const Color(0xFFFF6B35), 'label': '隱含波動率 (IV)'},
        ]);
      } else {
        return _buildLegendRow([
          {'color': const Color(0xFFFFC300), 'label': '預測價格'},
          {'color': const Color(0xFF0066FF), 'label': '信賴區間'},
        ]);
      }
    });
  }

  Widget _buildLegendRow(List<Map<String, dynamic>> items) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.center,
      children: items.map((item) => Padding(
        padding: const EdgeInsets.symmetric(horizontal: 10),
        child: Row(
          children: [
            Container(width: 16, height: 3, color: item['color'] as Color),
            const SizedBox(width: 6),
            Text(
              item['label'] as String,
              style: const TextStyle(color: Color(0xFF8892A4), fontSize: 12),
            ),
          ],
        ),
      )).toList(),
    );
  }

  Widget _buildStockInfoCard() {
    return Obx(() {
      final prices = controller.priceData;
      if (prices.isEmpty) return const SizedBox.shrink();
      final latest = prices.last;
      final prev = prices.length > 1 ? prices[prices.length - 2] : prices.last;
      final change = latest.closePrice - prev.closePrice;
      final changePct = prev.closePrice > 0 ? (change / prev.closePrice * 100) : 0.0;
      final isUp = change >= 0;

      return Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: const Color(0xFF141824),
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: const Color(0xFF1E2536)),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Text(
                  controller.selectedStockName.value,
                  style: const TextStyle(
                    color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold,
                  ),
                ),
                const SizedBox(width: 8),
                Text(
                  controller.selectedSymbol.value,
                  style: const TextStyle(color: Color(0xFF8892A4), fontSize: 13),
                ),
                const Spacer(),
                Text(
                  latest.closePrice.toStringAsFixed(1),
                  style: const TextStyle(
                    color: Colors.white, fontSize: 22, fontWeight: FontWeight.bold,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Row(
              children: [
                Icon(
                  isUp ? Icons.arrow_upward : Icons.arrow_downward,
                  color: isUp ? const Color(0xFF2ECC71) : const Color(0xFFFF4757),
                  size: 14,
                ),
                Text(
                  ' ${change >= 0 ? '+' : ''}${change.toStringAsFixed(1)} (${changePct.toStringAsFixed(2)}%)',
                  style: TextStyle(
                    color: isUp ? const Color(0xFF2ECC71) : const Color(0xFFFF4757),
                    fontSize: 13,
                  ),
                ),
                const Spacer(),
                Text(
                  '成交量: ${(latest.volume / 1000000).toStringAsFixed(1)}M',
                  style: const TextStyle(color: Color(0xFF8892A4), fontSize: 12),
                ),
              ],
            ),
            const SizedBox(height: 12),
            Row(
              children: [
                _buildInfoItem('開盤', latest.openPrice.toStringAsFixed(1)),
                _buildInfoItem('最高', latest.highPrice.toStringAsFixed(1)),
                _buildInfoItem('最低', latest.lowPrice.toStringAsFixed(1)),
                _buildInfoItem('日期', latest.tradeDate.substring(5)),
              ],
            ),
          ],
        ),
      );
    });
  }

  Widget _buildInfoItem(String label, String value) {
    return Expanded(
      child: Column(
        children: [
          Text(label, style: const TextStyle(color: Color(0xFF8892A4), fontSize: 11)),
          const SizedBox(height: 2),
          Text(
            value,
            style: const TextStyle(
              color: Colors.white, fontSize: 13, fontWeight: FontWeight.w600,
            ),
          ),
        ],
      ),
    );
  }
}
