<template>
  <div class="stocks-page">
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
        <v-text-field v-model="search" label="搜尋股票" prepend-inner-icon="mdi-magnify"
          variant="outlined" density="compact" clearable hint="輸入股票代碼或名稱"></v-text-field>
      </v-col>
      <v-col cols="12" md="3">
        <v-select v-model="marketFilter" label="市場別" :items="markets"
          variant="outlined" density="compact" clearable></v-select>
      </v-col>
      <v-col cols="12" md="3">
        <v-select v-model="industryFilter" label="產業別" :items="industries"
          variant="outlined" density="compact" clearable></v-select>
      </v-col>
      <v-col cols="12" md="3">
        <v-select v-model="changeFilter" label="漲跌篩選" :items="changeOptions"
          variant="outlined" density="compact" clearable></v-select>
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
            <v-alert v-if="loading" type="info" variant="tonal" class="my-4">資料載入中，請稍候...</v-alert>
            <v-alert v-if="errorMessage" :type="stocks.length > 0 ? 'warning' : 'error'"
              variant="tonal" class="my-4" closable @click:close="errorMessage = ''">
              {{ errorMessage }}
            </v-alert>

            <v-data-table :headers="headers" :items="filteredStocks" :search="search"
              :loading="loading" loading-text="載入資料中..."
              :no-data-text="loading ? '載入中...' : '暫無股票資料'"
              items-per-page-text="每頁顯示" :items-per-page="20" class="elevation-1">
              <template v-slot:item.symbol="{ item }"><strong>{{ item.symbol }}</strong></template>
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
              <div><div class="text-h6">上漲家數</div><div class="text-h4">{{ upCount }}</div></div>
            </div>
          </v-card-text>
        </v-card>
      </v-col>
      <v-col cols="12" md="3">
        <v-card color="green" dark elevation="2">
          <v-card-text>
            <div class="d-flex align-center">
              <v-icon size="48" class="mr-3">mdi-arrow-down-thick</v-icon>
              <div><div class="text-h6">下跌家數</div><div class="text-h4">{{ downCount }}</div></div>
            </div>
          </v-card-text>
        </v-card>
      </v-col>
      <v-col cols="12" md="3">
        <v-card color="grey" dark elevation="2">
          <v-card-text>
            <div class="d-flex align-center">
              <v-icon size="48" class="mr-3">mdi-minus</v-icon>
              <div><div class="text-h6">平盤家數</div><div class="text-h4">{{ flatCount }}</div></div>
            </div>
          </v-card-text>
        </v-card>
      </v-col>
      <v-col cols="12" md="3">
        <v-card color="primary" dark elevation="2">
          <v-card-text>
            <div class="d-flex align-center">
              <v-icon size="48" class="mr-3">mdi-chart-bar</v-icon>
              <div><div class="text-h6">總成交量</div><div class="text-h4">{{ formatVolume(totalVolume) }}</div></div>
            </div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- 股票詳情對話框（含 K 線圖） -->
    <v-dialog v-model="detailDialog" max-width="900" @after-leave="destroyChart">
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
          <v-divider class="my-3"></v-divider>
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

          <!-- K 線圖區塊 -->
          <v-divider class="my-4"></v-divider>
          <div class="d-flex align-center justify-space-between mb-3">
            <span class="text-subtitle-1 font-weight-bold">
              <v-icon class="mr-1">mdi-chart-candlestick</v-icon>K 線圖
            </span>
            <v-btn-group density="compact" variant="outlined">
              <v-btn v-for="p in periods" :key="p.value"
                :color="chartPeriod === p.value ? 'primary' : ''"
                @click="changePeriod(p.value)" size="small">
                {{ p.label }}
              </v-btn>
            </v-btn-group>
          </div>

          <div v-if="chartLoading" class="text-center py-6">
            <v-progress-circular indeterminate color="primary"></v-progress-circular>
            <div class="mt-2 text-grey">載入 K 線圖...</div>
          </div>

          <v-alert v-else-if="chartError" type="warning" variant="tonal" density="compact">
            {{ chartError }}
          </v-alert>

          <div v-show="!chartLoading && !chartError" style="position: relative; height: 300px;">
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

    <v-snackbar v-model="showError" color="error" :timeout="5000" top>
      {{ errorMessage }}
      <template v-slot:actions>
        <v-btn variant="text" @click="showError = false">關閉</v-btn>
      </template>
    </v-snackbar>
  </div>
</template>

