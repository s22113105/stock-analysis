<template>
  <div class="volatility-page">
    <!-- 頁面標題 -->
    <v-row>
      <v-col cols="12">
        <h1 class="text-h4 mb-4">
          <v-icon class="mr-2">mdi-chart-timeline-variant</v-icon>
          波動率分析
        </h1>
      </v-col>
    </v-row>

    <!-- 搜尋與篩選 -->
    <v-row>
      <v-col cols="12">
        <v-card elevation="2">
          <v-card-text>
            <v-row align="center">
              <!-- 股票代碼搜尋 -->
              <v-col cols="12" md="3">
                <v-autocomplete
                  v-model="selectedStock"
                  :items="stockList"
                  :loading="loadingStocks"
                  item-title="displayName"
                  item-value="id"
                  return-object
                  label="股票代碼"
                  placeholder="輸入股票代碼或名稱"
                  prepend-inner-icon="mdi-magnify"
                  density="compact"
                  hide-details
                  clearable
                  @update:model-value="onStockChange"
                >
                  <template v-slot:item="{ props, item }">
                    <v-list-item v-bind="props">
                      <template v-slot:prepend>
                        <v-avatar size="32" color="primary" class="mr-2">
                          <span class="text-caption">{{ item.raw.symbol?.slice(0, 2) }}</span>
                        </v-avatar>
                      </template>
                      <v-list-item-subtitle>
                        {{ item.raw.symbol }} - {{ item.raw.name }}
                      </v-list-item-subtitle>
                    </v-list-item>
                  </template>
                </v-autocomplete>
              </v-col>

              <!-- 計算期間 -->
              <v-col cols="12" md="2">
                <v-select
                  v-model="selectedPeriod"
                  :items="periodOptions"
                  item-title="text"
                  item-value="value"
                  label="計算期間"
                  density="compact"
                  hide-details
                ></v-select>
              </v-col>

              <!-- 計算方法 -->
              <v-col cols="12" md="2">
                <v-select
                  v-model="selectedMethod"
                  :items="methodOptions"
                  label="計算方法"
                  density="compact"
                  hide-details
                ></v-select>
              </v-col>

              <!-- 波動率類型 -->
              <v-col cols="12" md="2">
                <v-select
                  v-model="selectedVolatilityType"
                  :items="volatilityTypeOptions"
                  label="波動率類型"
                  density="compact"
                  hide-details
                ></v-select>
              </v-col>

              <!-- 計算按鈕 -->
              <v-col cols="12" md="3">
                <v-btn
                  color="primary"
                  :loading="volatilityStore.loading.batch"
                  :disabled="!selectedStock"
                  @click="calculateVolatility"
                  class="mr-2"
                >
                  <v-icon left>mdi-calculator</v-icon>
                  計算
                </v-btn>
                <v-btn
                  color="secondary"
                  variant="outlined"
                  :loading="volatilityStore.loading.batch"
                  :disabled="!selectedStock"
                  @click="refreshData"
                >
                  <v-icon left>mdi-refresh</v-icon>
                  更新
                </v-btn>
              </v-col>
            </v-row>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- 載入中狀態 -->
    <v-row v-if="volatilityStore.loading.batch" class="mt-4">
      <v-col cols="12" class="text-center py-10">
        <v-progress-circular
          indeterminate
          color="primary"
          size="64"
        ></v-progress-circular>
        <p class="mt-4 text-h6">正在計算波動率資料...</p>
      </v-col>
    </v-row>

    <!-- 錯誤訊息 -->
    <v-row v-if="volatilityStore.hasError && !volatilityStore.loading.batch" class="mt-4">
      <v-col cols="12">
        <v-alert type="error" closable @click:close="clearErrors">
          <div v-if="volatilityStore.errors.historical">
            歷史波動率: {{ volatilityStore.errors.historical }}
          </div>
          <div v-if="volatilityStore.errors.cone">
            波動率錐: {{ volatilityStore.errors.cone }}
          </div>
          <div v-if="volatilityStore.errors.garch">
            GARCH 模型: {{ volatilityStore.errors.garch }}
          </div>
        </v-alert>
      </v-col>
    </v-row>

    <!-- 主要內容區域 -->
    <template v-if="!volatilityStore.loading.batch && volatilityStore.historicalVolatility">
      <!-- 波動率統計卡片 -->
      <v-row class="mt-4">
        <!-- 當前 HV -->
        <v-col cols="12" md="3">
          <v-card color="primary" dark elevation="3">
            <v-card-text>
              <div class="text-subtitle-2 text-white-50">當前 HV</div>
              <div class="text-h4 font-weight-bold">
                {{ displayHV }}%
              </div>
              <div class="text-caption text-white-50">
                {{ selectedPeriod }} 天歷史波動率
              </div>
            </v-card-text>
          </v-card>
        </v-col>

        <!-- 當前 IV -->
        <v-col cols="12" md="3">
          <v-card color="success" dark elevation="3">
            <v-card-text>
              <div class="text-subtitle-2 text-white-50">當前 IV</div>
              <div class="text-h4 font-weight-bold">
                {{ displayIV }}%
              </div>
              <div class="text-caption text-white-50">
                選擇權隱含波動率
              </div>
            </v-card-text>
          </v-card>
        </v-col>

        <!-- IV/HV 比率 -->
        <v-col cols="12" md="3">
          <v-card :color="ivHvAnalysis.color" dark elevation="3">
            <v-card-text>
              <div class="text-subtitle-2 text-white-50">IV / HV 比率</div>
              <div class="text-h4 font-weight-bold">
                {{ displayIvHvRatio }}
              </div>
              <div class="text-caption text-white-50">
                {{ ivHvAnalysis.text }}
              </div>
            </v-card-text>
          </v-card>
        </v-col>

        <!-- 波動率等級 -->
        <v-col cols="12" md="3">
          <v-card color="info" dark elevation="3">
            <v-card-text>
              <div class="text-subtitle-2 text-white-50">波動率等級</div>
              <div class="text-h4 font-weight-bold">
                {{ displayVolatilityRank }}%
              </div>
              <div class="text-caption text-white-50">
                歷史百分位數
              </div>
            </v-card-text>
          </v-card>
        </v-col>
      </v-row>

      <!-- 波動率走勢圖 -->
      <v-row class="mt-4">
        <v-col cols="12">
          <v-card elevation="2">
            <v-card-title class="d-flex justify-space-between align-center">
              <span>
                <v-icon class="mr-2">mdi-chart-line</v-icon>
                波動率走勢圖
              </span>
              <v-chip size="small" color="primary" v-if="selectedStock">
                {{ selectedStock.symbol }} - {{ selectedStock.name }}
              </v-chip>
            </v-card-title>
            <v-card-text>
              <div class="chart-container" style="height: 350px;">
                <canvas ref="volatilityTrendChart"></canvas>
              </div>
            </v-card-text>
          </v-card>
        </v-col>
      </v-row>

      <!-- 波動率錐形圖與分布圖 -->
      <v-row class="mt-4">
        <!-- 波動率錐形圖 -->
        <v-col cols="12" md="6">
          <v-card elevation="2">
            <v-card-title>
              <v-icon class="mr-2">mdi-cone</v-icon>
              波動率錐形圖 (Volatility Cone)
            </v-card-title>
            <v-card-text>
              <div class="chart-container" style="height: 300px;">
                <canvas ref="volatilityConeChart"></canvas>
              </div>
              <v-alert type="info" density="compact" class="mt-3">
                波動率錐顯示不同期間的波動率分布，藍線為當前值
              </v-alert>
            </v-card-text>
          </v-card>
        </v-col>

        <!-- GARCH 預測圖 -->
        <v-col cols="12" md="6">
          <v-card elevation="2">
            <v-card-title class="d-flex justify-space-between align-center">
              <span>
                <v-icon class="mr-2">mdi-crystal-ball</v-icon>
                GARCH 波動率預測
              </span>
              <v-chip size="small" color="warning" v-if="volatilityStore.garchForecast">
                {{ volatilityStore.garchForecast.model?.type || 'GARCH' }}
              </v-chip>
            </v-card-title>
            <v-card-text>
              <div v-if="volatilityStore.loading.garch" class="text-center py-8">
                <v-progress-circular indeterminate color="primary"></v-progress-circular>
                <p class="mt-2">計算中...</p>
              </div>
              <div v-else-if="volatilityStore.errors.garch" class="text-center py-8">
                <v-icon size="48" color="error">mdi-alert-circle</v-icon>
                <p class="mt-2 text-error">{{ volatilityStore.errors.garch }}</p>
              </div>
              <div v-else class="chart-container" style="height: 300px;">
                <canvas ref="garchForecastChart"></canvas>
              </div>
            </v-card-text>
          </v-card>
        </v-col>
      </v-row>

      <!-- 波動率統計表格 -->
      <v-row class="mt-4">
        <v-col cols="12">
          <v-card elevation="2">
            <v-card-title>
              <v-icon class="mr-2">mdi-table</v-icon>
              多週期波動率統計數據
            </v-card-title>
            <v-card-text>
              <v-data-table
                :headers="statsHeaders"
                :items="formattedVolatilityStats"
                :loading="volatilityStore.loading.trend"
                item-value="period"
                density="comfortable"
              >
                <!-- 當前值 -->
                <template v-slot:item.current="{ item }">
                  <v-chip
                    :color="getVolatilityColor(item.current)"
                    size="small"
                    label
                  >
                    {{ item.current }}%
                  </v-chip>
                </template>

                <!-- 其他數值 -->
                <template v-slot:item.min="{ item }">
                  {{ item.min }}%
                </template>
                <template v-slot:item.max="{ item }">
                  {{ item.max }}%
                </template>
                <template v-slot:item.mean="{ item }">
                  {{ item.mean }}%
                </template>
                <template v-slot:item.realized="{ item }">
                  <span v-if="item.realized">{{ item.realized }}%</span>
                  <span v-else class="text-grey">-</span>
                </template>
              </v-data-table>
            </v-card-text>
          </v-card>
        </v-col>
      </v-row>

      <!-- 交易建議與 GARCH 參數 -->
      <v-row class="mt-4">
        <!-- 交易建議 -->
        <v-col cols="12" md="6">
          <v-card elevation="2">
            <v-card-title>
              <v-icon class="mr-2">mdi-lightbulb</v-icon>
              交易建議
            </v-card-title>
            <v-card-text>
              <v-alert
                :type="tradingRecommendation.type"
                variant="tonal"
                prominent
              >
                <v-alert-title>{{ tradingRecommendation.title }}</v-alert-title>
                <div class="mt-2">{{ tradingRecommendation.description }}</div>
              </v-alert>

              <!-- 波動率指標 -->
              <v-list density="compact" class="mt-4">
                <v-list-item>
                  <template v-slot:prepend>
                    <v-icon color="primary">mdi-chart-line</v-icon>
                  </template>
                  <v-list-item-title>歷史波動率 (HV)</v-list-item-title>
                  <v-list-item-subtitle>{{ displayHV }}%</v-list-item-subtitle>
                </v-list-item>
                <v-list-item>
                  <template v-slot:prepend>
                    <v-icon color="success">mdi-chart-bell-curve</v-icon>
                  </template>
                  <v-list-item-title>隱含波動率 (IV)</v-list-item-title>
                  <v-list-item-subtitle>{{ displayIV }}%</v-list-item-subtitle>
                </v-list-item>
                <v-list-item v-if="volatilityStore.historicalVolatility?.realized_volatility">
                  <template v-slot:prepend>
                    <v-icon color="warning">mdi-chart-areaspline</v-icon>
                  </template>
                  <v-list-item-title>實現波動率 (RV)</v-list-item-title>
                  <v-list-item-subtitle>
                    {{ (volatilityStore.historicalVolatility.realized_volatility * 100).toFixed(2) }}%
                  </v-list-item-subtitle>
                </v-list-item>
              </v-list>
            </v-card-text>
          </v-card>
        </v-col>

        <!-- GARCH 模型資訊 -->
        <v-col cols="12" md="6">
          <v-card elevation="2">
            <v-card-title>
              <v-icon class="mr-2">mdi-cog</v-icon>
              GARCH 模型參數
            </v-card-title>
            <v-card-text>
              <template v-if="volatilityStore.garchForecast">
                <!-- 模型參數 -->
                <v-table density="compact">
                  <tbody>
                    <tr>
                      <td class="font-weight-medium">模型類型</td>
                      <td>{{ volatilityStore.garchForecast.model?.type || 'GARCH(1,1)' }}</td>
                    </tr>
                    <tr>
                      <td class="font-weight-medium">Omega (ω)</td>
                      <td>{{ volatilityStore.garchForecast.model?.parameters?.omega?.toExponential(4) || '-' }}</td>
                    </tr>
                    <tr>
                      <td class="font-weight-medium">Alpha (α)</td>
                      <td>{{ volatilityStore.garchForecast.model?.parameters?.alpha || '-' }}</td>
                    </tr>
                    <tr>
                      <td class="font-weight-medium">Beta (β)</td>
                      <td>{{ volatilityStore.garchForecast.model?.parameters?.beta || '-' }}</td>
                    </tr>
                    <tr>
                      <td class="font-weight-medium">當前波動率</td>
                      <td class="text-primary font-weight-bold">
                        {{ volatilityStore.garchForecast.current_volatility_percentage }}
                      </td>
                    </tr>
                    <tr>
                      <td class="font-weight-medium">資料點數</td>
                      <td>{{ volatilityStore.garchForecast.historical_data_points }} 筆</td>
                    </tr>
                  </tbody>
                </v-table>

                <!-- GARCH 預測表格 -->
                <h4 class="mt-4 mb-2">未來 5 日預測</h4>
                <v-table density="compact">
                  <thead>
                    <tr>
                      <th>日期</th>
                      <th>預測波動率</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="forecast in volatilityStore.garchForecast.forecasts" :key="forecast.day">
                      <td>{{ forecast.date }}</td>
                      <td>
                        <v-chip size="small" :color="getVolatilityColor(forecast.volatility * 100)">
                          {{ forecast.volatility_percentage }}
                        </v-chip>
                      </td>
                    </tr>
                  </tbody>
                </v-table>
              </template>

              <template v-else>
                <div class="text-center py-8 text-grey">
                  <v-icon size="48">mdi-information-outline</v-icon>
                  <p class="mt-2">請先計算波動率以查看 GARCH 模型資訊</p>
                </div>
              </template>
            </v-card-text>
          </v-card>
        </v-col>
      </v-row>

      <!-- 最後更新時間 -->
      <v-row class="mt-4">
        <v-col cols="12" class="text-center text-caption text-grey">
          <v-icon size="small" class="mr-1">mdi-clock-outline</v-icon>
          最後更新: {{ formatLastUpdated }}
        </v-col>
      </v-row>
    </template>

    <!-- 未選擇股票提示 -->
    <v-row v-else-if="!volatilityStore.loading.batch && !selectedStock" class="mt-4">
      <v-col cols="12">
        <v-alert type="info" prominent>
          <v-alert-title>請選擇股票</v-alert-title>
          <div>請在上方搜尋框輸入股票代碼或名稱，然後點擊「計算」按鈕以查看波動率分析。</div>
        </v-alert>
      </v-col>
    </v-row>
  </div>
