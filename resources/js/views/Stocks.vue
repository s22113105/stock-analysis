<template>
  <div class="stocks-page">
    <!-- 頁面標題 -->
    <v-row class="mb-4">
      <v-col>
        <h1 class="text-h4">
          <v-icon class="mr-2">mdi-chart-line</v-icon>
          股票報價
        </h1>
      </v-col>
      <v-col class="text-right">
        <v-btn color="primary" @click="refreshData" :loading="loading">
          <v-icon left>mdi-refresh</v-icon>
          更新資料
        </v-btn>
      </v-col>
    </v-row>

    <!-- 篩選器 (保持不變) -->
    <v-row class="mb-4">
      <v-col cols="12" md="3">
        <v-text-field
          v-model="search"
          label="搜尋股票"
          prepend-inner-icon="mdi-magnify"
          variant="outlined"
          density="compact"
          clearable
          hint="輸入股票代碼或名稱"
        ></v-text-field>
      </v-col>
      <v-col cols="12" md="3">
        <v-select
          v-model="marketFilter"
          label="市場別"
          :items="markets"
          variant="outlined"
          density="compact"
          clearable
        ></v-select>
      </v-col>
      <v-col cols="12" md="3">
        <v-select
          v-model="industryFilter"
          label="產業別"
          :items="industries"
          variant="outlined"
          density="compact"
          clearable
        ></v-select>
      </v-col>
      <v-col cols="12" md="3">
        <v-select
          v-model="changeFilter"
          label="漲跌篩選"
          :items="changeOptions"
          variant="outlined"
          density="compact"
          clearable
        ></v-select>
      </v-col>
    </v-row>

    <!-- 股票列表 -->
    <v-row>
      <v-col cols="12">
        <v-card elevation="2">
          <v-card-title>
            股票列表 (總筆數: {{ totalStocks }})
            <v-spacer></v-spacer>
            <v-chip v-if="!loading && lastUpdateTime" color="success">
              最後更新: {{ lastUpdateTime }}
            </v-chip>
          </v-card-title>
          <v-card-text>

            <v-alert v-if="loading" type="info" variant="tonal" class="my-4">
              資料載入中，請稍候...
            </v-alert>

            <!-- 錯誤訊息 -->
            <v-alert
              v-if="errorMessage"
              :type="stocks.length > 0 ? 'warning' : 'error'"
              variant="tonal"
              class="my-4"
              closable
              @click:close="errorMessage = ''"
            >
              {{ errorMessage }}
            </v-alert>

            <!-- 表格 -->
            <v-data-table
              :headers="headers"
              :items="filteredStocks"
              :search="search"
              :loading="loading"
              loading-text="載入資料中..."
              :no-data-text="loading ? '載入中...' : '暫無股票資料'"
              items-per-page-text="每頁顯示"
              :items-per-page="20"
              class="elevation-1"
            >
              <template v-slot:item.symbol="{ item }">
                <strong>{{ item.symbol }}</strong>
              </template>
              <template v-slot:item.name="{ item }">
                <span>{{ item.name }}</span>
              </template>
              <template v-slot:item.price="{ item }">
                <span v-if="item.price">
                  ${{ item.price.toFixed(2) }}
                </span>
                <span v-else class="text-grey">N/A</span>
              </template>
              <template v-slot:item.change="{ item }">
                <v-chip
                  v-if="item.change !== null"
                  :color="getChangeColor(item.change)"
                  size="small"
                >
                  <v-icon size="small">
                    {{ item.change >= 0 ? 'mdi-arrow-up' : 'mdi-arrow-down' }}
                  </v-icon>
                  {{ item.change >= 0 ? '+' : '' }}{{ item.change.toFixed(2) }}%
                </v-chip>
                <span v-else class="text-grey">-</span>
              </template>
              <template v-slot:item.volume="{ item }">
                <span v-if="item.volume">
                  {{ formatVolume(item.volume) }}
                </span>
                <span v-else class="text-grey">-</span>
              </template>
              <template v-slot:item.trade_date="{ item }">
                <span v-if="item.trade_date">
                  {{ formatDate(item.trade_date) }}
                </span>
                <span v-else class="text-grey">-</span>
              </template>
              <template v-slot:item.actions="{ item }">
                <v-btn icon="mdi-eye" size="small" variant="text" @click="viewStockDetail(item)" title="詳情"></v-btn>
                <v-btn icon="mdi-chart-line" size="small" variant="text" @click="viewChart(item)" title="走勢圖"></v-btn>
                <v-btn icon="mdi-calculator-variant" size="small" variant="text" @click="calculate(item)" title="選擇權分析"></v-btn>
              </template>
            </v-data-table>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- 快速統計 (保持不變) -->
    <v-row class="mt-4">
      <v-col cols="12" md="3">
        <v-card color="success" dark elevation="2">
          <v-card-text>
            <div class="d-flex align-center">
              <v-icon size="48" class="mr-3">mdi-arrow-up-thick</v-icon>
              <div>
                <div class="text-h6">上漲家數</div>
                <div class="text-h4">{{ upCount }}</div>
              </div>
            </div>
          </v-card-text>
        </v-card>
      </v-col>
      <v-col cols="12" md="3">
        <v-card color="error" dark elevation="2">
          <v-card-text>
            <div class="d-flex align-center">
              <v-icon size="48" class="mr-3">mdi-arrow-down-thick</v-icon>
              <div>
                <div class="text-h6">下跌家數</div>
                <div class="text-h4">{{ downCount }}</div>
              </div>
            </div>
          </v-card-text>
        </v-card>
      </v-col>
      <v-col cols="12" md="3">
        <v-card color="grey" dark elevation="2">
          <v-card-text>
            <div class="d-flex align-center">
              <v-icon size="48" class="mr-3">mdi-minus</v-icon>
              <div>
                <div class="text-h6">平盤家數</div>
                <div class="text-h4">{{ flatCount }}</div>
              </div>
            </div>
          </v-card-text>
        </v-card>
      </v-col>
      <v-col cols="12" md="3">
        <v-card color="primary" dark elevation="2">
          <v-card-text>
            <div class="d-flex align-center">
              <v-icon size="48" class="mr-3">mdi-chart-bar</v-icon>
              <div>
                <div class="text-h6">總成交量</div>
                <div class="text-h4">{{ formatVolume(totalVolume) }}</div>
              </div>
            </div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- 股票詳情對話框 (保持不變) -->
    <v-dialog v-model="detailDialog" max-width="800">
      <v-card v-if="selectedStock">
        <v-card-title class="d-flex align-center">
          <div>
            <div class="text-h5">{{ selectedStock.symbol }} - {{ selectedStock.name }}</div>
            <div class="text-caption text-grey">{{ selectedStock.market }} | {{ selectedStock.industry }}</div>
          </div>
          <v-spacer></v-spacer>
          <v-btn icon="mdi-close" variant="text" @click="detailDialog = false"></v-btn>
        </v-card-title>
        <v-divider></v-divider>
        <v-card-text>
          <v-row class="my-3">
            <v-col cols="6">
              <div class="text-subtitle-2 text-grey">當前價格</div>
              <div class="text-h4" :class="getPriceTextClass(selectedStock.change)">
                ${{ selectedStock.price || 'N/A' }}
              </div>
            </v-col>
            <v-col cols="6">
              <div class="text-subtitle-2 text-grey">漲跌幅</div>
              <div class="text-h4" :class="getPriceTextClass(selectedStock.change)">
                {{ selectedStock.change >= 0 ? '+' : '' }}{{ selectedStock.change }}%
              </div>
            </v-col>
          </v-row>
          <v-divider class="my-4"></v-divider>
          <v-row>
            <v-col cols="4">
              <div class="text-subtitle-2 text-grey">開盤價</div>
              <div class="text-body-1">${{ selectedStock.open || 'N/A' }}</div>
            </v-col>
            <v-col cols="4">
              <div class="text-subtitle-2 text-grey">最高價</div>
              <div class="text-body-1">${{ selectedStock.high || 'N/A' }}</div>
            </v-col>
            <v-col cols="4">
              <div class="text-subtitle-2 text-grey">最低價</div>
              <div class="text-body-1">${{ selectedStock.low || 'N/A' }}</div>
            </v-col>
          </v-row>
          <v-row class="mt-2">
            <v-col cols="6">
              <div class="text-subtitle-2 text-grey">成交量</div>
              <div class="text-body-1">{{ formatVolume(selectedStock.volume) }}</div>
            </v-col>
            <v-col cols="6">
              <div class="text-subtitle-2 text-grey">交易日期</div>
              <div class="text-body-1">{{ formatDate(selectedStock.trade_date) }}</div>
            </v-col>
          </v-row>
        </v-card-text>
        <v-card-actions>
          <v-spacer></v-spacer>
          <v-btn color="primary" @click="viewChart(selectedStock)">
            <v-icon left>mdi-chart-line</v-icon>
            查看走勢圖
          </v-btn>
          <v-btn color="secondary" @click="calculate(selectedStock)">
            <v-icon left>mdi-calculator</v-icon>
            選擇權分析
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- 錯誤訊息 Snackbar -->
    <v-snackbar v-model="showError" color="error" :timeout="5000" top>
      {{ errorMessage }}
      <template v-slot:actions>
        <v-btn variant="text" @click="showError = false">關閉</v-btn>
      </template>
    </v-snackbar>
  </div>