<script>
import { ref, computed, onMounted, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { Chart, registerables } from 'chart.js'
import { CandlestickController, CandlestickElement } from 'chartjs-chart-financial'
import 'chartjs-adapter-date-fns'
import axios from 'axios'

Chart.register(...registerables, CandlestickController, CandlestickElement)

export default {
  name: 'Stocks',
  setup() {
    const router = useRouter()

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

    const markets = ref(['上市', '上櫃', 'TWSE', 'TPEx'])
    const industries = ref([])
    const changeOptions = ref(['上漲', '下跌', '平盤'])

    const headers = ref([
      { title: '股票代碼', key: 'symbol',    width: '120px', sortable: true },
      { title: '股票名稱', key: 'name',       width: '150px', sortable: true },
      { title: '市場',     key: 'market',     width: '100px', sortable: true },
      { title: '產業',     key: 'industry',   width: '120px', sortable: true },
      { title: '當前價格', key: 'price',      width: '120px', sortable: true },
      { title: '漲跌幅',   key: 'change',     width: '120px', sortable: true },
      { title: '成交量',   key: 'volume',     width: '120px', sortable: true },
      { title: '交易日期', key: 'trade_date', width: '120px', sortable: true },
      { title: '操作',     key: 'actions',    width: '100px', sortable: false }
    ])

    // K 線圖相關
    const stockChartCanvas = ref(null)
    const chartLoading = ref(false)
    const chartError = ref('')
    const chartPeriod = ref('3m')
    let chartInstance = null
    let cachedPrices = []

    const periods = [
      { label: '1M', value: '1m' },
      { label: '3M', value: '3m' },
      { label: '6M', value: '6m' },
      { label: '1Y', value: '1y' },
    ]

    const destroyChart = () => {
      if (chartInstance) {
        chartInstance.destroy()
        chartInstance = null
      }
    }

    const drawCandlestick = async (priceList, stock) => {
      if (priceList.length === 0) {
        chartError.value = '此股票暫無歷史價格資料'
        return
      }

      await nextTick()
      if (!stockChartCanvas.value) return

      destroyChart()
      const ctx = stockChartCanvas.value.getContext('2d')

      const ohlcData = priceList.map(p => ({
        x: new Date(p.trade_date).getTime(),
        o: parseFloat(p.open)  || 0,
        h: parseFloat(p.high)  || 0,
        l: parseFloat(p.low)   || 0,
        c: parseFloat(p.close) || 0,
      }))

      chartInstance = new Chart(ctx, {
        type: 'candlestick',
        data: {
          datasets: [{
            label: `${stock.symbol} K線`,
            data: ohlcData,
            color: {
              up:        '#ef5350',
              down:      '#26a69a',
              unchanged: '#999999',
            },
            borderColor: {
              up:        '#ef5350',
              down:      '#26a69a',
              unchanged: '#999999',
            },
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                label: ctx => {
                  const d = ctx.raw
                  return [
                    `開: $${d.o?.toFixed(2)}`,
                    `高: $${d.h?.toFixed(2)}`,
                    `低: $${d.l?.toFixed(2)}`,
                    `收: $${d.c?.toFixed(2)}`,
                  ]
                }
              }
            }
          },
          scales: {
            x: {
              type: 'timeseries',
              time: {
                unit: 'day',
                displayFormats: { day: 'MM/dd' }
              },
              ticks: { maxTicksLimit: 10, font: { size: 11 } }
            },
            y: {
              beginAtZero: false,
              ticks: { font: { size: 11 } }
            }
          }
        }
      })
    }

    const loadChart = async (stock, period) => {
      if (!stock?.id) return
      chartLoading.value = true
      chartError.value = ''

      try {
        if (cachedPrices.length === 0) {
          const response = await axios.get(`stocks/${stock.id}/prices`, {
            params: { per_page: 999 }
          })
          if (!response.data.success) {
            chartError.value = response.data.message || '無法載入資料'
            return
          }
          cachedPrices = response.data.data?.prices || []
        }

        const daysMap = { '1m': 30, '3m': 90, '6m': 180, '1y': 365 }
        const days = daysMap[period] || 90
        const sliced = cachedPrices.slice(-days)

        await drawCandlestick(sliced, stock)
      } catch (err) {
        console.error('載入K線圖失敗:', err)
        chartError.value = '載入K線圖失敗，請稍後再試'
      } finally {
        chartLoading.value = false
      }
    }

    const changePeriod = (period) => {
      chartPeriod.value = period
      chartLoading.value = true
      const daysMap = { '1m': 30, '3m': 90, '6m': 180, '1y': 365 }
      const days = daysMap[period] || 90
      const sliced = cachedPrices.slice(-days)
      drawCandlestick(sliced, selectedStock.value).finally(() => {
        chartLoading.value = false
      })
    }

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
    const upCount     = computed(() => stocks.value.filter(s => s.change > 0).length)
    const downCount   = computed(() => stocks.value.filter(s => s.change < 0).length)
    const flatCount   = computed(() => stocks.value.filter(s => s.change === 0).length)
    const totalVolume = computed(() => stocks.value.reduce((sum, s) => sum + (s.volume || 0), 0))

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

    const getChangeColor    = (c) => c > 0 ? 'red' : c < 0 ? 'green' : 'grey'
    const getPriceTextClass = (c) => c > 0 ? 'text-red' : c < 0 ? 'text-green' : 'text-grey'

    const viewStockDetail = (stock) => {
      selectedStock.value = stock
      chartPeriod.value = '3m'
      cachedPrices = []
      detailDialog.value = true
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
      stockChartCanvas, chartLoading, chartError, chartPeriod, periods,
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
