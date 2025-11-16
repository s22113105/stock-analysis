<template>
  <v-app>
    <v-main class="login-container d-flex align-center">
      <v-container>
        <v-row justify="center">
          <v-col cols="12" sm="8" md="6" lg="5" xl="4">
            <!-- Logo and Title -->
            <div class="text-center mb-6">
              <v-icon size="80" color="white">mdi-chart-line</v-icon>
              <h1 class="text-h3 font-weight-bold text-white mt-4">Stock_Analysis</h1>
              <p class="text-subtitle-1 text-white-70">台股選擇權交易分析系統</p>
            </div>

            <!-- Login Card -->
            <v-card elevation="10" rounded="lg">
              <v-card-text class="pa-8">
                <v-form @submit.prevent="handleLogin">
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

                  <!-- Remember Me -->
                  <v-checkbox
                    v-model="rememberMe"
                    label="記住我"
                    class="mb-4"
                  ></v-checkbox>

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

                  <!-- Login Button -->
                  <v-btn
                    type="submit"
                    color="primary"
                    size="large"
                    block
                    :loading="loading"
                    class="mb-4"
                  >
                    <v-icon left>mdi-login</v-icon>
                    登入
                  </v-btn>

                  <!-- Divider -->
                  <v-divider class="my-4"></v-divider>

                  <!-- Register Link -->
                  <div class="text-center">
                    <span class="text-grey">還沒有帳號?</span>
                    <router-link to="/register" class="text-primary text-decoration-none ml-1">
                      立即註冊
                    </router-link>
                  </div>
                </v-form>
              </v-card-text>
            </v-card>

            <!-- Demo Credentials -->
            <v-card class="mt-4" variant="tonal" color="info">
              <v-card-text>
                <div class="text-center">
                  <v-icon size="small" class="mr-2">mdi-information</v-icon>
                  <strong>示範帳號:</strong> demo@stock.com | 密碼: demo1234
                </div>
              </v-card-text>
            </v-card>
          </v-col>
        </v-row>
      </v-container>
    </v-main>
  </v-app>
</template>

<script>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'

export default {
  name: 'Login',

  setup() {
    const router = useRouter()

    // 表單資料
    const email = ref('')
    const password = ref('')
    const rememberMe = ref(false)
    const showPassword = ref(false)

    // 狀態
    const loading = ref(false)
    const errorMessage = ref('')
    const errors = ref({
      email: [],
      password: []
    })

    // 驗證規則
    const emailRules = [
      v => !!v || 'Email 為必填',
      v => /.+@.+\..+/.test(v) || 'Email 格式不正確'
    ]

    const passwordRules = [
      v => !!v || '密碼為必填',
      v => v.length >= 6 || '密碼至少需要 6 個字元'
    ]

    // 處理登入
    const handleLogin = async () => {
      loading.value = true
      errorMessage.value = ''
      errors.value = { email: [], password: [] }

      try {
        // ✅ 修正:移除 /api 前綴,因為 baseURL 已經是 /api
        const response = await axios.post('auth/login', {
          email: email.value,
          password: password.value
        })

        if (response.data.success) {
          // 儲存 token
          const token = response.data.data.token
          localStorage.setItem('authToken', token)
          
          // 設定 axios 預設 header
          axios.defaults.headers.common['Authorization'] = `Bearer ${token}`

          // 儲存使用者資訊
          localStorage.setItem('user', JSON.stringify(response.data.data.user))

          // 記住我
          if (rememberMe.value) {
            localStorage.setItem('rememberMe', 'true')
            localStorage.setItem('savedEmail', email.value)
          } else {
            localStorage.removeItem('rememberMe')
            localStorage.removeItem('savedEmail')
          }

          // 顯示成功訊息
          console.log('✅ 登入成功!導向儀表板...')

          // 導向儀表板
          router.push('/dashboard')
        }
      } catch (error) {
        console.error('❌ 登入錯誤:', error)
        
        if (error.response) {
          if (error.response.status === 422) {
            // 驗證錯誤
            const validationErrors = error.response.data.errors
            if (validationErrors.email) {
              errors.value.email = validationErrors.email
            }
            if (validationErrors.password) {
              errors.value.password = validationErrors.password
            }
          } else if (error.response.status === 401) {
            // 帳號密碼錯誤
            errorMessage.value = error.response.data.message || 'Email 或密碼錯誤'
          } else {
            errorMessage.value = '登入失敗,請稍後再試'
          }
        } else {
          errorMessage.value = '網路連線錯誤,請檢查您的網路連線'
        }
      } finally {
        loading.value = false
      }
    }

    // 載入記住的 Email
    const loadRememberedEmail = () => {
      if (localStorage.getItem('rememberMe') === 'true') {
        email.value = localStorage.getItem('savedEmail') || ''
        rememberMe.value = true
      }
    }

    // 初始化
    loadRememberedEmail()

    return {
      email,
      password,
      rememberMe,
      showPassword,
      loading,
      errorMessage,
      errors,
      emailRules,
      passwordRules,
      handleLogin
    }
  }
}
</script>

<style scoped>
.login-container {
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