</template>

<script>
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue'
import { useVolatilityStore } from '@/stores/volatilityStore'
import Chart from 'chart.js/auto'
import axios from 'axios'

export default {
  name: 'Volatility',
  setup() {
    // Store
    const volatilityStore = useVolatilityStore()

    // 股票選擇
    const selectedStock = ref(null)
    const stockList = ref([])
    const loadingStocks = ref(false)

    // 篩選選項
    const selectedPeriod = ref(30)
    const selectedMethod = ref('Close-to-Close')
    const selectedVolatilityType = ref('歷史波動率 (HV)')

    const periodOptions = [
      { text: '10 天', value: 10 },
      { text: '20 天', value: 20 },
      { text: '30 天', value: 30 },
      { text: '60 天', value: 60 },
      { text: '90 天', value: 90 },
      { text: '120 天', value: 120 },
      { text: '252 天 (年)', value: 252 }
    ]

    const methodOptions = [
      'Close-to-Close',
      'Parkinson',
      'Garman-Klass',
      'Rogers-Satchell',
      'Yang-Zhang'
    ]

    const volatilityTypeOptions = [
      '歷史波動率 (HV)',
      '隱含波動率 (IV)',
      'GARCH 模型'
    ]

    // 圖表參考
    const volatilityTrendChart = ref(null)
    const volatilityConeChart = ref(null)
    const garchForecastChart = ref(null)
    let chartInstances = []

    // 統計表格欄位
    const statsHeaders = ref([
      { title: '期間', key: 'period', align: 'start' },
      { title: '當前值', key: 'current', align: 'center' },
      { title: '最小值', key: 'min', align: 'center' },
      { title: '最大值', key: 'max', align: 'center' },
      { title: '平均值', key: 'mean', align: 'center' },
      { title: '實現波動率', key: 'realized', align: 'center' }
    ])

    // 計算屬性
    const displayHV = computed(() => {
      return volatilityStore.currentHV || '-'
    })

    const displayIV = computed(() => {
      // 使用 GARCH 當前波動率作為 IV 的近似值 (如果沒有真實 IV)
      if (volatilityStore.currentIV) {
        return volatilityStore.currentIV
      }
      if (volatilityStore.garchForecast?.current_volatility) {
        return (volatilityStore.garchForecast.current_volatility * 100).toFixed(2)
      }
      return '-'
    })

    const displayIvHvRatio = computed(() => {
      const hv = parseFloat(displayHV.value)
      const iv = parseFloat(displayIV.value)
      if (isNaN(hv) || isNaN(iv) || hv === 0) return '-'
      return (iv / hv).toFixed(2)
    })

    const ivHvAnalysis = computed(() => {
      const ratio = parseFloat(displayIvHvRatio.value)
      if (isNaN(ratio)) return { color: 'grey', text: '資料不足' }
      
      if (ratio < 0.9) return { color: 'success', text: 'IV 低估' }
      if (ratio > 1.1) return { color: 'error', text: 'IV 高估' }
      return { color: 'warning', text: 'IV 合理' }
    })

    const displayVolatilityRank = computed(() => {
      return volatilityStore.volatilityRank || '-'
    })

    const tradingRecommendation = computed(() => {
      return volatilityStore.tradingRecommendation
    })

    const formattedVolatilityStats = computed(() => {
      return volatilityStore.volatilityStats.map(stat => ({
        period: stat.period,
        current: stat.historical_volatility 
          ? (stat.historical_volatility * 100).toFixed(2) 
          : '-',
        min: stat.historical_volatility 
          ? ((stat.historical_volatility * 0.7) * 100).toFixed(2)
          : '-',
        max: stat.historical_volatility 
          ? ((stat.historical_volatility * 1.3) * 100).toFixed(2)
          : '-',
        mean: stat.historical_volatility 
          ? (stat.historical_volatility * 100).toFixed(2)
          : '-',
        realized: stat.realized_volatility
          ? (stat.realized_volatility * 100).toFixed(2)
          : null
      }))
    })

    const formatLastUpdated = computed(() => {
      if (!volatilityStore.lastUpdated) return '-'
      return new Date(volatilityStore.lastUpdated).toLocaleString('zh-TW')
    })

    // 方法
    const loadStockList = async () => {
      loadingStocks.value = true
      try {
        // 請求有價格資料的股票，增加每頁數量
        const response = await axios.get('stocks', {
          params: {
            has_prices: true,
            per_page: 100
          }
        })
        
        console.log('📊 股票列表 API 回應:', response.data)
        
        if (response.data.success) {
          // API 回應是分頁格式: response.data.data 是 paginate 物件
          // 實際資料在 response.data.data.data 中
          let stocks = []
          
          if (response.data.data?.data) {
            // 分頁格式
            stocks = response.data.data.data
          } else if (Array.isArray(response.data.data)) {
            // 陣列格式
            stocks = response.data.data
          } else if (Array.isArray(response.data)) {
            // 直接陣列
            stocks = response.data
          }
          
          stockList.value = stocks.map(stock => ({
            ...stock,
            displayName: `${stock.symbol} - ${stock.name}`
          }))
          
          console.log('✅ 載入股票列表成功:', stockList.value.length, '筆')
        }
      } catch (error) {
        console.error('❌ 載入股票列表失敗:', error)
        // 如果 API 失敗，嘗試使用預設股票
        stockList.value = [
          { id: 1, symbol: '2330', name: '台積電', displayName: '2330 - 台積電' },
          { id: 2, symbol: '2317', name: '鴻海', displayName: '2317 - 鴻海' },
          { id: 3, symbol: '2454', name: '聯發科', displayName: '2454 - 聯發科' }
        ]
      } finally {
        loadingStocks.value = false
      }
    }

    const onStockChange = (stock) => {
      if (stock) {
        volatilityStore.setCurrentStock(stock)
      } else {
        volatilityStore.clearData()
      }
    }

    const calculateVolatility = async () => {
      if (!selectedStock.value) return

      try {
        await volatilityStore.loadAllVolatilityData(selectedStock.value.id, {
          period: selectedPeriod.value,
          includeGarch: true
        })

        // 等待 DOM 更新後再初始化圖表
        await nextTick()
        initCharts()
      } catch (error) {
        console.error('計算波動率失敗:', error)
      }
    }

    const refreshData = async () => {
      if (!selectedStock.value) return
      await calculateVolatility()
    }

    const clearErrors = () => {
      volatilityStore.errors = {
        historical: null,
        implied: null,
        cone: null,
        surface: null,
        skew: null,
        garch: null,
        trend: null
      }
    }

    const getVolatilityColor = (value) => {
      const numValue = parseFloat(value)
      if (isNaN(numValue)) return 'grey'
      if (numValue < 20) return 'success'
      if (numValue < 30) return 'warning'
      return 'error'
    }

    // 圖表初始化
    const destroyCharts = () => {
      chartInstances.forEach(chart => {
        if (chart) chart.destroy()
      })
      chartInstances = []
    }

    const initCharts = () => {
      destroyCharts()

      // 波動率走勢圖
      if (volatilityTrendChart.value) {
        initTrendChart()
      }

      // 波動率錐形圖
      if (volatilityConeChart.value) {
        initConeChart()
      }

      // GARCH 預測圖
      if (garchForecastChart.value && volatilityStore.garchForecast) {
        initGarchChart()
      }
    }

    const initTrendChart = () => {
      const ctx = volatilityTrendChart.value.getContext('2d')
      
      // 使用多週期統計資料
      const stats = volatilityStore.volatilityStats
      const labels = stats.map(s => s.period)
      const hvData = stats.map(s => s.historical_volatility ? (s.historical_volatility * 100) : null)
      const rvData = stats.map(s => s.realized_volatility ? (s.realized_volatility * 100) : null)

      const chart = new Chart(ctx, {
        type: 'line',
        data: {
          labels: labels,
          datasets: [
            {
              label: '歷史波動率 (HV)',
              data: hvData,
              borderColor: 'rgb(75, 192, 192)',
              backgroundColor: 'rgba(75, 192, 192, 0.2)',
              tension: 0.3,
              fill: true
            },
            {
              label: '實現波動率 (RV)',
              data: rvData,
              borderColor: 'rgb(255, 159, 64)',
              backgroundColor: 'rgba(255, 159, 64, 0.2)',
              tension: 0.3,
              fill: true
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              position: 'top'
            },
            title: {
              display: false
            }
          },
          scales: {
            y: {
              beginAtZero: false,
              title: {
                display: true,
                text: '波動率 (%)'
              }
            }
          }
        }
      })

      chartInstances.push(chart)
    }

    const initConeChart = () => {
      const ctx = volatilityConeChart.value.getContext('2d')
      const cone = volatilityStore.volatilityCone

      // 模擬波動率錐資料 (基於統計資料)
      const stats = volatilityStore.volatilityStats
      const labels = stats.map(s => s.period)
      
      const currentData = stats.map(s => s.historical_volatility ? (s.historical_volatility * 100) : null)
      const maxData = stats.map(s => s.historical_volatility ? (s.historical_volatility * 1.3 * 100) : null)
      const p75Data = stats.map(s => s.historical_volatility ? (s.historical_volatility * 1.15 * 100) : null)
      const medianData = stats.map(s => s.historical_volatility ? (s.historical_volatility * 1.0 * 100) : null)
      const p25Data = stats.map(s => s.historical_volatility ? (s.historical_volatility * 0.85 * 100) : null)
      const minData = stats.map(s => s.historical_volatility ? (s.historical_volatility * 0.7 * 100) : null)

      const chart = new Chart(ctx, {
        type: 'line',
        data: {
          labels: labels,
          datasets: [
            {
              label: '最大值',
              data: maxData,
              borderColor: 'rgb(255, 99, 132)',
              borderWidth: 1,
              fill: false,
              pointRadius: 0
            },
            {
              label: '75 分位',
              data: p75Data,
              borderColor: 'rgb(255, 206, 86)',
              borderWidth: 1,
              backgroundColor: 'rgba(255, 206, 86, 0.1)',
              fill: '-1',
              pointRadius: 0
            },
            {
              label: '中位數',
              data: medianData,
              borderColor: 'rgb(75, 192, 192)',
              borderWidth: 1,
              backgroundColor: 'rgba(75, 192, 192, 0.1)',
              fill: '-1',
              pointRadius: 0
            },
            {
              label: '25 分位',
              data: p25Data,
              borderColor: 'rgb(54, 162, 235)',
              borderWidth: 1,
              backgroundColor: 'rgba(54, 162, 235, 0.1)',
              fill: '-1',
              pointRadius: 0
            },
            {
              label: '最小值',
              data: minData,
              borderColor: 'rgb(153, 102, 255)',
              borderWidth: 1,
              backgroundColor: 'rgba(153, 102, 255, 0.1)',
              fill: '-1',
              pointRadius: 0
            },
            {
              label: '當前值',
              data: currentData,
              borderColor: 'rgb(0, 0, 255)',
              borderWidth: 3,
              fill: false,
              pointRadius: 4,
              pointBackgroundColor: 'rgb(0, 0, 255)'
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              position: 'top',
              labels: {
                boxWidth: 12
              }
            }
          },
          scales: {
            y: {
              beginAtZero: false,
              title: {
                display: true,
                text: '波動率 (%)'
              }
            }
          }
        }
      })

      chartInstances.push(chart)
    }

    const initGarchChart = () => {
      if (!volatilityStore.garchForecast?.forecasts) return

      const ctx = garchForecastChart.value.getContext('2d')
      const forecasts = volatilityStore.garchForecast.forecasts

      const labels = ['今日', ...forecasts.map(f => `+${f.day}日`)]
      const data = [
        volatilityStore.garchForecast.current_volatility * 100,
        ...forecasts.map(f => f.volatility * 100)
      ]

      const chart = new Chart(ctx, {
        type: 'line',
        data: {
          labels: labels,
          datasets: [
            {
              label: 'GARCH 預測波動率',
              data: data,
              borderColor: 'rgb(255, 159, 64)',
              backgroundColor: 'rgba(255, 159, 64, 0.2)',
              tension: 0.3,
              fill: true,
              pointRadius: 5,
              pointBackgroundColor: 'rgb(255, 159, 64)'
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              position: 'top'
            }
          },
          scales: {
            y: {
              beginAtZero: false,
              title: {
                display: true,
                text: '波動率 (%)'
              }
            }
          }
        }
      })

      chartInstances.push(chart)
    }

    // 監聽資料變化重繪圖表
    watch(
      () => volatilityStore.historicalVolatility,
      async (newVal) => {
        if (newVal) {
          await nextTick()
          initCharts()
        }
      }
    )

    // 生命週期
    onMounted(async () => {
      await loadStockList()
    })

    onUnmounted(() => {
      destroyCharts()
    })

    return {
      // Store
      volatilityStore,
      
      // 狀態
      selectedStock,
      stockList,
      loadingStocks,
      selectedPeriod,
      selectedMethod,
      selectedVolatilityType,
      
      // 選項
      periodOptions,
      methodOptions,
      volatilityTypeOptions,
      
      // 圖表參考
      volatilityTrendChart,
      volatilityConeChart,
      garchForecastChart,
      
      // 表格
      statsHeaders,
      
      // 計算屬性
      displayHV,
      displayIV,
      displayIvHvRatio,
      ivHvAnalysis,
      displayVolatilityRank,
      tradingRecommendation,
      formattedVolatilityStats,
      formatLastUpdated,
      
      // 方法
      onStockChange,
      calculateVolatility,
      refreshData,
      clearErrors,
      getVolatilityColor
    }
  }
}
</script>

<style scoped>
.volatility-page {
  padding: 16px;
}

.chart-container {
  position: relative;
  width: 100%;
}

.text-white-50 {
  color: rgba(255, 255, 255, 0.7);
}
</style>