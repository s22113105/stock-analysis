<template>
  <div class="option-chain-container">
    <!-- 頁面標題與控制區 -->
    <v-container fluid>
      <v-row class="mb-4">
        <v-col cols="12" md="6">
          <h1 class="text-h4 font-weight-bold mb-2">
            <v-icon color="primary" class="mr-2">mdi-table-split-cell</v-icon>
            選擇權 T 字報價
          </h1>
          <p class="text-subtitle-1 text-grey">
            TXO 臺指選擇權即時行情 | 資料日期: {{ tradeDate || '載入中...' }}
          </p>
        </v-col>

        <v-col cols="12" md="6" class="d-flex align-center justify-end">
          <!-- 到期日選擇 -->
          <v-select
            v-model="selectedExpiry"
            :items="availableExpiries"
            label="選擇到期日"
            variant="outlined"
            density="compact"
            hide-details
            class="mr-3"
            style="max-width: 200px"
            :loading="loading"
            @update:model-value="loadOptionChain"
          >
            <template v-slot:item="{ item, props }">
              <v-list-item v-bind="props">
                <v-list-item-title>{{ formatExpiryDate(item.value) }}</v-list-item-title>
              </v-list-item>
            </template>
            <template v-slot:selection="{ item }">
              {{ formatExpiryDate(item.value) }}
            </template>
          </v-select>

          <!-- 更新按鈕 -->
          <v-btn
            color="primary"
            variant="flat"
            prepend-icon="mdi-refresh"
            :loading="loading"
            @click="refreshData"
          >
            更新報價
          </v-btn>
        </v-col>
      </v-row>

      <!-- 錯誤訊息 -->
      <v-alert
        v-if="errorMessage"
        type="error"
        variant="tonal"
        closable
        class="mb-4"
        @click:close="errorMessage = ''"
      >
        {{ errorMessage }}
      </v-alert>

      <!-- 市場資訊卡片 -->
      <v-row class="mb-4">
        <v-col cols="12" md="3">
          <v-card variant="tonal" color="primary">
            <v-card-text class="text-center">
              <div class="text-caption">現貨價格</div>
              <div class="text-h5 font-weight-bold">{{ formatNumber(spotPrice) }}</div>
            </v-card-text>
          </v-card>
        </v-col>
        <v-col cols="12" md="3">
          <v-card variant="tonal" color="warning">
            <v-card-text class="text-center">
              <div class="text-caption">ATM 履約價</div>
              <div class="text-h5 font-weight-bold">{{ formatNumber(atmStrike) }}</div>
            </v-card-text>
          </v-card>
        </v-col>
        <v-col cols="12" md="3">
          <v-card variant="tonal" color="success">
            <v-card-text class="text-center">
              <div class="text-caption">總履約價數</div>
              <div class="text-h5 font-weight-bold">{{ totalStrikes }}</div>
            </v-card-text>
          </v-card>
        </v-col>
        <v-col cols="12" md="3">
          <v-card variant="tonal" color="info">
            <v-card-text class="text-center">
              <div class="text-caption">市場狀態</div>
              <div class="text-h5 font-weight-bold">{{ marketStatus }}</div>
            </v-card-text>
          </v-card>
        </v-col>
      </v-row>

      <!-- 選擇權 T 字報價表 -->
      <v-card>
        <v-card-title class="bg-grey-darken-3 text-white">
          <v-row no-gutters>
            <v-col cols="5" class="text-center">
              <v-icon color="red">mdi-arrow-up-bold</v-icon>
              CALL (買權)
            </v-col>
            <v-col cols="2" class="text-center">
              履約價
            </v-col>
            <v-col cols="5" class="text-center">
              <v-icon color="green">mdi-arrow-down-bold</v-icon>
              PUT (賣權)
            </v-col>
          </v-row>
        </v-card-title>

        <v-card-text class="pa-0">
          <v-table
            dense
            fixed-header
            height="600"
            class="option-chain-table"
          >
            <thead>
              <tr>
                <!-- CALL 欄位 -->
                <th class="text-right">Delta</th>
                <th class="text-right">IV%</th>
                <th class="text-right">OI</th>
                <th class="text-right">成交量</th>
                <th class="text-right font-weight-bold text-red">成交價</th>
                <!-- 履約價 -->
                <th class="text-center bg-grey-lighten-3 font-weight-bold">Strike</th>
                <!-- PUT 欄位 -->
                <th class="text-left font-weight-bold text-green">成交價</th>
                <th class="text-left">成交量</th>
                <th class="text-left">OI</th>
                <th class="text-left">IV%</th>
                <th class="text-left">Delta</th>
              </tr>
            </thead>
            <tbody>
              <!-- Loading 狀態 -->
              <tr v-if="loading">
                <td colspan="11" class="text-center py-10">
                  <v-progress-circular
                    indeterminate
                    color="primary"
                    size="64"
                  ></v-progress-circular>
                  <div class="mt-3">載入中...</div>
                </td>
              </tr>

              <!-- 無資料狀態 -->
              <tr v-else-if="!chainData || chainData.length === 0">
                <td colspan="11" class="text-center py-10">
                  <v-icon size="64" color="grey">mdi-database-off</v-icon>
                  <div class="mt-3 text-h6">目前無選擇權報價資料</div>
                  <div class="text-caption text-grey">請稍後再試或聯繫系統管理員</div>
                  <v-btn
                    class="mt-3"
                    color="primary"
                    variant="tonal"
                    @click="testConnection"
                  >
                    測試資料庫連線
                  </v-btn>
                </td>
              </tr>

              <!-- 資料列 -->
              <template v-else>
                <tr
                  v-for="row in chainData"
                  :key="row.strike"
                  :class="{
                    'bg-yellow-lighten-5': row.is_atm,
                    'option-row': true
                  }"
                >
                  <!-- CALL 資料 -->
                  <td class="text-right" :class="getCallBgClass(row.call)">
                    {{ formatDecimal(row.call?.delta) }}
                  </td>
                  <td class="text-right" :class="getCallBgClass(row.call)">
                    {{ formatPercent(row.call?.iv) }}
                  </td>
                  <td class="text-right" :class="getCallBgClass(row.call)">
                    {{ formatNumber(row.call?.oi) }}
                  </td>
                  <td class="text-right" :class="getCallBgClass(row.call)">
                    {{ formatNumber(row.call?.volume) }}
                  </td>
                  <td class="text-right font-weight-bold" :class="getCallPriceBgClass(row.call)">
                    {{ formatPrice(row.call?.price) }}
                  </td>

                  <!-- 履約價 -->
                  <td class="text-center bg-grey-lighten-2 font-weight-bold strike-column">
                    {{ row.strike }}
                  </td>

                  <!-- PUT 資料 -->
                  <td class="text-left font-weight-bold" :class="getPutPriceBgClass(row.put)">
                    {{ formatPrice(row.put?.price) }}
                  </td>
                  <td class="text-left" :class="getPutBgClass(row.put)">
                    {{ formatNumber(row.put?.volume) }}
                  </td>
                  <td class="text-left" :class="getPutBgClass(row.put)">
                    {{ formatNumber(row.put?.oi) }}
                  </td>
                  <td class="text-left" :class="getPutBgClass(row.put)">
                    {{ formatPercent(row.put?.iv) }}
                  </td>
                  <td class="text-left" :class="getPutBgClass(row.put)">
                    {{ formatDecimal(row.put?.delta) }}
                  </td>
                </tr>
              </template>
            </tbody>
          </v-table>
        </v-card-text>
      </v-card>

      <!-- 除錯資訊（開發模式） -->
      <v-expansion-panels v-if="debugMode" class="mt-4">
        <v-expansion-panel>
          <v-expansion-panel-title>
            <v-icon class="mr-2">mdi-bug</v-icon>
            除錯資訊
          </v-expansion-panel-title>
          <v-expansion-panel-text>
            <pre>{{ JSON.stringify(debugInfo, null, 2) }}</pre>
          </v-expansion-panel-text>
        </v-expansion-panel>
      </v-expansion-panels>
    </v-container>
  </div>
