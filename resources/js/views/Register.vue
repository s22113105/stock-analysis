<template>
  <v-app>
    <v-main class="register-container d-flex align-center">
      <v-container>
        <v-row justify="center">
          <v-col cols="12" sm="8" md="6" lg="5" xl="4">
            <!-- Logo and Title -->
            <div class="text-center mb-6">
              <v-icon size="80" color="white">mdi-chart-line</v-icon>
              <h1 class="text-h3 font-weight-bold text-white mt-4">建立新帳號</h1>
              <p class="text-subtitle-1 text-white-70">開始使用 Stock_Analysis 系統</p>
            </div>

            <!-- Register Card -->
            <v-card elevation="10" rounded="lg">
              <v-card-text class="pa-8">
                <v-form @submit.prevent="handleRegister">
                  <!-- Name Field -->
                  <v-text-field
                    v-model="name"
                    :error-messages="errors.name"
                    label="姓名"
                    prepend-inner-icon="mdi-account"
                    variant="outlined"
                    :rules="nameRules"
                    class="mb-4"
                  ></v-text-field>

                  <!-- Email Field -->
                  <v-text-field
                    v-model="email"
                    :error-messages="errors.email"
                    label="Email"
                    type="email"
                    prepend-inner-icon="mdi-email"
                    variant="outlined"
                    :rules="emailRules"
                    class="mb-4"
                  ></v-text-field>

                  <!-- Password Field -->
                  <v-text-field
                    v-model="password"
                    :error-messages="errors.password"
                    label="密碼"
                    :type="showPassword ? 'text' : 'password'"
                    prepend-inner-icon="mdi-lock"
                    :append-inner-icon="showPassword ? 'mdi-eye-off' : 'mdi-eye'"
                    @click:append-inner="showPassword = !showPassword"
                    variant="outlined"
                    :rules="passwordRules"
                    class="mb-4"
                  ></v-text-field>

                  <!-- Password Confirmation Field -->
                  <v-text-field
                    v-model="passwordConfirmation"
                    label="確認密碼"
                    :type="showPasswordConfirm ? 'text' : 'password'"
                    prepend-inner-icon="mdi-lock-check"
                    :append-inner-icon="showPasswordConfirm ? 'mdi-eye-off' : 'mdi-eye'"
                    @click:append-inner="showPasswordConfirm = !showPasswordConfirm"
                    variant="outlined"
                    :rules="passwordConfirmRules"
                    class="mb-4"
                  ></v-text-field>

                  <!-- Terms Agreement -->
                  <v-checkbox
                    v-model="agreedToTerms"
                    class="mb-4"
                  >
                    <template v-slot:label>
                      <div>
                        我同意
                        <a href="#" class="text-primary">服務條款</a>
                        和
                        <a href="#" class="text-primary">隱私政策</a>
                      </div>
                    </template>
                  </v-checkbox>

                  <!-- Error Alert -->
                  <v-alert
                    v-if="errorMessage"
                    type="error"
                    variant="tonal"
                    class="mb-4"
                    closable
                    @click:close="errorMessage = ''"
                  >
                    {{ errorMessage }}
                  </v-alert>

                  <!-- Register Button -->
                  <v-btn
                    type="submit"
                    color="primary"
                    size="large"
                    block
                    :loading="loading"
                    :disabled="!agreedToTerms"
                    class="mb-4"
                  >
                    <v-icon left>mdi-account-plus</v-icon>
                    註冊
                  </v-btn>

                  <!-- Divider -->
                  <v-divider class="my-4"></v-divider>

                  <!-- Login Link -->
                  <div class="text-center">
                    <span class="text-grey">已經有帳號了?</span>
                    <router-link to="/login" class="text-primary text-decoration-none ml-1">
                      立即登入
                    </router-link>
                  </div>
                </v-form>
              </v-card-text>
            </v-card>
          </v-col>
        </v-row>
      </v-container>
    </v-main>
  </v-app>
</template>

<script>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'

export default {
  name: 'Register',

  setup() {
    const router = useRouter()

    // 表單資料
    const name = ref('')
    const email = ref('')
    const password = ref('')
    const passwordConfirmation = ref('')
    const agreedToTerms = ref(false)
    const showPassword = ref(false)
    const showPasswordConfirm = ref(false)

    // 狀態
    const loading = ref(false)
    const errorMessage = ref('')
    const errors = ref({
      name: [],
      email: [],
      password: []
    })

    // 驗證規則
    const nameRules = [
      v => !!v || '姓名為必填',
      v => v.length >= 2 || '姓名至少需要 2 個字元'
    ]

    const emailRules = [
      v => !!v || 'Email 為必填',
      v => /.+@.+\..+/.test(v) || 'Email 格式不正確'
    ]

    const passwordRules = [
      v => !!v || '密碼為必填',
      v => v.length >= 8 || '密碼至少需要 8 個字元',
      v => /[A-Z]/.test(v) || '密碼需包含至少一個大寫字母',
      v => /[a-z]/.test(v) || '密碼需包含至少一個小寫字母',
      v => /[0-9]/.test(v) || '密碼需包含至少一個數字'
    ]

    const passwordConfirmRules = [
      v => !!v || '請確認密碼',
      v => v === password.value || '兩次輸入的密碼不一致'
    ]

    // 處理註冊
    const handleRegister = async () => {
      loading.value = true
      errorMessage.value = ''
      errors.value = { name: [], email: [], password: [] }

      try {
        // ✅ 修正:移除 /api 前綴,因為 baseURL 已經是 /api
        const response = await axios.post('auth/register', {
          name: name.value,
          email: email.value,
          password: password.value,
          password_confirmation: passwordConfirmation.value
        })

        if (response.data.success) {
          // 儲存 token
          const token = response.data.data.token
          localStorage.setItem('authToken', token)
          
          // 設定 axios 預設 header
          axios.defaults.headers.common['Authorization'] = `Bearer ${token}`

          // 儲存使用者資訊
          localStorage.setItem('user', JSON.stringify(response.data.data.user))

          // 顯示成功訊息
          console.log('✅ 註冊成功!導向儀表板...')

          // 導向儀表板
          router.push('/dashboard')
        }
      } catch (error) {
        console.error('❌ 註冊錯誤:', error)
        
        if (error.response) {
          if (error.response.status === 422) {
            // 驗證錯誤
            const validationErrors = error.response.data.errors
            if (validationErrors.name) {
              errors.value.name = validationErrors.name
            }
            if (validationErrors.email) {
              errors.value.email = validationErrors.email
            }
            if (validationErrors.password) {
              errors.value.password = validationErrors.password
            }
          } else {
            errorMessage.value = error.response.data.message || '註冊失敗,請稍後再試'
          }
        } else {
          errorMessage.value = '網路連線錯誤,請檢查您的網路連線'
        }
      } finally {
        loading.value = false
      }
    }

    return {
      name,
      email,
      password,
      passwordConfirmation,
      agreedToTerms,
      showPassword,
      showPasswordConfirm,
      loading,
      errorMessage,
      errors,
      nameRules,
      emailRules,
      passwordRules,
      passwordConfirmRules,
      handleRegister
    }
  }
}
</script>

<style scoped>
.register-container {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  min-height: 100vh;
}

.v-card {
  backdrop-filter: blur(10px);
}

.text-white-70 {
  color: rgba(255, 255, 255, 0.7);
}
</style>