// ============================================
// C1. Login View - 登入頁面
// 對應 AuthController (Laravel Sanctum)
// ============================================

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import '../../controllers/auth_controller.dart';
import '../../utils/app_constants.dart';

class LoginView extends GetView<AuthController> {
  const LoginView({super.key});

  @override
  Widget build(BuildContext context) {
    final emailCtrl = TextEditingController(text: 'admin@example.com');
    final passwordCtrl = TextEditingController(text: 'password');
    final obscure = true.obs;

    return Scaffold(
      backgroundColor: const Color(0xFF0A0E1A),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const SizedBox(height: 48),

              // Logo & Title
              Center(
                child: Column(
                  children: [
                    Container(
                      width: 80, height: 80,
                      decoration: BoxDecoration(
                        gradient: const LinearGradient(
                          colors: [Color(0xFF00D2FF), Color(0xFF0066FF)],
                        ),
                        borderRadius: BorderRadius.circular(20),
                      ),
                      child: const Icon(Icons.candlestick_chart, color: Colors.white, size: 44),
                    ),
                    const SizedBox(height: 16),
                    const Text(
                      'Stock_Analysis',
                      style: TextStyle(
                        color: Colors.white,
                        fontSize: 28,
                        fontWeight: FontWeight.bold,
                        letterSpacing: 1.2,
                      ),
                    ),
                    const SizedBox(height: 6),
                    const Text(
                      '台股選擇權分析系統',
                      style: TextStyle(color: Color(0xFF8892A4), fontSize: 14),
                    ),
                  ],
                ),
              ),

              const SizedBox(height: 48),

              // 登入表單
              Container(
                padding: const EdgeInsets.all(24),
                decoration: BoxDecoration(
                  color: const Color(0xFF141824),
                  borderRadius: BorderRadius.circular(16),
                  border: Border.all(color: const Color(0xFF1E2536)),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      '登入帳號',
                      style: TextStyle(
                        color: Colors.white,
                        fontSize: 20,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                    const SizedBox(height: 24),

                    // Email
                    const Text('Email', style: TextStyle(color: Color(0xFF8892A4), fontSize: 13)),
                    const SizedBox(height: 8),
                    _buildTextField(
                      controller: emailCtrl,
                      hint: 'admin@example.com',
                      icon: Icons.email_outlined,
                      keyboardType: TextInputType.emailAddress,
                    ),

                    const SizedBox(height: 16),

                    // Password
                    const Text('密碼', style: TextStyle(color: Color(0xFF8892A4), fontSize: 13)),
                    const SizedBox(height: 8),
                    Obx(() => _buildTextField(
                      controller: passwordCtrl,
                      hint: '••••••••',
                      icon: Icons.lock_outlined,
                      obscureText: obscure.value,
                      suffixIcon: IconButton(
                        icon: Icon(
                          obscure.value ? Icons.visibility_off : Icons.visibility,
                          color: const Color(0xFF8892A4),
                        ),
                        onPressed: () => obscure.value = !obscure.value,
                      ),
                    )),

                    const SizedBox(height: 8),

                    // 錯誤訊息
                    Obx(() => controller.errorMessage.value.isNotEmpty
                        ? Padding(
                            padding: const EdgeInsets.only(top: 8),
                            child: Row(
                              children: [
                                const Icon(Icons.error_outline, color: Color(0xFFFF4757), size: 16),
                                const SizedBox(width: 6),
                                Text(
                                  controller.errorMessage.value,
                                  style: const TextStyle(color: Color(0xFFFF4757), fontSize: 13),
                                ),
                              ],
                            ),
                          )
                        : const SizedBox.shrink()),

                    const SizedBox(height: 24),

                    // 登入按鈕
                    Obx(() => SizedBox(
                      width: double.infinity,
                      height: 52,
                      child: ElevatedButton(
                        onPressed: controller.isLoading.value
                            ? null
                            : () async {
                                final success = await controller.login(
                                  emailCtrl.text.trim(),
                                  passwordCtrl.text,
                                );
                                if (success) {
                                  Get.offAllNamed(AppConstants.routeHome);
                                }
                              },
                        style: ElevatedButton.styleFrom(
                          backgroundColor: const Color(0xFF0066FF),
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(12),
                          ),
                          elevation: 0,
                        ),
                        child: controller.isLoading.value
                            ? const SizedBox(
                                width: 22, height: 22,
                                child: CircularProgressIndicator(
                                  strokeWidth: 2, color: Colors.white,
                                ),
                              )
                            : const Text(
                                '登入',
                                style: TextStyle(
                                  color: Colors.white,
                                  fontSize: 16,
                                  fontWeight: FontWeight.bold,
                                ),
                              ),
                      ),
                    )),
                  ],
                ),
              ),

              const SizedBox(height: 24),

              Center(
                child: TextButton(
                  onPressed: () => Get.offAllNamed(AppConstants.routeHome),
                  child: const Text(
                    'Demo 模式 (不需登入)',
                    style: TextStyle(color: Color(0xFF00D2FF), fontSize: 13),
                  ),
                ),
              ),

              const SizedBox(height: 24),

              const Center(
                child: Text(
                  'Stock_Analysis v1.0.0\nLaravel 10 + Flutter',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: Color(0xFF3D4759), fontSize: 12),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildTextField({
    required TextEditingController controller,
    required String hint,
    required IconData icon,
    TextInputType? keyboardType,
    bool obscureText = false,
    Widget? suffixIcon,
  }) {
    return Container(
      decoration: BoxDecoration(
        color: const Color(0xFF0A0E1A),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: const Color(0xFF1E2536)),
      ),
      child: TextField(
        controller: controller,
        keyboardType: keyboardType,
        obscureText: obscureText,
        style: const TextStyle(color: Colors.white, fontSize: 15),
        decoration: InputDecoration(
          hintText: hint,
          hintStyle: const TextStyle(color: Color(0xFF3D4759)),
          prefixIcon: Icon(icon, color: const Color(0xFF8892A4), size: 20),
          suffixIcon: suffixIcon,
          border: InputBorder.none,
          contentPadding: const EdgeInsets.symmetric(vertical: 14),
        ),
      ),
    );
  }
}