</template>

<script>
import { ref, onMounted, computed, watch } from 'vue'
import axios from 'axios'

export default {
  name: 'Options',
  setup() {
    // 狀態變數
    const loading = ref(false)
    const errorMessage = ref('')
    const chainData = ref([])
    const availableExpiries = ref([])
    const selectedExpiry = ref(null)
    const tradeDate = ref('')
    const atmStrike = ref(0)
    const spotPrice = ref(0)
    const totalStrikes = ref(0)
    const marketStatus = ref('TAIFEX')
    const debugMode = ref(false) // 可以在開發時設為 true
    const debugInfo = ref({})

    // 計算屬性
    const hasData = computed(() => chainData.value && chainData.value.length > 0)

    // 載入選擇權鏈資料
    const loadOptionChain = async () => {
      loading.value = true
      errorMessage.value = ''

      try {
        const params = {}
        if (selectedExpiry.value) {
          params.expiry_date = selectedExpiry.value
        }

        console.log('正在載入選擇權鏈資料...', params)

        const response = await axios.get('/options/chain-table', { params })
        const result = response.data

        if (result.success && result.data) {
          const data = result.data

          // 更新資料
          chainData.value = data.chain || []
          availableExpiries.value = data.available_expiries || []
          tradeDate.value = data.trade_date || '無資料'
          atmStrike.value = data.atm_strike || 0
          spotPrice.value = data.spot_price || 0
          totalStrikes.value = data.total_strikes || 0

          // 如果沒有選擇到期日，自動選擇第一個
          if (!selectedExpiry.value && availableExpiries.value.length > 0) {
            selectedExpiry.value = data.expiry_date || availableExpiries.value[0]
          }

          // 更新除錯資訊
          debugInfo.value = {
            chainCount: chainData.value.length,
            availableExpiries: availableExpiries.value,
            selectedExpiry: selectedExpiry.value,
            tradeDate: tradeDate.value,
            responseData: data
          }

          console.log('選擇權鏈資料載入成功', debugInfo.value)
        } else {
          throw new Error(result.message || '無法取得資料')
        }
      } catch (error) {
        console.error('載入選擇權鏈失敗:', error)
        errorMessage.value = error.response?.data?.message || error.message || '載入資料失敗，請稍後再試'

        // 清空資料
        chainData.value = []

        // 更新除錯資訊
        debugInfo.value = {
          error: error.message,
          response: error.response?.data
        }
      } finally {
        loading.value = false
      }
    }

    // 更新資料
    const refreshData = () => {
      loadOptionChain()
    }

    // 測試資料庫連線
    const testConnection = async () => {
      try {
        const response = await axios.get('/debug/data-check')
        console.log('資料庫連線測試結果:', response.data)
        alert('資料庫連線測試結果:\n' + JSON.stringify(response.data, null, 2))
      } catch (error) {
        console.error('測試失敗:', error)
        alert('測試失敗: ' + error.message)
      }
    }

    // 取得市場狀態
    const getMarketStatus = async () => {
      try {
        // const response = await axios.get('/api/options/chain-table/market-status')
        if (response.data.success) {
          marketStatus.value = response.data.data.market_status
        }
      } catch (error) {
        marketStatus.value = '未知'
      }
    }

    // 格式化函數
    const formatNumber = (value) => {
      if (value === null || value === undefined || value === 0) return '-'
      return Number(value).toLocaleString()
    }

    const formatPrice = (value) => {
      if (value === null || value === undefined || value === 0) return '-'
      return Number(value).toFixed(2)
    }

    const formatDecimal = (value, digits = 2) => {
      if (value === null || value === undefined) return '-'
      return Number(value).toFixed(digits)
    }

    const formatPercent = (value) => {
      if (value === null || value === undefined || value === 0) return '-'
      return Number(value).toFixed(1) + '%'
    }

    const formatExpiryDate = (dateStr) => {
      if (!dateStr) return ''
      const date = new Date(dateStr)
      const month = String(date.getMonth() + 1).padStart(2, '0')
      const day = String(date.getDate()).padStart(2, '0')
      return `${month}/${day}`
    }

    // 樣式類別函數
    const getCallBgClass = (call) => {
      if (!call) return ''
      return call.is_itm ? 'bg-red-lighten-5' : ''
    }

    const getCallPriceBgClass = (call) => {
      if (!call) return ''
      return call.is_itm ? 'bg-red-lighten-4 text-red-darken-2' : 'text-red'
    }

    const getPutBgClass = (put) => {
      if (!put) return ''
      return put.is_itm ? 'bg-green-lighten-5' : ''
    }

    const getPutPriceBgClass = (put) => {
      if (!put) return ''
      return put.is_itm ? 'bg-green-lighten-4 text-green-darken-2' : 'text-green'
    }

    // 生命週期
    onMounted(() => {
      marketStatus.value = 'TAIFEX'
      loadOptionChain()
    })

    // 監聽到期日變更
    watch(selectedExpiry, (newVal) => {
      if (newVal) {
        console.log('到期日變更為:', newVal)
      }
    })

    return {
      // 狀態
      loading,
      errorMessage,
      chainData,
      availableExpiries,
      selectedExpiry,
      tradeDate,
      atmStrike,
      spotPrice,
      totalStrikes,
      marketStatus,
      hasData,
      debugMode,
      debugInfo,

      // 方法
      loadOptionChain,
      refreshData,
      testConnection,

      // 格式化函數
      formatNumber,
      formatPrice,
      formatDecimal,
      formatPercent,
      formatExpiryDate,

      // 樣式函數
      getCallBgClass,
      getCallPriceBgClass,
      getPutBgClass,
      getPutPriceBgClass
    }
  }
}
</script>

