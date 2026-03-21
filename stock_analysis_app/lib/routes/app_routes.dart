import 'package:get/get.dart';
import '../views/login/login_view.dart';
import '../views/home/home_view.dart';
import '../views/chart/chart_view.dart';
import '../views/notification/notification_view.dart';
import '../bindings/auth_binding.dart';
import '../bindings/home_binding.dart';
import '../bindings/chart_binding.dart';
import '../bindings/notification_binding.dart';
import '../utils/app_constants.dart';

class AppRoutes {
  static final routes = [
    GetPage(
      name: AppConstants.routeLogin,
      page: () => const LoginView(),
      binding: AuthBinding(),
    ),
    GetPage(
      name: AppConstants.routeHome,
      page: () => const HomeView(),
      binding: HomeBinding(),
    ),
    GetPage(
      name: AppConstants.routeChart,
      page: () => const ChartView(),
      binding: ChartBinding(),
    ),
    GetPage(
      name: AppConstants.routeNotification,
      page: () => const NotificationView(),
      binding: NotificationBinding(),
    ),
  ];
}
