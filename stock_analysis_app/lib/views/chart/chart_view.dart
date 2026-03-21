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
                    child: CircularProgressIndicator(
                        color: Color(0xFF00D2FF)),
                  );
                }
                return SingleChildScrollView(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    children: [
                      _buildContent(),
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
          const Icon(Icons.show_chart,
              color: Color(0xFF00D2FF), size: 22),
          const SizedBox(width: 10),
          const Text(
            '圖表分析',
            style: TextStyle(
              color: Colors.white,
              fontSize: 18,
              fontWeight: FontWeight.bold,
            ),
          ),
          const Spacer(),
          Obx(() => Row(
            children: [7, 30, 60].map((days) {
              final isSelected = controller.selectedDays.value == days;
              return GestureDetector(
                onTap: () => controller.changeDays(days),
                child: Container(
                  padding: const EdgeInsets.symmetric(
                      horizontal: 10, vertical: 4),
                  margin: const EdgeInsets.only(left: 4),
                  decoration: BoxDecoration(
                    color: isSelected
                        ? const Color(0xFF0066FF)
                        : const Color(0xFF0A0E1A),
                    borderRadius: BorderRadius.circular(16),
                    border:
                        Border.all(color: const Color(0xFF1E2536)),
                  ),
                  child: Text(
                    '${days}D',
                    style: TextStyle(
                      color: isSelected
                          ? Colors.white
                          : const Color(0xFF8892A4),
                      fontSize: 12,
                    ),
                  ),
                ),
              );
            }).toList(),
          )),
        ],
      ),
    );
  }

  Widget _buildStockSelector() {
    return Container(
      color: const Color(0xFF141824),
      child: Obx(() {
        if (controller.isLoadingStocks.value) {
          return const SizedBox(
            height: 52,
            child: Center(
              child: SizedBox(
                width: 18,
                height: 18,
                child: CircularProgressIndicator(
                  strokeWidth: 2,
                  color: Color(0xFF00D2FF),
                ),
              ),
            ),
          );
        }
        return SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          padding:
              const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          child: Row(
            children: controller.availableStocks.map((stock) {
              final isSelected =
                  controller.selectedSymbol.value == stock['symbol'];
              return GestureDetector(
                onTap: () => controller.selectStock(
                  stock['symbol'] as String,
                  stock['name'] as String,
                  stock['id'] as int,
                ),
                child: Container(
                  margin: const EdgeInsets.only(right: 6),
                  padding: const EdgeInsets.symmetric(
                      horizontal: 12, vertical: 6),
                  decoration: BoxDecoration(
                    gradient: isSelected
                        ? const LinearGradient(colors: [
                            Color(0xFF0066FF),
                            Color(0xFF00D2FF)
                          ])
                        : null,
                    color: isSelected
                        ? null
                        : const Color(0xFF0A0E1A),
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(
                      color: isSelected
                          ? Colors.transparent
                          : const Color(0xFF1E2536),
                    ),
                  ),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        stock['symbol'] as String,
                        style: TextStyle(
                          color: isSelected
                              ? Colors.white
                              : const Color(0xFF8892A4),
                          fontWeight: FontWeight.bold,
                          fontSize: 13,
                        ),
                      ),
                      Text(
                        stock['name'] as String,
                        style: TextStyle(
                          color: isSelected
                              ? Colors.white70
                              : const Color(0xFF3D4759),
                          fontSize: 10,
                        ),
                      ),
                    ],
                  ),
                ),
              );
            }).toList(),
          ),
        );
      }),
    );
  }

  Widget _buildChartTypeSelector() {
    // 只保留 股價 / HV / IV，移除預測
    final types = [
      {'key': 'price', 'label': '股價', 'icon': Icons.show_chart},
      {'key': 'hv', 'label': 'HV', 'icon': Icons.bar_chart},
      {'key': 'iv', 'label': 'IV', 'icon': Icons.stacked_line_chart},
    ];
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      color: const Color(0xFF0A0E1A),
      child: Obx(() => Row(
        children: types.map((t) {
          final isSelected = controller.chartType.value == t['key'];
          return Expanded(
            child: GestureDetector(
              onTap: () =>
                  controller.changeChartType(t['key'] as String),
              child: Container(
                margin: const EdgeInsets.only(right: 6),
                padding:
                    const EdgeInsets.symmetric(vertical: 10),
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
                      color: isSelected
                          ? Colors.white
                          : const Color(0xFF8892A4),
                      size: 18,
                    ),
                    const SizedBox(height: 3),
                    Text(
                      t['label'] as String,
                      style: TextStyle(
                        color: isSelected
                            ? Colors.white
                            : const Color(0xFF8892A4),
                        fontSize: 12,
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

  // 根據 chartType 顯示不同內容
  Widget _buildContent() {
    return Obx(() {
      switch (controller.chartType.value) {
        case 'hv':
          return _buildHVContent();
        case 'iv':
          return _buildIVContent();
        default:
          return _buildPriceChart();
      }
    });
  }

  // ==========================================
  // 股價折線圖
  // ==========================================
  Widget _buildPriceChart() {
    final prices = controller.priceData;
    if (prices.isEmpty) {
      return _buildEmptyCard('無股價資料');
    }

    final spots = prices.asMap().entries
        .map((e) => FlSpot(e.key.toDouble(), e.value.closePrice))
        .toList();
    final minY = prices
        .map((p) => p.closePrice)
        .reduce((a, b) => a < b ? a : b);
    final maxY = prices
        .map((p) => p.closePrice)
        .reduce((a, b) => a > b ? a : b);

    return Container(
      height: 280,
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: const Color(0xFF141824),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFF1E2536)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Obx(() => Text(
            '${controller.selectedStockName.value}'
            ' (${controller.selectedSymbol.value}) - 收盤價',
            style: const TextStyle(
              color: Colors.white,
              fontSize: 13,
              fontWeight: FontWeight.w600,
            ),
          )),
          const SizedBox(height: 12),
          Expanded(
            child: LineChart(LineChartData(
              gridData: FlGridData(
                show: true,
                getDrawingHorizontalLine: (_) => const FlLine(
                    color: Color(0xFF1E2536), strokeWidth: 1),
                getDrawingVerticalLine: (_) => const FlLine(
                    color: Color(0xFF1E2536), strokeWidth: 0.5),
              ),
              titlesData: FlTitlesData(
                leftTitles: AxisTitles(
                  sideTitles: SideTitles(
                    showTitles: true,
                    reservedSize: 56,
                    getTitlesWidget: (value, _) => Text(
                      value.toStringAsFixed(0),
                      style: const TextStyle(
                          color: Color(0xFF8892A4), fontSize: 10),
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
                          style: const TextStyle(
                              color: Color(0xFF8892A4),
                              fontSize: 9),
                        );
                      }
                      return const SizedBox.shrink();
                    },
                  ),
                ),
                topTitles: const AxisTitles(
                    sideTitles: SideTitles(showTitles: false)),
                rightTitles: const AxisTitles(
                    sideTitles: SideTitles(showTitles: false)),
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
          const SizedBox(height: 8),
          // 圖例
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Container(
                  width: 16,
                  height: 3,
                  color: const Color(0xFF00D2FF)),
              const SizedBox(width: 6),
              const Text('收盤價',
                  style: TextStyle(
                      color: Color(0xFF8892A4), fontSize: 12)),
            ],
          ),
        ],
      ),
    );
  }

  // ==========================================
  // HV 卡片（真實數值）
  // ==========================================
  Widget _buildHVContent() {
    return Obx(() {
      if (!controller.hvLoaded.value && controller.hvValue.value == 0) {
        return _buildLoadButton('載入歷史波動率 (HV)', () => controller.loadHV());
      }

      if (controller.hvValue.value == 0) {
        return _buildEmptyCard('無法計算 HV，資料不足');
      }

      final hv = controller.hvValue.value;
      final rv = controller.rvValue.value;
      final hvColor = hv > 25
          ? const Color(0xFFFF4757)
          : hv > 15
              ? const Color(0xFFFFCC02)
              : const Color(0xFF2ECC71);

      return Column(
        children: [
          // 主要數值卡片
          Container(
            padding: const EdgeInsets.all(20),
            decoration: BoxDecoration(
              color: const Color(0xFF141824),
              borderRadius: BorderRadius.circular(16),
              border: Border.all(
                  color: hvColor.withValues(alpha: 0.4)),
            ),
            child: Column(
              children: [
                Row(
                  children: [
                    const Icon(Icons.bar_chart,
                        color: Color(0xFF2ECC71), size: 20),
                    const SizedBox(width: 8),
                    Text(
                      '歷史波動率 (HV) — ${controller.selectedStockName.value}',
                      style: const TextStyle(
                          color: Colors.white,
                          fontSize: 14,
                          fontWeight: FontWeight.bold),
                    ),
                  ],
                ),
                const SizedBox(height: 20),
                // 大數字
                Text(
                  controller.hvPercentage.value,
                  style: TextStyle(
                    color: hvColor,
                    fontSize: 48,
                    fontWeight: FontWeight.bold,
                  ),
                ),
                const SizedBox(height: 8),
                Text(
                  '${controller.selectedDays.value} 天期 年化歷史波動率',
                  style: const TextStyle(
                      color: Color(0xFF8892A4), fontSize: 13),
                ),
                const SizedBox(height: 20),
                // 波動率等級
                Container(
                  padding: const EdgeInsets.symmetric(
                      horizontal: 16, vertical: 8),
                  decoration: BoxDecoration(
                    color: hvColor.withValues(alpha: 0.15),
                    borderRadius: BorderRadius.circular(20),
                  ),
                  child: Text(
                    hv > 30
                        ? '⚠️ 極高波動'
                        : hv > 20
                            ? '📈 高波動'
                            : hv > 12
                                ? '📊 正常波動'
                                : '📉 低波動',
                    style: TextStyle(
                        color: hvColor,
                        fontWeight: FontWeight.w600),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 12),
          // 說明卡片
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: const Color(0xFF141824),
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: const Color(0xFF1E2536)),
            ),
            child: Column(
              children: [
                _buildMetricRow(
                    'HV（年化）',
                    controller.hvPercentage.value,
                    const Color(0xFF2ECC71)),
                const Divider(color: Color(0xFF1E2536)),
                _buildMetricRow(
                    'RV（實現波動率）',
                    rv > 0 ? controller.rvPercentage.value : '無資料',
                    const Color(0xFF00D2FF)),
                const Divider(color: Color(0xFF1E2536)),
                _buildMetricRow(
                    '計算期間',
                    '${controller.selectedDays.value} 天',
                    const Color(0xFF8892A4)),
                const Divider(color: Color(0xFF1E2536)),
                _buildMetricRow(
                    '計算方法',
                    'Close-to-Close',
                    const Color(0xFF8892A4)),
              ],
            ),
          ),
        ],
      );
    });
  }

  // ==========================================
  // IV 卡片
  // ==========================================
  Widget _buildIVContent() {
    return Obx(() {
      if (!controller.ivLoaded.value) {
        return _buildLoadButton('載入隱含波動率 (IV)', () => controller.loadIV());
      }

      if (!controller.ivAvailable.value) {
        return Container(
          padding: const EdgeInsets.all(24),
          decoration: BoxDecoration(
            color: const Color(0xFF141824),
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: const Color(0xFF1E2536)),
          ),
          child: Column(
            children: [
              const Icon(Icons.info_outline,
                  color: Color(0xFF8892A4), size: 40),
              const SizedBox(height: 12),
              Text(
                'IV 資料僅支援 2330、2317、2454',
                style: const TextStyle(
                    color: Colors.white,
                    fontSize: 15,
                    fontWeight: FontWeight.bold),
              ),
              const SizedBox(height: 8),
              Text(
                '${controller.selectedStockName.value} (${controller.selectedSymbol.value}) 目前無選擇權 IV 資料',
                textAlign: TextAlign.center,
                style: const TextStyle(
                    color: Color(0xFF8892A4), fontSize: 13),
              ),
            ],
          ),
        );
      }

      final iv = controller.ivValue.value;
      final hv = controller.hvValue.value;
      final diff = iv - hv;
      final ivColor = iv > hv
          ? const Color(0xFFFF6B35)
          : const Color(0xFF2ECC71);

      return Column(
        children: [
          Container(
            padding: const EdgeInsets.all(20),
            decoration: BoxDecoration(
              color: const Color(0xFF141824),
              borderRadius: BorderRadius.circular(16),
              border: Border.all(
                  color: ivColor.withValues(alpha: 0.4)),
            ),
            child: Column(
              children: [
                Row(
                  children: [
                    const Icon(Icons.stacked_line_chart,
                        color: Color(0xFFFF6B35), size: 20),
                    const SizedBox(width: 8),
                    Text(
                      '隱含波動率 (IV) — ${controller.selectedStockName.value}',
                      style: const TextStyle(
                          color: Colors.white,
                          fontSize: 14,
                          fontWeight: FontWeight.bold),
                    ),
                  ],
                ),
                const SizedBox(height: 20),
                Text(
                  controller.ivPercentage.value,
                  style: TextStyle(
                    color: ivColor,
                    fontSize: 48,
                    fontWeight: FontWeight.bold,
                  ),
                ),
                const SizedBox(height: 8),
                const Text(
                  '選擇權市場隱含波動率',
                  style: TextStyle(
                      color: Color(0xFF8892A4), fontSize: 13),
                ),
                const SizedBox(height: 20),
                Container(
                  padding: const EdgeInsets.symmetric(
                      horizontal: 16, vertical: 8),
                  decoration: BoxDecoration(
                    color: ivColor.withValues(alpha: 0.15),
                    borderRadius: BorderRadius.circular(20),
                  ),
                  child: Text(
                    iv > hv && hv > 0
                        ? '📈 IV > HV，建議賣出波動率'
                        : iv < hv && hv > 0
                            ? '📉 IV < HV，建議買入波動率'
                            : '📊 IV/HV 接近',
                    style: TextStyle(
                        color: ivColor,
                        fontWeight: FontWeight.w600),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 12),
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: const Color(0xFF141824),
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: const Color(0xFF1E2536)),
            ),
            child: Column(
              children: [
                _buildMetricRow(
                    '隱含波動率 (IV)',
                    controller.ivPercentage.value,
                    const Color(0xFFFF6B35)),
                const Divider(color: Color(0xFF1E2536)),
                _buildMetricRow(
                    '歷史波動率 (HV)',
                    hv > 0
                        ? '${hv.toStringAsFixed(2)}%'
                        : '未載入',
                    const Color(0xFF2ECC71)),
                const Divider(color: Color(0xFF1E2536)),
                _buildMetricRow(
                    'IV - HV 差值',
                    hv > 0
                        ? '${diff >= 0 ? '+' : ''}${diff.toStringAsFixed(2)}%'
                        : '—',
                    diff > 0
                        ? const Color(0xFFFF6B35)
                        : const Color(0xFF2ECC71)),
              ],
            ),
          ),
        ],
      );
    });
  }

  // ==========================================
  // 股票資訊卡片
  // ==========================================
  Widget _buildStockInfoCard() {
    return Obx(() {
      final prices = controller.priceData;
      if (prices.isEmpty) return const SizedBox.shrink();
      final latest = prices.last;
      final prev =
          prices.length > 1 ? prices[prices.length - 2] : prices.last;
      final change = latest.closePrice - prev.closePrice;
      final changePct = prev.closePrice > 0
          ? (change / prev.closePrice * 100)
          : 0.0;
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
                      color: Colors.white,
                      fontSize: 16,
                      fontWeight: FontWeight.bold),
                ),
                const SizedBox(width: 8),
                Text(
                  controller.selectedSymbol.value,
                  style: const TextStyle(
                      color: Color(0xFF8892A4), fontSize: 13),
                ),
                const Spacer(),
                Text(
                  latest.closePrice.toStringAsFixed(1),
                  style: const TextStyle(
                      color: Colors.white,
                      fontSize: 22,
                      fontWeight: FontWeight.bold),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Row(
              children: [
                Icon(
                  isUp ? Icons.arrow_upward : Icons.arrow_downward,
                  color: isUp
                      ? const Color(0xFF2ECC71)
                      : const Color(0xFFFF4757),
                  size: 14,
                ),
                Text(
                  ' ${change >= 0 ? '+' : ''}${change.toStringAsFixed(1)}'
                  ' (${changePct.toStringAsFixed(2)}%)',
                  style: TextStyle(
                    color: isUp
                        ? const Color(0xFF2ECC71)
                        : const Color(0xFFFF4757),
                    fontSize: 13,
                  ),
                ),
                const Spacer(),
                Text(
                  '成交量: ${(latest.volume / 1000000).toStringAsFixed(1)}M',
                  style: const TextStyle(
                      color: Color(0xFF8892A4), fontSize: 12),
                ),
              ],
            ),
            const SizedBox(height: 12),
            Row(
              children: [
                _buildInfoItem('開盤',
                    latest.openPrice.toStringAsFixed(1)),
                _buildInfoItem('最高',
                    latest.highPrice.toStringAsFixed(1)),
                _buildInfoItem(
                    '最低', latest.lowPrice.toStringAsFixed(1)),
                _buildInfoItem('日期',
                    latest.tradeDate.length >= 10
                        ? latest.tradeDate.substring(5)
                        : latest.tradeDate),
              ],
            ),
          ],
        ),
      );
    });
  }

  // ==========================================
  // 共用元件
  // ==========================================
  Widget _buildMetricRow(String label, String value, Color color) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label,
              style: const TextStyle(
                  color: Color(0xFF8892A4), fontSize: 13)),
          Text(value,
              style: TextStyle(
                  color: color,
                  fontSize: 14,
                  fontWeight: FontWeight.w600)),
        ],
      ),
    );
  }

  Widget _buildInfoItem(String label, String value) {
    return Expanded(
      child: Column(
        children: [
          Text(label,
              style: const TextStyle(
                  color: Color(0xFF8892A4), fontSize: 11)),
          const SizedBox(height: 2),
          Text(value,
              style: const TextStyle(
                  color: Colors.white,
                  fontSize: 13,
                  fontWeight: FontWeight.w600)),
        ],
      ),
    );
  }

  Widget _buildEmptyCard(String message) {
    return Container(
      padding: const EdgeInsets.all(32),
      decoration: BoxDecoration(
        color: const Color(0xFF141824),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFF1E2536)),
      ),
      child: Center(
        child: Text(message,
            style: const TextStyle(color: Color(0xFF8892A4))),
      ),
    );
  }

  Widget _buildLoadButton(String label, VoidCallback onTap) {
    return Container(
      padding: const EdgeInsets.all(32),
      decoration: BoxDecoration(
        color: const Color(0xFF141824),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFF1E2536)),
      ),
      child: Center(
        child: ElevatedButton.icon(
          onPressed: onTap,
          icon: const Icon(Icons.calculate, size: 18),
          label: Text(label),
          style: ElevatedButton.styleFrom(
            backgroundColor: const Color(0xFF0066FF),
            foregroundColor: Colors.white,
            padding: const EdgeInsets.symmetric(
                horizontal: 20, vertical: 12),
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(10),
            ),
          ),
        ),
      ),
    );
  }
}
