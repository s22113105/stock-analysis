import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';

import 'services/api_service.dart';
import 'controllers/auth_controller.dart';
import 'routes/app_routes.dart';
import 'utils/app_constants.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  await SystemChrome.setPreferredOrientations([
    DeviceOrientation.portraitUp,
    DeviceOrientation.portraitDown,
  ]);

  SystemChrome.setSystemUIOverlayStyle(
    const SystemUiOverlayStyle(
      statusBarColor: Colors.transparent,
      statusBarIconBrightness: Brightness.light,
    ),
  );

  Get.put(ApiService(), permanent: true);
  Get.put(AuthController(), permanent: true);

  runApp(const StockAnalysisApp());
}

class StockAnalysisApp extends StatelessWidget {
  const StockAnalysisApp({super.key});

  @override
  Widget build(BuildContext context) {
    return GetMaterialApp(
      title: 'Stock_Analysis',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        brightness: Brightness.dark,
        scaffoldBackgroundColor: const Color(0xFF0A0E1A),
        colorScheme: const ColorScheme.dark(
          primary: Color(0xFF0066FF),
          secondary: Color(0xFF00D2FF),
          surface: Color(0xFF141824),
          error: Color(0xFFFF4757),
        ),
        appBarTheme: const AppBarTheme(
          backgroundColor: Color(0xFF141824),
          elevation: 0,
          titleTextStyle: TextStyle(
            color: Colors.white,
            fontSize: 18,
            fontWeight: FontWeight.bold,
          ),
        ),
        cardColor: const Color(0xFF141824),
        dividerColor: const Color(0xFF1E2536),
        textTheme: const TextTheme(
          bodyLarge: TextStyle(color: Colors.white),
          bodyMedium: TextStyle(color: Color(0xFF8892A4)),
        ),
      ),
      initialRoute: AppConstants.routeLogin,
      getPages: AppRoutes.routes,
      defaultTransition: Transition.fadeIn,
      transitionDuration: const Duration(milliseconds: 200),
    );
  }
}
