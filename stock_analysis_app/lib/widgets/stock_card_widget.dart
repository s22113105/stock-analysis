import 'package:flutter/material.dart';
import 'package:get/get.dart';
import '../models/stock_model.dart';
import '../utils/app_constants.dart';

class StockCardWidget extends StatelessWidget {
  final StockModel stock;
  const StockCardWidget({super.key, required this.stock});

  @override
  Widget build(BuildContext context) {
    final isUp = (stock.changePercent ?? 0) >= 0;
    final changeColor =
        isUp ? const Color(0xFF2ECC71) : const Color(0xFFFF4757);

    final recentPrices = stock.prices.length > 10
        ? stock.prices.sublist(stock.prices.length - 10)
        : stock.prices;

    return GestureDetector(
      onTap: () => Get.toNamed(AppConstants.routeChart),
      child: Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: const Color(0xFF141824),
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: const Color(0xFF1E2536)),
        ),
        child: Row(
          children: [
            // 股票代號圓圈
            Container(
              width: 44, height: 44,
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  colors: isUp
                      ? [const Color(0xFF0D3B2E), const Color(0xFF1A5C3A)]
                      : [const Color(0xFF3B0D0D), const Color(0xFF5C1A1A)],
                ),
                borderRadius: BorderRadius.circular(10),
              ),
              child: Center(
                child: Text(
                  stock.symbol.length > 3
                      ? stock.symbol.substring(0, 3)
                      : stock.symbol,
                  style: TextStyle(
                    color: changeColor,
                    fontSize: 12,
                    fontWeight: FontWeight.bold,
                  ),
                ),
              ),
            ),
            const SizedBox(width: 12),

            // 股票名稱
            Expanded(
              flex: 2,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    stock.name,
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 14,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    stock.symbol,
                    style: const TextStyle(
                      color: Color(0xFF8892A4), fontSize: 12,
                    ),
                  ),
                ],
              ),
            ),

            // 迷你折線圖
            if (recentPrices.isNotEmpty)
              Expanded(
                flex: 2,
                child: SizedBox(
                  height: 36,
                  child: CustomPaint(
                    painter: MiniLinePainter(
                      prices: recentPrices.map((p) => p.closePrice).toList(),
                      color: changeColor,
                    ),
                  ),
                ),
              ),

            const SizedBox(width: 12),

            // 價格與漲跌
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Text(
                  stock.currentPrice != null
                      ? stock.currentPrice!.toStringAsFixed(1)
                      : (stock.prices.isNotEmpty
                          ? stock.prices.last.closePrice.toStringAsFixed(1)
                          : '--'),
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 16,
                    fontWeight: FontWeight.bold,
                  ),
                ),
                const SizedBox(height: 2),
                Row(
                  children: [
                    Icon(
                      isUp ? Icons.arrow_upward : Icons.arrow_downward,
                      color: changeColor,
                      size: 12,
                    ),
                    Text(
                      '${(stock.changePercent ?? 0) >= 0 ? '+' : ''}${(stock.changePercent ?? 0).toStringAsFixed(2)}%',
                      style: TextStyle(color: changeColor, fontSize: 12),
                    ),
                  ],
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

// 迷你折線圖 Painter
class MiniLinePainter extends CustomPainter {
  final List<double> prices;
  final Color color;

  MiniLinePainter({required this.prices, required this.color});

  @override
  void paint(Canvas canvas, Size size) {
    if (prices.length < 2) return;

    final minPrice = prices.reduce((a, b) => a < b ? a : b);
    final maxPrice = prices.reduce((a, b) => a > b ? a : b);
    final range = maxPrice - minPrice;
    if (range == 0) return;

    final paint = Paint()
      ..color = color
      ..strokeWidth = 1.5
      ..style = PaintingStyle.stroke
      ..strokeCap = StrokeCap.round;

    final path = Path();
    for (int i = 0; i < prices.length; i++) {
      final x = i / (prices.length - 1) * size.width;
      final y = size.height - ((prices[i] - minPrice) / range) * size.height;
      if (i == 0) {
        path.moveTo(x, y);
      } else {
        path.lineTo(x, y);
      }
    }
    canvas.drawPath(path, paint);

    // 終點圓點
    final lastX = size.width;
    final lastY = size.height -
        ((prices.last - minPrice) / range) * size.height;
    canvas.drawCircle(
      Offset(lastX, lastY),
      3,
      Paint()..color = color,
    );
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => true;
}
