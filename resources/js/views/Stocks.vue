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

    <!-- 篩選器 -->
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
                <span v-if="item.price">${{ item.price.toFixed(2) }}</span>
                <span v-else class="text-grey">N/A</span>
              </template>
              <template v-slot:item.change="{ item }">
                <v-chip v-if="item.change !== null" :color="getChangeColor(item.change)" size="small">
                  <v-icon size="small">{{ item.change >= 0 ? 'mdi-arrow-up' : 'mdi-arrow-down' }}</v-icon>
                  {{ item.change >= 0 ? '+' : '' }}{{ item.change.toFixed(2) }}%
                </v-chip>
                <span v-else class="text-grey">-</span>
              </template>
              <template v-slot:item.volume="{ item }">
                <span v-if="item.volume">{{ formatVolume(item.volume) }}</span>
                <span v-else class="text-grey">-</span>
              </template>
              <template v-slot:item.trade_date="{ item }">
                <span v-if="item.trade_date">{{ formatDate(item.trade_date) }}</span>
                <span v-else class="text-grey">-</span>
              </template>
              <!-- ★ 操作欄：拿掉走勢圖按鈕，只保留詳情和選擇權分析 -->
              <template v-slot:item.actions="{ item }">
                <v-btn icon="mdi-eye" size="small" variant="text" @click="viewStockDetail(item)" title="詳情"></v-btn>
                <v-btn icon="mdi-calculator-variant" size="small" variant="text" @click="calculate(item)" title="選擇權分析"></v-btn>
              </template>
            </v-data-table>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- 快速統計 -->
    <v-row class="mt-4">
      <v-col cols="12" md="3">
        <v-card color="red" dark elevation="2">
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
        <v-card color="green" dark elevation="2">
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

    <!-- ★ 股票詳情對話框（含走勢圖） -->
    <v-dialog v-model="detailDialog" max-width="860" @after-leave="destroyChart">
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
          <!-- 價格資訊 -->
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

          <!-- ★ 走勢圖區塊 -->
          <v-divider class="my-4"></v-divider>
          <div class="d-flex align-center justify-space-between mb-3">
            <span class="text-subtitle-1 font-weight-bold">
              <v-icon class="mr-1">mdi-chart-line</v-icon>走勢圖
            </span>
            <!-- 期間選擇 -->
            <v-btn-group density="compact" variant="outlined">
              <v-btn
                v-for="p in periods"
                :key="p.value"
                :color="chartPeriod === p.value ? 'primary' : ''"
                @click="changePeriod(p.value)"
                size="small"
              >{{ p.label }}</v-btn>
            </v-btn-group>
          </div>

          <!-- 載入中 -->
          <div v-if="chartLoading" class="text-center py-6">
            <v-progress-circular indeterminate color="primary"></v-progress-circular>
            <div class="mt-2 text-grey">載入走勢圖...</div>
          </div>

          <!-- 無資料 -->
          <v-alert v-else-if="chartError" type="warning" variant="tonal" density="compact">
            {{ chartError }}
          </v-alert>

          <!-- 圖表 Canvas -->
          <div v-show="!chartLoading && !chartError" style="position: relative; height: 280px;">
            <canvas ref="stockChartCanvas"></canvas>
          </div>
        </v-card-text>

        <v-card-actions>
          <v-spacer></v-spacer>
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
import { ref, computed, onMounted, nextTick, watch } from 'vue'
import { useRouter } from 'vue-router'
import { Chart, registerables } from 'chart.js'
import axios from 'axios'

Chart.register(...registerables)

