// ============================================
// C3. Notification View - 波動率警示與策略訊息
// 對應 DashboardController::alerts & BacktestController
// ============================================

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import '../../controllers/notification_controller.dart';
import '../../widgets/bottom_nav_widget.dart';

class NotificationView extends GetView<NotificationController> {
  const NotificationView({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF0A0E1A),
      body: SafeArea(
        child: Column(
          children: [
            _buildHeader(),
            _buildFilterChips(),
            Expanded(
              child: Obx(() {
                if (controller.isLoading.value) {
                  return const Center(
                    child: CircularProgressIndicator(color: Color(0xFF00D2FF)),
                  );
                }
                return RefreshIndicator(
                  color: const Color(0xFF00D2FF),
                  backgroundColor: const Color(0xFF141824),
                  onRefresh: controller.refresh,
                  child: SingleChildScrollView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        _buildSectionTitle('策略訊號', Icons.lightbulb_outline),
                        const SizedBox(height: 12),
                        _buildStrategySignals(),
                        const SizedBox(height: 20),
                        _buildSectionTitle('系統警示', Icons.notifications_active),
                        const SizedBox(height: 12),
                        _buildAlerts(),
                        const SizedBox(height: 20),
                        _buildSectionTitle('最近回測結果', Icons.history),
                        const SizedBox(height: 12),
                        _buildBacktestResults(),
                        const SizedBox(height: 80),
                      ],
                    ),
                  ),
                );
              }),
            ),
          ],
        ),
      ),
      bottomNavigationBar: const BottomNavWidget(currentIndex: 2),
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
          const Icon(Icons.notifications_outlined, color: Color(0xFF00D2FF), size: 22),
          const SizedBox(width: 10),
          const Text(
            '通知中心',
            style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold),
          ),
          const Spacer(),
          Obx(() => controller.alerts.isNotEmpty
              ? Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                  decoration: BoxDecoration(
                    color: const Color(0xFFFF4757),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Text(
                    '${controller.alerts.length}',
                    style: const TextStyle(
                      color: Colors.white, fontSize: 12, fontWeight: FontWeight.bold,
                    ),
                  ),
                )
              : const SizedBox.shrink()),
          const SizedBox(width: 8),
          IconButton(
            icon: const Icon(Icons.refresh, color: Color(0xFF8892A4), size: 20),
            onPressed: controller.refresh,
            padding: EdgeInsets.zero,
            constraints: const BoxConstraints(),
          ),
        ],
      ),
    );
  }

  Widget _buildFilterChips() {
    final filters = [
      {'key': 'all', 'label': '全部'},
      {'key': 'warning', 'label': '警告'},
      {'key': 'error', 'label': '錯誤'},
      {'key': 'info', 'label': '資訊'},
    ];
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      color: const Color(0xFF0A0E1A),
      child: Obx(() => Row(
        children: filters.map((f) {
          final isSelected = controller.filterType.value == f['key'];
          return Padding(
            padding: const EdgeInsets.only(right: 8),
            child: GestureDetector(
              onTap: () => controller.filterType.value = f['key']!,
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
                decoration: BoxDecoration(
                  color: isSelected ? const Color(0xFF0066FF) : const Color(0xFF141824),
                  borderRadius: BorderRadius.circular(20),
                  border: Border.all(
                    color: isSelected ? const Color(0xFF0066FF) : const Color(0xFF1E2536),
                  ),
                ),
                child: Text(
                  f['label']!,
                  style: TextStyle(
                    color: isSelected ? Colors.white : const Color(0xFF8892A4),
                    fontSize: 12,
                  ),
                ),
              ),
            ),
          );
        }).toList(),
      )),
    );
  }

  Widget _buildStrategySignals() {
    return Obx(() {
      if (controller.strategySignals.isEmpty) return _buildEmptyState('無策略訊號');
      return Column(
        children: controller.strategySignals.map((signal) {
          final type = signal['type'] as String;
          final color = type == 'success'
              ? const Color(0xFF2ECC71)
              : type == 'warning'
                  ? const Color(0xFFFFCC02)
                  : const Color(0xFFFF4757);

          return Container(
            margin: const EdgeInsets.only(bottom: 10),
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: const Color(0xFF141824),
              borderRadius: BorderRadius.circular(12),
              // ✅ 修改：withOpacity → withValues
              border: Border.all(color: color.withValues(alpha: 0.3)),
            ),
            child: Row(
              children: [
                Container(
                  width: 42, height: 42,
                  decoration: BoxDecoration(
                    // ✅ 修改：withOpacity → withValues
                    color: color.withValues(alpha: 0.15),
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: Icon(
                    type == 'success' ? Icons.trending_up : Icons.remove,
                    color: color, size: 22,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Text(
                            signal['symbol'] as String,
                            style: const TextStyle(
                              color: Colors.white, fontWeight: FontWeight.bold, fontSize: 14,
                            ),
                          ),
                          const SizedBox(width: 6),
                          Text(
                            signal['name'] as String,
                            style: const TextStyle(color: Color(0xFF8892A4), fontSize: 12),
                          ),
                        ],
                      ),
                      const SizedBox(height: 3),
                      Text(
                        signal['title'] as String,
                        style: TextStyle(
                          color: color, fontSize: 13, fontWeight: FontWeight.w600,
                        ),
                      ),
                      Text(
                        signal['description'] as String,
                        style: const TextStyle(color: Color(0xFF8892A4), fontSize: 11),
                      ),
                    ],
                  ),
                ),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    Text(
                      'IV ${signal['iv']}%',
                      style: const TextStyle(color: Color(0xFFFF6B35), fontSize: 12),
                    ),
                    Text(
                      'HV ${signal['hv']}%',
                      style: const TextStyle(color: Color(0xFF2ECC71), fontSize: 12),
                    ),
                  ],
                ),
              ],
            ),
          );
        }).toList(),
      );
    });
  }

  Widget _buildAlerts() {
    return Obx(() {
      final filtered = controller.filteredAlerts;
      if (filtered.isEmpty) return _buildEmptyState('暫無警示');
      return Column(
        children: filtered.map((alert) {
          final color = _getAlertColor(alert.type);
          final icon = _getAlertIcon(alert.type);
          return Container(
            margin: const EdgeInsets.only(bottom: 8),
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: const Color(0xFF141824),
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: const Color(0xFF1E2536)),
            ),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  width: 36, height: 36,
                  decoration: BoxDecoration(
                    // ✅ 修改：withOpacity → withValues
                    color: color.withValues(alpha: 0.15),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Icon(icon, color: color, size: 18),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        alert.title,
                        style: const TextStyle(
                          color: Colors.white, fontSize: 13, fontWeight: FontWeight.w600,
                        ),
                      ),
                      const SizedBox(height: 3),
                      Text(
                        alert.message,
                        style: const TextStyle(color: Color(0xFF8892A4), fontSize: 12),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        _formatTime(alert.timestamp),
                        style: const TextStyle(color: Color(0xFF3D4759), fontSize: 10),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          );
        }).toList(),
      );
    });
  }

  Widget _buildBacktestResults() {
    return Obx(() {
      if (controller.recentBacktests.isEmpty) return _buildEmptyState('無回測資料');
      return Column(
        children: controller.recentBacktests.map((result) {
          final isPositive = result.totalReturn >= 0;
          return Container(
            margin: const EdgeInsets.only(bottom: 10),
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: const Color(0xFF141824),
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: const Color(0xFF1E2536)),
            ),
            child: Column(
              children: [
                Row(
                  children: [
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                      decoration: BoxDecoration(
                        // ✅ 修改：withOpacity → withValues
                        color: const Color(0xFF0066FF).withValues(alpha: 0.2),
                        borderRadius: BorderRadius.circular(6),
                      ),
                      child: Text(
                        result.strategy.replaceAll('_', ' ').toUpperCase(),
                        style: const TextStyle(color: Color(0xFF00D2FF), fontSize: 11),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Text(
                      result.stockSymbol,
                      style: const TextStyle(
                        color: Colors.white, fontWeight: FontWeight.bold,
                      ),
                    ),
                    const Spacer(),
                    Text(
                      '${isPositive ? '+' : ''}${result.totalReturn.toStringAsFixed(2)}%',
                      style: TextStyle(
                        color: isPositive
                            ? const Color(0xFF2ECC71)
                            : const Color(0xFFFF4757),
                        fontWeight: FontWeight.bold,
                        fontSize: 16,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 10),
                Row(
                  children: [
                    _buildResultMetric(
                      'Sharpe', result.sharpeRatio.toStringAsFixed(2), const Color(0xFF00D2FF),
                    ),
                    _buildResultMetric(
                      'Max DD', '${result.maxDrawdown.toStringAsFixed(1)}%', const Color(0xFFFF4757),
                    ),
                    _buildResultMetric(
                      '期間',
                      '${result.startDate.substring(0, 7)} ~ ${result.endDate.substring(0, 7)}',
                      const Color(0xFF8892A4),
                    ),
                  ],
                ),
              ],
            ),
          );
        }).toList(),
      );
    });
  }

  Widget _buildResultMetric(String label, String value, Color color) {
    return Expanded(
      child: Column(
        children: [
          Text(label, style: const TextStyle(color: Color(0xFF8892A4), fontSize: 11)),
          const SizedBox(height: 2),
          Text(
            value,
            style: TextStyle(color: color, fontSize: 12, fontWeight: FontWeight.w600),
          ),
        ],
      ),
    );
  }

  Widget _buildSectionTitle(String title, IconData icon) {
    return Row(
      children: [
        Icon(icon, color: const Color(0xFF00D2FF), size: 18),
        const SizedBox(width: 8),
        Text(
          title,
          style: const TextStyle(
            color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold,
          ),
        ),
      ],
    );
  }

  Widget _buildEmptyState(String message) {
    return Container(
      padding: const EdgeInsets.all(24),
      decoration: BoxDecoration(
        color: const Color(0xFF141824),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Center(
        child: Text(message, style: const TextStyle(color: Color(0xFF8892A4))),
      ),
    );
  }

  Color _getAlertColor(String type) {
    switch (type) {
      case 'error':   return const Color(0xFFFF4757);
      case 'warning': return const Color(0xFFFFCC02);
      case 'success': return const Color(0xFF2ECC71);
      default:        return const Color(0xFF00D2FF);
    }
  }

  IconData _getAlertIcon(String type) {
    switch (type) {
      case 'error':   return Icons.error_outline;
      case 'warning': return Icons.warning_amber_outlined;
      case 'success': return Icons.check_circle_outline;
      default:        return Icons.info_outline;
    }
  }

  String _formatTime(String timestamp) {
    try {
      final dt = DateTime.parse(timestamp);
      final now = DateTime.now();
      final diff = now.difference(dt);
      if (diff.inMinutes < 60) return '${diff.inMinutes} 分鐘前';
      if (diff.inHours < 24) return '${diff.inHours} 小時前';
      return timestamp.substring(0, 10);
    } catch (_) {
      return timestamp;
    }
  }
}
