// ============================================
// C1. Home View - 首頁（即時行情與預測指標）
// 對應 DashboardController
// ============================================

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import '../../controllers/home_controller.dart';
import '../../controllers/auth_controller.dart';
import '../../widgets/bottom_nav_widget.dart';
import '../../widgets/stock_card_widget.dart';
import '../../widgets/stat_card_widget.dart';
import '../../widgets/volatility_badge_widget.dart';

class HomeView extends GetView<HomeController> {
  const HomeView({super.key});

  @override
  Widget build(BuildContext context) {
    final authCtrl = Get.find<AuthController>();
    return Scaffold(
      backgroundColor: const Color(0xFF0A0E1A),
      body: SafeArea(
        child: Column(
          children: [
            _buildHeader(authCtrl),
            Expanded(
              child: RefreshIndicator(
                color: const Color(0xFF00D2FF),
                backgroundColor: const Color(0xFF141824),
                onRefresh: controller.loadAll,
                child: SingleChildScrollView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      _buildStatsSection(),
                      const SizedBox(height: 20),
                      _buildSectionTitle('即時行情', Icons.trending_up),
                      const SizedBox(height: 12),
                      _buildStockTrends(),
                      const SizedBox(height: 20),
                      _buildSectionTitle('波動率分析 (IV/HV)', Icons.analytics),
                      const SizedBox(height: 12),
                      _buildVolatilitySection(),
                      const SizedBox(height: 20),
                      _buildDaysSelector(),
                      const SizedBox(height: 80),
                    ],
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
      bottomNavigationBar: const BottomNavWidget(currentIndex: 0),
    );
  }

  Widget _buildHeader(AuthController authCtrl) {
    return Container(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
      decoration: const BoxDecoration(
        color: Color(0xFF141824),
        border: Border(bottom: BorderSide(color: Color(0xFF1E2536))),
      ),
      child: Row(
        children: [
          const Icon(Icons.candlestick_chart, color: Color(0xFF00D2FF), size: 24),
          const SizedBox(width: 10),
          const Text(
            'Stock_Analysis',
            style: TextStyle(
              color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold,
            ),
          ),
          const Spacer(),
          IconButton(
            icon: const Icon(Icons.refresh, color: Color(0xFF8892A4), size: 20),
            onPressed: controller.loadAll,
            padding: EdgeInsets.zero,
            constraints: const BoxConstraints(),
          ),
          const SizedBox(width: 8),
          GestureDetector(
            onTap: () => _showLogoutDialog(authCtrl),
            child: CircleAvatar(
              radius: 16,
              backgroundColor: const Color(0xFF0066FF),
              child: Obx(() => Text(
                authCtrl.userName.value.isNotEmpty
                    ? authCtrl.userName.value[0].toUpperCase()
                    : 'U',
                style: const TextStyle(
                  color: Colors.white, fontSize: 13, fontWeight: FontWeight.bold,
                ),
              )),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildStatsSection() {
    return Obx(() {
      if (controller.isLoadingStats.value) {
        return _buildStatsShimmer();
      }
      final s = controller.stats.value;
      return Row(
        children: [
          Expanded(child: StatCardWidget(
            title: '股票數', value: '${s?.totalStocks ?? 0}',
            icon: Icons.bar_chart, color: const Color(0xFF0066FF),
          )),
          const SizedBox(width: 10),
          Expanded(child: StatCardWidget(
            title: '選擇權', value: '${s?.activeOptions ?? 0}',
            icon: Icons.swap_horiz, color: const Color(0xFF00D2FF),
          )),
          const SizedBox(width: 10),
          Expanded(child: StatCardWidget(
            title: '警示數', value: '${controller.alerts.length}',
            icon: Icons.notifications_active,
            color: controller.alerts.isNotEmpty
                ? const Color(0xFFFF6B35)
                : const Color(0xFF2ECC71),
          )),
        ],
      );
    });
  }

  Widget _buildStockTrends() {
    return Obx(() {
      if (controller.isLoadingTrends.value) {
        return const Center(
          child: Padding(
            padding: EdgeInsets.all(32),
            child: CircularProgressIndicator(color: Color(0xFF00D2FF)),
          ),
        );
      }
      final trends = controller.stockTrends.value;
      if (trends == null || trends.stocks.isEmpty) {
        return _buildEmptyState('無股票資料');
      }
      return Column(
        children: trends.stocks.map((stock) => Padding(
          padding: const EdgeInsets.only(bottom: 10),
          child: StockCardWidget(stock: stock),
        )).toList(),
      );
    });
  }

  Widget _buildVolatilitySection() {
    return Obx(() {
      if (controller.isLoadingVolatility.value) {
        return const Center(
          child: Padding(
            padding: EdgeInsets.all(20),
            child: CircularProgressIndicator(color: Color(0xFF00D2FF)),
          ),
        );
      }
      if (controller.volatilityList.isEmpty) {
        return _buildEmptyState('無波動率資料');
      }
      return Column(
        children: controller.volatilityList.map((v) => Padding(
          padding: const EdgeInsets.only(bottom: 8),
          child: VolatilityBadgeWidget(volatility: v),
        )).toList(),
      );
    });
  }

  Widget _buildDaysSelector() {
    return Obx(() => Row(
      children: [
        const Text('期間：', style: TextStyle(color: Color(0xFF8892A4), fontSize: 13)),
        ...[7, 14, 30, 60].map((days) => Padding(
          padding: const EdgeInsets.only(right: 8),
          child: GestureDetector(
            onTap: () => controller.changeDays(days),
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
              decoration: BoxDecoration(
                color: controller.selectedDays.value == days
                    ? const Color(0xFF0066FF)
                    : const Color(0xFF141824),
                borderRadius: BorderRadius.circular(20),
                border: Border.all(
                  color: controller.selectedDays.value == days
                      ? const Color(0xFF0066FF)
                      : const Color(0xFF1E2536),
                ),
              ),
              child: Text(
                '${days}天',
                style: TextStyle(
                  color: controller.selectedDays.value == days
                      ? Colors.white
                      : const Color(0xFF8892A4),
                  fontSize: 13,
                ),
              ),
            ),
          ),
        )),
      ],
    ));
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

  Widget _buildStatsShimmer() {
    return Row(
      children: List.generate(3, (_) => Expanded(
        child: Container(
          margin: const EdgeInsets.only(right: 10),
          height: 80,
          decoration: BoxDecoration(
            color: const Color(0xFF141824),
            borderRadius: BorderRadius.circular(12),
          ),
        ),
      )),
    );
  }

  void _showLogoutDialog(AuthController authCtrl) {
    Get.dialog(AlertDialog(
      backgroundColor: const Color(0xFF141824),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      title: const Text('登出', style: TextStyle(color: Colors.white)),
      content: Obx(() => Text(
        '確定要登出 ${authCtrl.userName.value} 嗎？',
        style: const TextStyle(color: Color(0xFF8892A4)),
      )),
      actions: [
        TextButton(
          onPressed: () => Get.back(),
          child: const Text('取消', style: TextStyle(color: Color(0xFF8892A4))),
        ),
        ElevatedButton(
          onPressed: () {
            Get.back();
            authCtrl.logout();
          },
          style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFFFF4757)),
          child: const Text('登出', style: TextStyle(color: Colors.white)),
        ),
      ],
    ));
  }
}
