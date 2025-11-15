<template>
  <div class="settings-page">
    <v-row>
      <v-col cols="12" md="3">
        <!-- 設定選單 -->
        <v-card elevation="2">
          <v-list density="compact" nav>
            <v-list-item
              v-for="item in settingsSections"
              :key="item.value"
              :value="item.value"
              :active="selectedSection === item.value"
              @click="selectedSection = item.value"
            >
              <template v-slot:prepend>
                <v-icon>{{ item.icon }}</v-icon>
              </template>
              <v-list-item-title>{{ item.title }}</v-list-item-title>
            </v-list-item>
          </v-list>
        </v-card>
      </v-col>

      <v-col cols="12" md="9">
        <!-- 一般設定 -->
        <v-card v-if="selectedSection === 'general'" elevation="2" class="mb-4">
          <v-card-title>一般設定</v-card-title>
          <v-card-text>
            <v-select
              v-model="settings.language"
              :items="languages"
              label="語言"
              density="compact"
              class="mb-4"
            ></v-select>

            <v-select
              v-model="settings.timezone"
              :items="timezones"
              label="時區"
              density="compact"
              class="mb-4"
            ></v-select>

            <v-select
              v-model="settings.currency"
              :items="currencies"
              label="貨幣"
              density="compact"
              class="mb-4"
            ></v-select>

            <v-select
              v-model="settings.dateFormat"
              :items="dateFormats"
              label="日期格式"
              density="compact"
              class="mb-4"
            ></v-select>

            <v-btn color="primary" @click="saveSettings">儲存設定</v-btn>
          </v-card-text>
        </v-card>

        <!-- 交易設定 -->
        <v-card v-if="selectedSection === 'trading'" elevation="2" class="mb-4">
          <v-card-title>交易設定</v-card-title>
          <v-card-text>
            <v-text-field
              v-model.number="settings.defaultStopLoss"
              label="預設停損點"
              type="number"
              suffix="%"
              density="compact"
              class="mb-4"
            ></v-text-field>

            <v-text-field
              v-model.number="settings.defaultTakeProfit"
              label="預設停利點"
              type="number"
              suffix="%"
              density="compact"
              class="mb-4"
            ></v-text-field>

            <v-text-field
              v-model.number="settings.defaultPositionSize"
              label="預設部位大小"
              type="number"
              suffix="%"
              density="compact"
              class="mb-4"
            ></v-text-field>

            <v-text-field
              v-model.number="settings.commission"
              label="手續費率"
              type="number"
              suffix="%"
              density="compact"
              class="mb-4"
            ></v-text-field>

            <v-switch
              v-model="settings.confirmBeforeTrade"
              label="交易前確認"
              color="primary"
              class="mb-4"
            ></v-switch>

            <v-switch
              v-model="settings.enableAutoTrading"
              label="啟用自動交易"
              color="primary"
              class="mb-4"
            ></v-switch>

            <v-btn color="primary" @click="saveSettings">儲存設定</v-btn>
          </v-card-text>
        </v-card>

        <!-- 通知設定 -->
        <v-card v-if="selectedSection === 'notifications'" elevation="2" class="mb-4">
          <v-card-title>通知設定</v-card-title>
          <v-card-text>
            <v-switch
              v-model="settings.emailNotifications"
              label="電子郵件通知"
              color="primary"
              class="mb-4"
            ></v-switch>

            <v-switch
              v-model="settings.pushNotifications"
              label="推播通知"
              color="primary"
              class="mb-4"
            ></v-switch>

            <v-switch
              v-model="settings.priceAlerts"
              label="價格警示"
              color="primary"
              class="mb-4"
            ></v-switch>

            <v-switch
              v-model="settings.tradeAlerts"
              label="交易警示"
              color="primary"
              class="mb-4"
            ></v-switch>

            <v-switch
              v-model="settings.systemAlerts"
              label="系統警示"
              color="primary"
              class="mb-4"
            ></v-switch>

            <v-text-field
              v-model="settings.notificationEmail"
              label="通知電子郵件"
              type="email"
              density="compact"
              class="mb-4"
            ></v-text-field>

            <v-btn color="primary" @click="saveSettings">儲存設定</v-btn>
          </v-card-text>
        </v-card>

        <!-- API 設定 -->
        <v-card v-if="selectedSection === 'api'" elevation="2" class="mb-4">
          <v-card-title>API 設定</v-card-title>
          <v-card-text>
            <v-text-field
              v-model="settings.apiKey"
              label="API 金鑰"
              type="password"
              density="compact"
              class="mb-4"
              append-inner-icon="mdi-eye"
            ></v-text-field>

            <v-text-field
              v-model="settings.apiSecret"
              label="API 密鑰"
              type="password"
              density="compact"
              class="mb-4"
              append-inner-icon="mdi-eye"
            ></v-text-field>

            <v-select
              v-model="settings.dataProvider"
              :items="dataProviders"
              label="資料提供商"
              density="compact"
              class="mb-4"
            ></v-select>

            <v-switch
              v-model="settings.enableAPI"
              label="啟用 API"
              color="primary"
              class="mb-4"
            ></v-switch>

            <v-btn color="primary" @click="saveSettings">儲存設定</v-btn>
            <v-btn color="secondary" class="ml-2" @click="testConnection">測試連線</v-btn>
          </v-card-text>
        </v-card>

        <!-- 顯示設定 -->
        <v-card v-if="selectedSection === 'display'" elevation="2" class="mb-4">
          <v-card-title>顯示設定</v-card-title>
          <v-card-text>
            <v-switch
              v-model="settings.darkMode"
              label="深色模式"
              color="primary"
              class="mb-4"
            ></v-switch>

            <v-select
              v-model="settings.chartType"
              :items="chartTypes"
              label="預設圖表類型"
              density="compact"
              class="mb-4"
            ></v-select>

            <v-select
              v-model="settings.tableRowsPerPage"
              :items="[10, 15, 20, 25, 50]"
              label="每頁顯示筆數"
              type="number"
              density="compact"
              class="mb-4"
            ></v-select>

            <v-switch
              v-model="settings.showGridLines"
              label="顯示格線"
              color="primary"
              class="mb-4"
            ></v-switch>

            <v-switch
              v-model="settings.compactView"
              label="緊湊視圖"
              color="primary"
              class="mb-4"
            ></v-switch>

            <v-btn color="primary" @click="saveSettings">儲存設定</v-btn>
          </v-card-text>
        </v-card>

        <!-- 安全設定 -->
        <v-card v-if="selectedSection === 'security'" elevation="2" class="mb-4">
          <v-card-title>安全設定</v-card-title>
          <v-card-text>
            <v-switch
              v-model="settings.twoFactorAuth"
              label="雙因素認證"
              color="primary"
              class="mb-4"
            ></v-switch>

            <v-text-field
              v-model="settings.currentPassword"
              label="當前密碼"
              type="password"
              density="compact"
              class="mb-4"
            ></v-text-field>

            <v-text-field
              v-model="settings.newPassword"
              label="新密碼"
              type="password"
              density="compact"
              class="mb-4"
            ></v-text-field>

            <v-text-field
              v-model="settings.confirmPassword"
              label="確認新密碼"
              type="password"
              density="compact"
              class="mb-4"
            ></v-text-field>

            <v-select
              v-model="settings.sessionTimeout"
              :items="sessionTimeouts"
              label="工作階段逾時"
              density="compact"
              class="mb-4"
            ></v-select>

            <v-btn color="primary" @click="changePassword">變更密碼</v-btn>
          </v-card-text>
        </v-card>

        <!-- 資料管理 -->
        <v-card v-if="selectedSection === 'data'" elevation="2" class="mb-4">
          <v-card-title>資料管理</v-card-title>
          <v-card-text>
            <v-list>
              <v-list-item>
                <v-list-item-title>匯出所有資料</v-list-item-title>
                <v-list-item-subtitle>下載您的所有交易記錄和設定</v-list-item-subtitle>
                <template v-slot:append>
                  <v-btn color="primary" variant="outlined" @click="exportData">匯出</v-btn>
                </template>
              </v-list-item>

              <v-list-item>
                <v-list-item-title>匯入資料</v-list-item-title>
                <v-list-item-subtitle>從檔案匯入交易記錄</v-list-item-subtitle>
                <template v-slot:append>
                  <v-btn color="primary" variant="outlined" @click="importData">匯入</v-btn>
                </template>
              </v-list-item>

              <v-list-item>
                <v-list-item-title>清除快取</v-list-item-title>
                <v-list-item-subtitle>清除所有暫存資料</v-list-item-subtitle>
                <template v-slot:append>
                  <v-btn color="warning" variant="outlined" @click="clearCache">清除</v-btn>
                </template>
              </v-list-item>

              <v-list-item>
                <v-list-item-title>重置所有設定</v-list-item-title>
                <v-list-item-subtitle>將所有設定恢復為預設值</v-list-item-subtitle>
                <template v-slot:append>
                  <v-btn color="error" variant="outlined" @click="resetSettings">重置</v-btn>
                </template>
              </v-list-item>
            </v-list>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>
  </div>
