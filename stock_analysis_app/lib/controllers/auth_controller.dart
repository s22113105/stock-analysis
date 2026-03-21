import 'package:get/get.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../services/api_service.dart';
import '../utils/app_constants.dart';

class AuthController extends GetxController {
  final ApiService _apiService = Get.find<ApiService>();

  final RxBool isLoading = false.obs;
  final RxBool isLoggedIn = false.obs;
  final RxString userName = ''.obs;
  final RxString userEmail = ''.obs;
  final RxString errorMessage = ''.obs;

  @override
  void onInit() {
    super.onInit();
    checkLoginStatus();
  }

  Future<void> checkLoginStatus() async {
    final prefs = await SharedPreferences.getInstance();
    final token = prefs.getString(AppConstants.tokenKey);
    if (token != null && token.isNotEmpty) {
      isLoggedIn.value = true;
      userName.value = prefs.getString(AppConstants.userNameKey) ?? '';
      userEmail.value = prefs.getString(AppConstants.userEmailKey) ?? '';
    }
  }

  Future<bool> login(String email, String password) async {
    isLoading.value = true;
    errorMessage.value = '';
    try {
      final response = await _apiService.login(email, password);
      if (response['success'] == true || response['token'] != null) {
        final token = response['token'] ?? response['data']?['token'];
        final user = response['user'] ?? response['data']?['user'];

        final prefs = await SharedPreferences.getInstance();
        await prefs.setString(AppConstants.tokenKey, token);
        await prefs.setString(AppConstants.userNameKey, user?['name'] ?? '');
        await prefs.setString(AppConstants.userEmailKey, user?['email'] ?? '');

        isLoggedIn.value = true;
        userName.value = user?['name'] ?? '';
        userEmail.value = user?['email'] ?? '';
        return true;
      } else {
        errorMessage.value = response['message'] ?? '登入失敗';
        return false;
      }
    } catch (e) {
      errorMessage.value = '連線失敗，請確認伺服器狀態';
      return false;
    } finally {
      isLoading.value = false;
    }
  }

  Future<void> logout() async {
    isLoading.value = true;
    await _apiService.logout();
    isLoggedIn.value = false;
    userName.value = '';
    userEmail.value = '';
    isLoading.value = false;
    Get.offAllNamed(AppConstants.routeLogin);
  }
}
