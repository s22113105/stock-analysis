<template>
  <div class="option-chain-page pa-4">
    <!-- 1. 控制列 -->
    <v-row class="align-center mb-4">
      <v-col cols="12" md="5">
        <h1 class="text-h4 font-weight-bold">
          <v-icon color="primary" class="mr-2">mdi-table-arrow-right</v-icon>
          選擇權 T 字報價
        </h1>
        <p class="text-subtitle-1 text-grey mt-1">TXO 臺指選擇權 - 即時行情</p>
      </v-col>

      <!-- 到期日選擇 -->
      <v-col cols="12" md="3">
        <v-select
          v-model="selectedExpiry"
          :items="availableExpiries"
          label="合約月份 (到期日)"
          variant="outlined"
          density="compact"
          hide-details
          prepend-inner-icon="mdi-calendar"
          @update:model-value="loadChainData"
          :disabled="loading"
          bg-color="white"
        ></v-select>
      </v-col>

      <!-- 資訊與更新 -->
      <v-col cols="12" md="4" class="text-right d-flex align-center justify-end">
        <div class="mr-4 text-caption text-grey">
          資料日期: <span class="text-high-emphasis font-weight-bold">{{ tradeDate }}</span>
          <br>
          預估大盤: <span class="text-primary font-weight-bold">{{ atmStrike > 0 ? atmStrike : '-' }}</span>
        </div>
        <v-btn
          color="primary"
          prepend-icon="mdi-refresh"
          @click="loadChainData"
          :loading="loading"
          elevation="2"
        >
          更新報價
        </v-btn>
      </v-col>
    </v-row>

    <!-- 錯誤提示 -->
    <v-alert v-if="errorMsg" type="warning" variant="tonal" class="mb-4" closable>
      {{ errorMsg }}
    </v-alert>

    <!-- 2. T 字報價表 -->
    <v-card elevation="3" rounded="lg" class="overflow-hidden chain-card">
      <!-- 表頭 (固定) -->
      <div class="chain-header d-flex w-100 text-center font-weight-bold text-subtitle-2 text-white">
        <div class="flex-grow-1 bg-red-darken-3 py-2 header-call">
          CALL (買權)
        </div>
        <div class="bg-grey-darken-3 py-2 header-strike">
          履約價
        </div>
        <div class="flex-grow-1 bg-green-darken-3 py-2 header-put">
          PUT (賣權)
        </div>
      </div>

      <!-- 表格本體 -->
      <v-table density="compact" hover fixed-header height="calc(100vh - 250px)" class="chain-table">
        <thead>
          <tr class="sub-header bg-grey-lighten-4">
            <!-- Call Columns -->
            <th class="text-right text-grey-darken-2" width="8%">Delta</th>
            <th class="text-right text-grey-darken-2" width="8%">IV%</th>
            <th class="text-right text-grey-darken-2" width="10%">未平倉</th>
            <th class="text-right text-grey-darken-2" width="10%">成交量</th>
            <th class="text-right text-red font-weight-bold" width="12%" style="font-size: 1.1em !important;">成交價</th>

            <!-- Strike (中軸) -->
            <th class="text-center bg-grey-lighten-2 px-0 font-weight-black text-body-2">Strike</th>

            <!-- Put Columns -->
            <th class="text-left text-green font-weight-bold" width="12%" style="font-size: 1.1em !important;">成交價</th>
            <th class="text-left text-grey-darken-2" width="10%">成交量</th>
            <th class="text-left text-grey-darken-2" width="10%">未平倉</th>
            <th class="text-left text-grey-darken-2" width="8%">IV%</th>
            <th class="text-left text-grey-darken-2" width="8%">Delta</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="row in chainData" :key="row.strike">
            <!-- 判斷是否為 ATM (價平) -->
            <tr class="chain-row" :class="{'bg-yellow-lighten-5': row.is_atm}">

              <!-- === CALL SIDE === -->
              <!-- 價內(ITM)背景色微調 -->
              <td class="text-right text-caption" :class="{'bg-red-lighten-5': row.call?.is_itm}">
                {{ formatDecimal(row.call?.delta, 2) }}
              </td>
              <td class="text-right text-caption" :class="{'bg-red-lighten-5': row.call?.is_itm}">
                {{ formatDecimal(row.call?.iv, 1) }}
              </td>
              <td class="text-right" :class="{'bg-red-lighten-5': row.call?.is_itm}">
                {{ formatInt(row.call?.oi) }}
              </td>
              <td class="text-right" :class="{'bg-red-lighten-5': row.call?.is_itm}">
                {{ formatInt(row.call?.volume) }}
              </td>
              <td class="text-right font-weight-bold text-body-2 text-red-darken-1 border-right"
                  :class="{'bg-red-lighten-4': row.call?.is_itm, 'bg-red-lighten-5': !row.call?.is_itm}">
                {{ formatPrice(row.call?.price) }}
              </td>

              <!-- === STRIKE (履約價) === -->
              <td class="text-center bg-grey-lighten-3 font-weight-black strike-cell text-body-1"
                  :class="{'text-primary': row.is_atm}">
                {{ row.strike }}
              </td>

              <!-- === PUT SIDE === -->
              <td class="text-left font-weight-bold text-body-2 text-green-darken-1 border-left"
                  :class="{'bg-green-lighten-4': row.put?.is_itm, 'bg-green-lighten-5': !row.put?.is_itm}">
                {{ formatPrice(row.put?.price) }}
              </td>
              <td class="text-left" :class="{'bg-green-lighten-5': row.put?.is_itm}">
                {{ formatInt(row.put?.volume) }}
              </td>
              <td class="text-left" :class="{'bg-green-lighten-5': row.put?.is_itm}">
                {{ formatInt(row.put?.oi) }}
              </td>
              <td class="text-left text-caption" :class="{'bg-green-lighten-5': row.put?.is_itm}">
                {{ formatDecimal(row.put?.iv, 1) }}
              </td>
              <td class="text-left text-caption" :class="{'bg-green-lighten-5': row.put?.is_itm}">
                {{ formatDecimal(row.put?.delta, 2) }}
              </td>
            </tr>
          </template>

          <!-- 無資料狀態 -->
          <tr v-if="!loading && chainData.length === 0">
            <td colspan="11" class="text-center py-10 text-grey">
              <v-icon size="64" color="grey-lighten-2" class="mb-2">mdi-database-off</v-icon>
              <p>目前無選擇權報價資料，請嘗試切換到期日</p>
            </td>
          </tr>
        </tbody>
      </v-table>

      <!-- Loading 遮罩 -->
      <div v-if="loading" class="loading-overlay d-flex align-center justify-center">
        <v-progress-circular indeterminate color="primary" size="64"></v-progress-circular>
      </div>
    </v-card>
  </div>