</template>

<script>
import { ref } from 'vue'

export default {
  name: 'Settings',
  setup() {
    // 選擇的設定區塊
    const selectedSection = ref('general')

    // 設定選單
    const settingsSections = [
      { title: '一般設定', value: 'general', icon: 'mdi-cog' },
      { title: '交易設定', value: 'trading', icon: 'mdi-chart-line' },
      { title: '通知設定', value: 'notifications', icon: 'mdi-bell' },
      { title: 'API 設定', value: 'api', icon: 'mdi-api' },
      { title: '顯示設定', value: 'display', icon: 'mdi-monitor' },
      { title: '安全設定', value: 'security', icon: 'mdi-shield-lock' },
      { title: '資料管理', value: 'data', icon: 'mdi-database' }
    ]

    // 設定選項
    const languages = ['繁體中文', 'English', '简体中文', '日本語']
    const timezones = ['Asia/Taipei', 'Asia/Tokyo', 'America/New_York', 'Europe/London']
    const currencies = ['TWD', 'USD', 'JPY', 'EUR']
    const dateFormats = ['YYYY-MM-DD', 'DD/MM/YYYY', 'MM/DD/YYYY']
    const dataProviders = ['TWSE', 'Yahoo Finance', 'Alpha Vantage', 'Bloomberg']
    const chartTypes = ['線圖', 'K線圖', '美國線', '柱狀圖']
    const sessionTimeouts = ['15 分鐘', '30 分鐘', '1 小時', '2 小時', '4 小時']

    // 設定值
    const settings = ref({
      // 一般設定
      language: '繁體中文',
      timezone: 'Asia/Taipei',
      currency: 'TWD',
      dateFormat: 'YYYY-MM-DD',

      // 交易設定
      defaultStopLoss: 5,
      defaultTakeProfit: 10,
      defaultPositionSize: 100,
      commission: 0.1425,
      confirmBeforeTrade: true,
      enableAutoTrading: false,

      // 通知設定
      emailNotifications: true,
      pushNotifications: true,
      priceAlerts: true,
      tradeAlerts: true,
      systemAlerts: true,
      notificationEmail: 'user@example.com',

      // API 設定
      apiKey: '',
      apiSecret: '',
      dataProvider: 'TWSE',
      enableAPI: false,

      // 顯示設定
      darkMode: false,
      chartType: '線圖',
      tableRowsPerPage: 15,
      showGridLines: true,
      compactView: false,

      // 安全設定
      twoFactorAuth: false,
      currentPassword: '',
      newPassword: '',
      confirmPassword: '',
      sessionTimeout: '30 分鐘'
    })

    // 方法
    const saveSettings = () => {
      console.log('儲存設定:', settings.value)
      // 呼叫 API 儲存設定
    }

    const testConnection = () => {
      console.log('測試 API 連線')
    }

    const changePassword = () => {
      if (settings.value.newPassword !== settings.value.confirmPassword) {
        alert('新密碼與確認密碼不符')
        return
      }
      console.log('變更密碼')
    }

    const exportData = () => {
      console.log('匯出資料')
    }

    const importData = () => {
      console.log('匯入資料')
    }

    const clearCache = () => {
      console.log('清除快取')
    }

    const resetSettings = () => {
      if (confirm('確定要重置所有設定嗎?')) {
        console.log('重置設定')
      }
    }

    return {
      selectedSection,
      settingsSections,
      languages,
      timezones,
      currencies,
      dateFormats,
      dataProviders,
      chartTypes,
      sessionTimeouts,
      settings,
      saveSettings,
      testConnection,
      changePassword,
      exportData,
      importData,
      clearCache,
      resetSettings
    }
  }
}
</script>

<style scoped>
.settings-page {
  padding: 16px;
}
</style>