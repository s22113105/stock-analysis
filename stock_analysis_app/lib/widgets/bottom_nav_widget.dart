import 'package:flutter/material.dart';
import 'package:get/get.dart';
import '../utils/app_constants.dart';

class BottomNavWidget extends StatelessWidget {
  final int currentIndex;
  const BottomNavWidget({super.key, required this.currentIndex});

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: const BoxDecoration(
        color: Color(0xFF141824),
        border: Border(top: BorderSide(color: Color(0xFF1E2536))),
      ),
      child: BottomNavigationBar(
        currentIndex: currentIndex,
        backgroundColor: Colors.transparent,
        elevation: 0,
        type: BottomNavigationBarType.fixed,
        selectedItemColor: const Color(0xFF00D2FF),
        unselectedItemColor: const Color(0xFF3D4759),
        selectedFontSize: 11,
        unselectedFontSize: 11,
        onTap: (index) {
          switch (index) {
            case 0: Get.offAllNamed(AppConstants.routeHome); break;
            case 1: Get.offAllNamed(AppConstants.routeChart); break;
            case 2: Get.offAllNamed(AppConstants.routeNotification); break;
          }
        },
        items: const [
          BottomNavigationBarItem(
            icon: Icon(Icons.home_outlined),
            activeIcon: Icon(Icons.home),
            label: '首頁',
          ),
          BottomNavigationBarItem(
            icon: Icon(Icons.show_chart),
            activeIcon: Icon(Icons.area_chart),
            label: '圖表',
          ),
          BottomNavigationBarItem(
            icon: Icon(Icons.notifications_outlined),
            activeIcon: Icon(Icons.notifications),
            label: '通知',
          ),
        ],
      ),
    );
  }
}