export default {
  name: 'Stocks',
  setup() {
    const router = useRouter()

    // 狀態管理
    const loading = ref(false)
    const stocks = ref([])
    const search = ref('')
    const marketFilter = ref(null)
    const industryFilter = ref(null)
    const changeFilter = ref(null)
    const detailDialog = ref(false)
    const selectedStock = ref(null)
    const lastUpdateTime = ref('')
    const showError = ref(false)
    const errorMessage = ref('')

    // 篩選選項
    const markets = ref(['上市', '上櫃', 'TWSE', 'TPEx'])
    const industries = ref([])
    const changeOptions = ref(['上漲', '下跌', '平盤'])

    // 表格欄位（拿掉走勢圖，操作欄縮小）
    const headers = ref([
      { title: '股票代碼', key: 'symbol',     width: '120px', sortable: true },
      { title: '股票名稱', key: 'name',        width: '150px', sortable: true },
      { title: '市場',     key: 'market',      width: '100px', sortable: true },
      { title: '產業',     key: 'industry',    width: '120px', sortable: true },
      { title: '當前價格', key: 'price',       width: '120px', sortable: true },
      { title: '漲跌幅',   key: 'change',      width: '120px', sortable: true },
      { title: '成交量',   key: 'volume',      width: '120px', sortable: true },
      { title: '交易日期', key: 'trade_date',  width: '120px', sortable: true },
      { title: '操作',     key: 'actions',     width: '100px', sortable: false }
    ])

    // ★ 走勢圖相關
    const stockChartCanvas = ref(null)
    const chartLoading = ref(false)
    const chartError = ref('')
    const chartPeriod = ref('3m')
    let chartInstance = null

    const periods = [
      { label: '1M', value: '1m' },
      { label: '3M', value: '3m' },
      { label: '6M', value: '6m' },
      { label: '1Y', value: '1y' },
    ]

    // 銷毀圖表
    const destroyChart = () => {
      if (chartInstance) {
        chartInstance.destroy()
        chartInstance = null
      }
    }

    // 載入並繪製走勢圖
    const loadChart = async (stock, period) => {
      if (!stock?.id) return
      chartLoading.value = true
      chartError.value = ''
      destroyChart()

      try {
        const response = await axios.get(`stocks/${stock.id}/chart`, {
          params: { period }
        })

        if (!response.data.success) {
          chartError.value = response.data.message || '無法載入圖表資料'
          return
        }

        const data = response.data.data
        const labels = data.labels || []
        const prices = data.datasets?.[0]?.data || []

        if (labels.length === 0) {
          chartError.value = '此股票暫無歷史價格資料'
          return
        }

        // 等 DOM 更新後再畫圖
        await nextTick()

        if (!stockChartCanvas.value) return

        const ctx = stockChartCanvas.value.getContext('2d')
        const isUp = stock.change >= 0
        const color = isUp ? 'rgb(239, 83, 80)' : 'rgb(38, 166, 154)'
        const bgColor = isUp ? 'rgba(239, 83, 80, 0.1)' : 'rgba(38, 166, 154, 0.1)'

        chartInstance = new Chart(ctx, {
          type: 'line',
          data: {
            labels,
            datasets: [{
              label: `${stock.symbol} 收盤價`,
              data: prices,
              borderColor: color,
              backgroundColor: bgColor,
              borderWidth: 1.5,
              fill: true,
              tension: 0.2,
              pointRadius: 0,
              pointHoverRadius: 4,
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
              legend: { display: false },
              tooltip: {
                callbacks: {
                  label: ctx => `NT$ ${ctx.parsed.y?.toFixed(2)}`
                }
              }
            },
            scales: {
              x: {
                ticks: {
                  maxTicksLimit: 8,
                  font: { size: 11 }
                }
              },
              y: {
                beginAtZero: false,
                ticks: { font: { size: 11 } }
              }
            }
          }
        })

      } catch (err) {
        console.error('載入走勢圖失敗:', err)
        chartError.value = '載入走勢圖失敗，請稍後再試'
      } finally {
        chartLoading.value = false
      }
    }

    // 切換期間
    const changePeriod = (period) => {
      chartPeriod.value = period
      loadChart(selectedStock.value, period)
    }

    // 計算屬性
    const filteredStocks = computed(() => {
      let filtered = stocks.value
      if (search.value) {
        const s = search.value.toLowerCase()
        filtered = filtered.filter(st =>
          st.symbol.toLowerCase().includes(s) || st.name.toLowerCase().includes(s)
        )
      }
      if (marketFilter.value) filtered = filtered.filter(st => st.market === marketFilter.value)
      if (industryFilter.value) filtered = filtered.filter(st => st.industry === industryFilter.value)
      if (changeFilter.value) {
        if (changeFilter.value === '上漲') filtered = filtered.filter(st => st.change > 0)
        else if (changeFilter.value === '下跌') filtered = filtered.filter(st => st.change < 0)
        else if (changeFilter.value === '平盤') filtered = filtered.filter(st => st.change === 0)
      }
      return filtered
    })

    const totalStocks = computed(() => stocks.value.length)
    const upCount    = computed(() => stocks.value.filter(s => s.change > 0).length)
    const downCount  = computed(() => stocks.value.filter(s => s.change < 0).length)
    const flatCount  = computed(() => stocks.value.filter(s => s.change === 0).length)
    const totalVolume = computed(() => stocks.value.reduce((sum, s) => sum + (s.volume || 0), 0))

    // 方法
    const loadStocks = async () => {
      loading.value = true
      errorMessage.value = ''
      showError.value = false
      try {
        const response = await axios.get('stocks', {
          params: { per_page: 1000, is_active: true, has_prices: true }
        })

        let fetchedStocks = []
        if (response.data.success && response.data.data?.data) {
          fetchedStocks = response.data.data.data
        }

        stocks.value = fetchedStocks.map(stock => {
          const lp = stock.latest_price
          return {
            id: stock.id,
            symbol: stock.symbol,
            name: stock.name,
            market: stock.exchange || 'N/A',
            industry: stock.industry || 'N/A',
            price:      lp ? parseFloat(lp.close)          : null,
            open:       lp ? parseFloat(lp.open)           : null,
            high:       lp ? parseFloat(lp.high)           : null,
            low:        lp ? parseFloat(lp.low)            : null,
            volume:     lp ? parseInt(lp.volume)           : null,
            change:     lp ? parseFloat(lp.change_percent) : null,
            trade_date: lp ? lp.trade_date                 : null,
            is_active: stock.is_active
          }
        })

        const uniqueIndustries = [...new Set(stocks.value.map(s => s.industry).filter(i => i && i !== 'N/A'))]
        industries.value = uniqueIndustries.sort()
        lastUpdateTime.value = new Date().toLocaleTimeString('zh-TW', { hour: '2-digit', minute: '2-digit', second: '2-digit' })

        if (stocks.value.length === 0) {
          errorMessage.value = 'API 連線成功，但資料庫中沒有滿足條件的股票報價。請檢查資料匯入。'
          showError.value = true
        }
      } catch (error) {
        errorMessage.value = `載入資料失敗: ${error.response?.data?.message || error.message}`
        showError.value = true
      } finally {
        loading.value = false
      }
    }

    const refreshData = () => loadStocks()

    const formatVolume = (volume) => {
      if (!volume) return '0'
      if (volume >= 100000000) return (volume / 100000000).toFixed(2) + '億'
      if (volume >= 10000)     return (volume / 10000).toFixed(0) + '萬'
      return volume.toLocaleString()
    }

    const formatDate = (date) => {
      if (!date) return 'N/A'
      return new Date(date).toLocaleDateString('zh-TW', { year: 'numeric', month: '2-digit', day: '2-digit' })
    }

    const getChangeColor   = (c) => c > 0 ? 'red' : c < 0 ? 'green' : 'grey'
    const getPriceTextClass = (c) => c > 0 ? 'text-red' : c < 0 ? 'text-green' : 'text-grey'

    // ★ 點眼睛開詳情，同時自動載入走勢圖
    const viewStockDetail = (stock) => {
      selectedStock.value = stock
      chartPeriod.value = '3m'
      detailDialog.value = true
      // 等 dialog 渲染完再載入圖表
      nextTick(() => loadChart(stock, '3m'))
    }

    const calculate = (stock) => {
      router.push({ name: 'BlackScholes', query: { symbol: stock.symbol } })
    }

    onMounted(() => loadStocks())

    return {
      loading, stocks, search, marketFilter, industryFilter, changeFilter,
      markets, industries, changeOptions, headers,
      detailDialog, selectedStock, lastUpdateTime, showError, errorMessage,
      filteredStocks, totalStocks, upCount, downCount, flatCount, totalVolume,
      // 走勢圖
      stockChartCanvas, chartLoading, chartError, chartPeriod, periods,
      // 方法
      loadStocks, refreshData, formatVolume, formatDate,
      getChangeColor, getPriceTextClass,
      viewStockDetail, calculate,
      changePeriod, destroyChart,
    }
  }
}
</script>

<style scoped>
.stocks-page { padding: 16px; }
.text-red   { color: rgb(244, 67, 54)  !important; }
.text-green { color: rgb(76, 175, 80)  !important; }
.text-grey  { color: rgb(158, 158, 158) !important; }
</style>