</template>

<script>
import { ref, onMounted } from 'vue'
import axios from 'axios'

export default {
  name: 'Options',
  setup() {
    const loading = ref(false)
    const chainData = ref([])
    const availableExpiries = ref([])
    const selectedExpiry = ref(null)
    const tradeDate = ref('-')
    const atmStrike = ref(0)
    const errorMsg = ref('')

    // API 載入函數
    const loadChainData = async () => {
      loading.value = true
      errorMsg.value = ''
      try {
        const params = {}
        if (selectedExpiry.value) {
          params.expiry_date = selectedExpiry.value
        }

        console.log('Fetching Option Chain...', params)
        const res = await axios.get('/api/options/chain-table', { params })
        const data = res.data.data || res.data

        if (data) {
          chainData.value = data.chain || []
          availableExpiries.value = data.available_expiries || []
          tradeDate.value = data.trade_date
          atmStrike.value = data.atm_strike

          // 如果當前沒選日期，自動選第一個
          if (!selectedExpiry.value && availableExpiries.value.length > 0) {
             // 檢查 API 回傳的 expiry_date 是否在列表中
            if (data.expiry_date && availableExpiries.value.includes(data.expiry_date)) {
                 selectedExpiry.value = data.expiry_date
            } else {
                 selectedExpiry.value = availableExpiries.value[0]
            }
          }
        }
      } catch (e) {
        console.error('載入選擇權鏈失敗:', e)
        errorMsg.value = e.response?.data?.message || '無法取得資料，請稍後再試'
      } finally {
        loading.value = false
      }
    }

    // 格式化工具
    const formatPrice = (val) => {
      if (val === null || val === undefined || val === '') return '-'
      // 如果是 0，顯示 '-'
      if (parseFloat(val) === 0) return '-'
      return Number(val).toLocaleString()
    }
    const formatInt = (val) => (val ? Number(val).toLocaleString() : '-')
    const formatDecimal = (val, digits = 2) => (val ? Number(val).toFixed(digits) : '-')

    onMounted(() => {
      loadChainData()
    })

    return {
      loading,
      chainData,
      availableExpiries,
      selectedExpiry,
      tradeDate,
      atmStrike,
      errorMsg,
      loadChainData,
      formatPrice,
      formatInt,
      formatDecimal
    }
  }
}
</script>

<style scoped>
/* 表格樣式優化 */
.chain-table th {
  white-space: nowrap;
  height: 40px !important;
  font-size: 0.85rem !important;
}

/* 履約價中軸固定 (Sticky) */
.strike-cell {
  position: sticky;
  left: 0;
  z-index: 10;
  width: 80px;
  min-width: 80px;
  border-left: 1px solid #e0e0e0;
  border-right: 1px solid #e0e0e0;
  background-color: #EEEEEE;
}

/* 價內 (ITM) 背景色 */
.bg-red-lighten-5 { background-color: #FFEBEE !important; } /* Call ITM */
.bg-red-lighten-4 { background-color: #FFCDD2 !important; } /* Call Price */
.bg-green-lighten-5 { background-color: #E8F5E9 !important; } /* Put ITM */
.bg-green-lighten-4 { background-color: #C8E6C9 !important; } /* Put Price */

/* 邊框修飾 */
.border-right { border-right: 2px solid #e0e0e0; }
.border-left { border-left: 2px solid #e0e0e0; }

/* Loading 遮罩 */
.loading-overlay {
  position: absolute;
  top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(255,255,255,0.8);
  z-index: 20;
}

/* 滑鼠懸停效果 */
.chain-row:hover td {
  filter: brightness(0.95);
  transition: filter 0.1s;
}
</style>
```

### 執行步驟

1.  **覆蓋檔案**：請務必**完全覆蓋**以上 3 個檔案。
2.  **清除快取 (關鍵)**：在終端機執行：
    ```bash
    docker-compose exec app php artisan optimize:clear