<style scoped>
.option-chain-container {
  min-height: 100vh;
  background: linear-gradient(135deg, #f5f5f5 0%, #ffffff 100%);
}

.option-chain-table {
  font-size: 0.875rem;
}

.option-chain-table th {
  font-size: 0.75rem;
  white-space: nowrap;
  padding: 8px 4px !important;
}

.option-chain-table td {
  padding: 4px 8px !important;
  border-bottom: 1px solid rgba(0, 0, 0, 0.05);
}

.option-row:hover {
  background-color: rgba(0, 0, 0, 0.02);
}

.strike-column {
  position: sticky;
  left: 0;
  z-index: 1;
  font-size: 0.9rem;
  border-left: 2px solid #e0e0e0;
  border-right: 2px solid #e0e0e0;
}

/* ITM 背景色 */
.bg-red-lighten-5 {
  background-color: rgba(255, 235, 238, 0.5);
}

.bg-red-lighten-4 {
  background-color: rgba(255, 205, 210, 0.7);
}

.bg-green-lighten-5 {
  background-color: rgba(232, 245, 233, 0.5);
}

.bg-green-lighten-4 {
  background-color: rgba(200, 230, 201, 0.7);
}

.bg-yellow-lighten-5 {
  background-color: rgba(255, 253, 231, 0.8);
}

/* 固定表頭樣式 */
.v-table > .v-table__wrapper > table > thead > tr > th {
  background-color: #f5f5f5;
  position: sticky;
  top: 0;
  z-index: 2;
}
</style>