</template>

<script>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'

export default {
  name: 'Stocks',
  setup() {
    const router = useRouter()

    // ==========================================
    // 狀態管理
    // ==========================================
    const loading = ref(false)
    const stocks = ref([])
    // 移除 rawApiResult 狀態
    const search = ref('')
    const marketFilter = ref(null)
    const industryFilter = ref(null)
    const changeFilter = ref(null)
    const detailDialog = ref(false)
    const selectedStock = ref(null)
    const lastUpdateTime = ref('')
    const showError = ref(false)
    const errorMessage = ref('')

    // 篩選選項 (保持不變)
    const markets = ref(['上市', '上櫃', 'TWSE', 'TPEx'])
    const industries = ref([])
    const changeOptions = ref(['上漲', '下跌', '平盤'])

    // 表格欄位定義 (保持不變)
    const headers = ref([
      { title: '股票代碼', key: 'symbol', width: '120px', sortable: true },
      { title: '股票名稱', key: 'name', width: '150px', sortable: true },
      { title: '市場', key: 'market', width: '100px', sortable: true },
      { title: '產業', key: 'industry', width: '120px', sortable: true },
      { title: '當前價格', key: 'price', width: '120px', sortable: true },
      { title: '漲跌幅', key: 'change', width: '120px', sortable: true },
      { title: '成交量', key: 'volume', width: '120px', sortable: true },
      { title: '交易日期', key: 'trade_date', width: '120px', sortable: true },
      { title: '操作', key: 'actions', width: '150px', sortable: false }
    ])

    // ==========================================
    // 計算屬性
    // ==========================================

    // 篩選後的股票列表
    const filteredStocks = computed(() => {
      let filtered = stocks.value

      // ... (篩選邏輯)
      if (search.value) {
          const searchLower = search.value.toLowerCase()
          filtered = filtered.filter(stock =>
              stock.symbol.toLowerCase().includes(searchLower) ||
              stock.name.toLowerCase().includes(searchLower)
          )
      }
      if (marketFilter.value) {
          filtered = filtered.filter(stock => stock.market === marketFilter.value)
      }
      if (industryFilter.value) {
          filtered = filtered.filter(stock => stock.industry === industryFilter.value)
      }
      if (changeFilter.value) {
          if (changeFilter.value === '上漲') {
              filtered = filtered.filter(stock => stock.change > 0)
          } else if (changeFilter.value === '下跌') {
              filtered = filtered.filter(stock => stock.change < 0)
          } else if (changeFilter.value === '平盤') {
              filtered = filtered.filter(stock => stock.change === 0)
          }
      }

      return filtered
    })

    // 統計資料
    const totalStocks = computed(() => stocks.value.length)
    const upCount = computed(() => stocks.value.filter(s => s.change > 0).length)
    const downCount = computed(() => stocks.value.filter(s => s.change < 0).length)
    const flatCount = computed(() => stocks.value.filter(s => s.change === 0).length)
    const totalVolume = computed(() => {
      return stocks.value.reduce((sum, stock) => {
        return sum + (stock.volume || 0)
      }, 0)
    })

    // ==========================================
    // 方法
    // ==========================================

    /**
     * 載入股票資料 (最終版本，無強制測試邏輯)
     */
    const loadStocks = async () => {
      loading.value = true
      errorMessage.value = ''
      showError.value = false

      try {
        const response = await axios.get('stocks', {
          params: {
            per_page: 1000,
            is_active: true,
            has_prices: true
          }
        })

        let fetchedStocks = []
        if (response.data.success && response.data.data && response.data.data.data) {
          fetchedStocks = response.data.data.data
        }

        // 轉換資料格式
        stocks.value = fetchedStocks.map(stock => {
          const latestPrice = stock.latest_price

          return {
            id: stock.id,
            symbol: stock.symbol,
            name: stock.name,
            market: stock.exchange || 'N/A',
            industry: stock.industry || 'N/A',
            // 價格資訊
            price: latestPrice ? parseFloat(latestPrice.close) : null,
            open: latestPrice ? parseFloat(latestPrice.open) : null,
            high: latestPrice ? parseFloat(latestPrice.high) : null,
            low: latestPrice ? parseFloat(latestPrice.low) : null,
            volume: latestPrice ? parseInt(latestPrice.volume) : null,
            // 這裡使用後端計算的 change_percent
            change: latestPrice ? parseFloat(latestPrice.change_percent) : null,
            trade_date: latestPrice ? latestPrice.trade_date : null,
            // 其他資訊
            is_active: stock.is_active
          }
        })

        // 提取產業列表
        const uniqueIndustries = [...new Set(stocks.value.map(s => s.industry).filter(i => i && i !== 'N/A'))]
        industries.value = uniqueIndustries.sort()

        // 更新最後更新時間
        lastUpdateTime.value = new Date().toLocaleTimeString('zh-TW', { hour: '2-digit', minute: '2-digit', second: '2-digit' })

        if (stocks.value.length === 0) {
            errorMessage.value = 'API 連線成功，但資料庫中沒有滿足條件的股票報價。請檢查資料匯入。'
            showError.value = true
        }

        console.log('股票資料載入成功:', stocks.value.length, '筆')

      } catch (error) {
        console.error('載入股票資料失敗 (Catch Block):', error)
        errorMessage.value = `載入資料失敗: ${error.response?.data?.message || error.message}`
        showError.value = true
      } finally {
        loading.value = false
      }
    }

    /**
     * 更新資料
     */
    const refreshData = async () => {
      await loadStocks()
    }

    /**
     * 格式化成交量
     */
    const formatVolume = (volume) => {
      if (!volume) return '0'

      if (volume >= 100000000) {
        return (volume / 100000000).toFixed(2) + '億'
      } else if (volume >= 10000) {
        return (volume / 10000).toFixed(0) + '萬'
      }
      return volume.toLocaleString()
    }

    /**
     * 格式化日期
     */
    const formatDate = (date) => {
      if (!date) return 'N/A'
      const d = new Date(date)
      return d.toLocaleDateString('zh-TW', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit'
      })
    }

    /**
     * 取得漲跌顏色
     */
    const getChangeColor = (change) => {
      if (change > 0) return 'success'
      if (change < 0) return 'error'
      return 'grey'
    }

    /**
     * 取得價格文字顏色類別
     */
    const getPriceTextClass = (change) => {
      if (change > 0) return 'text-success'
      if (change < 0) return 'text-error'
      return 'text-grey'
    }

    /**
     * 查看股票詳情
     */
    const viewStockDetail = (stock) => {
      selectedStock.value = stock
      detailDialog.value = true
    }

    /**
     * 查看走勢圖
     */
    const viewChart = (stock) => {
      console.log('查看走勢圖:', stock)
    }

    /**
     * 選擇權分析
     */
    const calculate = (stock) => {
      router.push({
        name: 'BlackScholes',
        query: { symbol: stock.symbol }
      })
    }

    // ==========================================
    // 生命週期
    // ==========================================
    onMounted(() => {
      loadStocks()
    })

    // ==========================================
    // 返回
    // ==========================================
    return {
      // 狀態
      loading,
      stocks,
      search,
      marketFilter,
      industryFilter,
      changeFilter,
      markets,
      industries,
      changeOptions,
      headers,
      detailDialog,
      selectedStock,
      lastUpdateTime,
      showError,
      errorMessage,
      // 計算屬性
      filteredStocks,
      totalStocks,
      upCount,
      downCount,
      flatCount,
      totalVolume,
      // 方法
      loadStocks,
      refreshData,
      formatVolume,
      formatDate,
      getChangeColor,
      getPriceTextClass,
      viewStockDetail,
      viewChart,
      calculate
    }
  }
}
</script>

<style scoped>
.stocks-page {
  padding: 16px;
}

.text-success {
  color: rgb(76, 175, 80);
}

.text-error {
  color: rgb(244, 67, 54);
}

.text-grey {
  color: rgb(158, 158, 158);
}
</style>
