import 'package:flutter/material.dart';
import '../models/dashboard_model.dart';

class VolatilityBadgeWidget extends StatelessWidget {
  final VolatilityOverviewModel volatility;
  const VolatilityBadgeWidget({super.key, required this.volatility});

  @override
  Widget build(BuildContext context) {
    final hv = volatility.hv ?? 0;
    final iv = volatility.iv ?? 0;
    final ivHigher = iv > hv;
    final diff = (iv - hv).abs();
    final signalColor =
        ivHigher ? const Color(0xFFFF6B35) : const Color(0xFF2ECC71);
    final signalLabel = ivHigher ? '賣出波動率訊號' : '買入波動率訊號';

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: const Color(0xFF141824),
        borderRadius: BorderRadius.circular(12),
        // ✅ 修改：withOpacity → withValues
        border: Border.all(color: signalColor.withValues(alpha: 0.25)),
      ),
      child: Column(
        children: [
          Row(
            children: [
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                decoration: BoxDecoration(
                  // ✅ 修改：withOpacity → withValues
                  color: const Color(0xFF0066FF).withValues(alpha: 0.15),
                  borderRadius: BorderRadius.circular(6),
                ),
                child: Text(
                  volatility.symbol,
                  style: const TextStyle(
                    color: Color(0xFF00D2FF), fontWeight: FontWeight.bold, fontSize: 14,
                  ),
                ),
              ),
              const SizedBox(width: 10),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(
                  // ✅ 修改：withOpacity → withValues
                  color: signalColor.withValues(alpha: 0.15),
                  borderRadius: BorderRadius.circular(6),
                ),
                child: Text(
                  signalLabel,
                  style: TextStyle(
                    color: signalColor, fontSize: 11, fontWeight: FontWeight.w600,
                  ),
                ),
              ),
              const Spacer(),
              Text(
                'Δ ${diff.toStringAsFixed(1)}%',
                style: TextStyle(
                  color: signalColor, fontSize: 13, fontWeight: FontWeight.bold,
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: _buildVolatilityBar(
                  label: 'HV',
                  value: hv,
                  color: const Color(0xFF2ECC71),
                  maxValue: [hv, iv].reduce((a, b) => a > b ? a : b) * 1.3,
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _buildVolatilityBar(
                  label: 'IV',
                  value: iv,
                  color: const Color(0xFFFF6B35),
                  maxValue: [hv, iv].reduce((a, b) => a > b ? a : b) * 1.3,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildVolatilityBar({
    required String label,
    required double value,
    required Color color,
    required double maxValue,
  }) {
    final ratio = maxValue > 0 ? (value / maxValue).clamp(0.0, 1.0) : 0.0;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text(
              label,
              style: TextStyle(color: color, fontSize: 12, fontWeight: FontWeight.w600),
            ),
            Text(
              '${value.toStringAsFixed(1)}%',
              style: const TextStyle(color: Colors.white, fontSize: 12),
            ),
          ],
        ),
        const SizedBox(height: 4),
        ClipRRect(
          borderRadius: BorderRadius.circular(4),
          child: LinearProgressIndicator(
            value: ratio,
            minHeight: 6,
            backgroundColor: const Color(0xFF0A0E1A),
            valueColor: AlwaysStoppedAnimation<Color>(color),
          ),
        ),
      ],
    );
  }
}
