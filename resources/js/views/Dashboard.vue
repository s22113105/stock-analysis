<template>
  <div class="dashboard-page">
    <!-- 頁面標題 -->
    <v-row class="mb-4">
      <v-col>
        <h1 class="text-h4">儀表板</h1>
        <p class="text-subtitle-1 text-grey mt-2">台股分析系統 - 即時市場概況</p>
      </v-col>
    </v-row>

    <!-- 核心圖表區 -->
    <v-row class="mb-4">
      <!-- 📈 熱門股票走勢 -->
      <v-col cols="12" md="6">
        <v-card elevation="3" class="h-100">
          <v-card-title class="d-flex justify-space-between align-center bg-primary">
            <span class="text-white">
              <v-icon color="white" class="mr-2">mdi-chart-line</v-icon>
              熱門股票走勢 (2330, 2317, 2454)
            </span>
            <v-btn
              icon
              size="small"
              variant="text"
              @click="loadStockTrends"
              :loading="loadingStocks"
            >
              <v-icon color="white">mdi-refresh</v-icon>
            </v-btn>
          </v-card-title>
          <v-card-text class="pa-4">
            <!-- 載入中 -->
            <div v-if="loadingStocks" class="text-center py-10">
              <v-progress-circular indeterminate color="primary"></v-progress-circular>
              <p class="mt-4">載入中...</p>
            </div>

            <!-- 錯誤訊息 -->
            <v-alert v-else-if="stockError" type="error" class="mb-4">
              {{ stockError }}
            </v-alert>

            <!-- 圖表容器 - 移除 v-else-if,讓它一直存在 -->
            <div v-show="!loadingStocks && !stockError" class="chart-container">
              <canvas ref="stockPriceChart" id="stockPriceChart"></canvas>
            </div>

            <!-- 股票漲跌標籤 -->
            <div v-if="topStocks.length > 0" class="mt-4 d-flex flex-wrap justify-center gap-2">
              <v-chip
                v-for="stock in topStocks"
                :key="stock.symbol"
                :color="stock.change_percent >= 0 ? 'red' : 'green'"
                size="small"
                class="ma-1"
              >
                <v-icon start :icon="stock.change_percent >= 0 ? 'mdi-arrow-up' : 'mdi-arrow-down'"></v-icon>
                {{ stock.symbol }}: {{ stock.change_percent >= 0 ? '+' : '' }}{{ stock.change_percent }}%
              </v-chip>
            </div>

            <!-- 最後更新時間 -->
            <div v-if="lastStockUpdate" class="text-caption text-grey text-center mt-2">
              最後更新: {{ lastStockUpdate }}
            </div>
          </v-card-text>
        </v-card>
      </v-col>

      <!-- 📊 波動率監控 -->
      <v-col cols="12" md="6">
        <v-card elevation="3" class="h-100">
          <v-card-title class="d-flex justify-space-between align-center" style="background-color: #424242;">
            <span class="text-white">
              <v-icon color="white" class="mr-2">mdi-chart-bell-curve</v-icon>
              波動率監控 (IV/HV)
            </span>
            <v-btn
              icon
              size="small"
              variant="text"
              @click="loadVolatilityData"
              :loading="loadingVolatility"
            >
              <v-icon color="white">mdi-refresh</v-icon>
            </v-btn>
          </v-card-title>
          <v-card-text class="pa-4">
            <!-- 載入中 -->
            <div v-if="loadingVolatility" class="text-center py-10">
              <v-progress-circular indeterminate color="primary"></v-progress-circular>
              <p class="mt-4">計算中...</p>
            </div>

            <!-- 錯誤訊息 -->
            <v-alert v-else-if="volatilityError" type="error" class="mb-4">
              {{ volatilityError }}
            </v-alert>

            <!-- 圖表容器 - 移除 v-else-if,讓它一直存在 -->
            <div v-show="!loadingVolatility && !volatilityError" class="chart-container">
              <canvas ref="volatilityChart" id="volatilityChart"></canvas>
            </div>

            <!-- 平均波動率 -->
            <v-row v-if="volatilityData.length > 0" class="mt-4">
              <v-col cols="6">
                <v-card variant="outlined" class="pa-3 text-center">
                  <div class="text-caption text-grey">平均歷史波動率</div>
                  <div class="text-h5 text-primary mt-1">{{ avgHV }}%</div>
                </v-card>
              </v-col>
              <v-col cols="6">
                <v-card variant="outlined" class="pa-3 text-center">
                  <div class="text-caption text-grey">平均隱含波動率</div>
                  <div class="text-h5 text-error mt-1">{{ avgIV }}%</div>
                </v-card>
              </v-col>
            </v-row>

            <!-- 最後更新時間 -->
            <div v-if="lastVolatilityUpdate" class="text-caption text-grey text-center mt-2">
              最後更新: {{ lastVolatilityUpdate }}
            </div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- AI 預測模型卡片 -->
    <v-row>
      <v-col cols="12">
        <v-card class="prediction-card" color="blue-grey-darken-3" theme="dark" elevation="3">
          <v-card-text class="pa-6">
            <v-row align="center">
              <v-col cols="12" md="8">
                <div class="d-flex align-center">
                  <v-avatar color="white" size="60" class="mr-4">
                    <v-icon color="primary" size="40">mdi-brain</v-icon>
                  </v-avatar>
                  <div>
                    <h3 class="text-h5 mb-2">AI 預測模型綜合分析</h3>
                    <p class="text-body-2 mb-0">
                      使用 LSTM、ARIMA、GARCH 等深度學習模型,預測股票未來走勢並提供價格區間
                    </p>
                    <div class="mt-2">
                      <v-chip size="small" color="primary" class="mr-2">
                        <v-icon start size="small">mdi-brain</v-icon>
                        LSTM 模型
                      </v-chip>
                      <v-chip size="small" color="success" class="mr-2">
                        <v-icon start size="small">mdi-chart-line</v-icon>
                        ARIMA 模型
                      </v-chip>
                      <v-chip size="small" color="warning">
                        <v-icon start size="small">mdi-chart-bell-curve</v-icon>
                        GARCH 模型
                      </v-chip>
                    </div>
                  </div>
                </div>
              </v-col>
              <v-col cols="12" md="4" class="text-right">
                <v-btn
                  color="white"
                  variant="flat"
                  size="large"
                  @click="goToPredictions"
                  prepend-icon="mdi-arrow-right"
                >
                  前往分析
                </v-btn>
              </v-col>
            </v-row>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>
  </div>
</template>

<script>
import { ref, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { Chart, registerables } from 'chart.js'
import axios from 'axios'

Chart.register(...registerables)

export default {
  name: 'Dashboard',
  setup() {
    const router = useRouter()

    // ==========================================
    // 狀態管理
    // ==========================================
    const loadingStocks = ref(false)
    const loadingVolatility = ref(false)
    const topStocks = ref([])
    const stockDates = ref([])
    const volatilityData = ref([])
    const avgHV = ref('-')
    const avgIV = ref('-')
    const lastStockUpdate = ref('')
    const lastVolatilityUpdate = ref('')
    const stockError = ref('')
    const volatilityError = ref('')

    // 圖表引用
    const stockPriceChart = ref(null)
    const volatilityChart = ref(null)
    let stockChartInstance = null
    let volatilityChartInstance = null

    // ==========================================
    // API 呼叫 - 熱門股票走勢
    // ==========================================
    const loadStockTrends = async () => {
      loadingStocks.value = true
      stockError.value = ''

      try {
        console.log('📊 開始載入股票走勢資料...')

        const response = await axios.get('dashboard/stock-trends', {
          params: { days: 30 }
        })

        console.log('✅ API 回應:', response.data)

        if (response.data.success) {
          const data = response.data.data
          topStocks.value = data.stocks || []
          stockDates.value = data.dates || []

          console.log('📈 股票資料:', {
            count: topStocks.value.length,
            dates: stockDates.value.length
          })

          lastStockUpdate.value = new Date().toLocaleString('zh-TW')
        } else {
          stockError.value = response.data.message || '載入失敗'
        }
      } catch (error) {
        console.error('❌ 載入股票走勢失敗:', error)
        stockError.value = error.response?.data?.message || '載入股票走勢失敗'
      } finally {
        loadingStocks.value = false
      }
    }

    // ==========================================
    // API 呼叫 - 波動率資料
    // ==========================================
    const loadVolatilityData = async () => {
      loadingVolatility.value = true
      volatilityError.value = ''

      try {
        console.log('📈 開始載入波動率資料...')

        const response = await axios.get('dashboard/volatility-overview')

        console.log('✅ 波動率 API 回應:', response.data)

        if (response.data.success) {
          const data = response.data.data
          volatilityData.value = data.volatilities || []
          avgHV.value = data.avg_hv?.toFixed(2) || '-'
          avgIV.value = data.avg_iv?.toFixed(2) || '-'

          console.log('📊 波動率資料:', volatilityData.value)

          lastVolatilityUpdate.value = new Date().toLocaleString('zh-TW')
        } else {
          volatilityError.value = response.data.message || '載入失敗'
        }
      } catch (error) {
        console.error('❌ 載入波動率資料失敗:', error)
        volatilityError.value = error.response?.data?.message || '載入波動率資料失敗'
      } finally {
        loadingVolatility.value = false
      }
    }

    // ==========================================
    // 繪製股票走勢圖
    // ==========================================
    const renderStockChart = () => {
      console.log('🎨 開始繪製股票走勢圖...')
      console.log('Canvas ref:', stockPriceChart.value)

      if (!stockPriceChart.value) {
        console.error('❌ Canvas 元素不存在!')
        // 使用 setTimeout 重試
        setTimeout(() => {
          if (stockPriceChart.value) {
            console.log('✅ 延遲後找到 Canvas,重新繪製')
            renderStockChart()
          }
        }, 100)
        return
      }

      console.log('✅ Canvas 元素存在')

      try {
        const ctx = stockPriceChart.value.getContext('2d')

        // 銷毀舊圖表
        if (stockChartInstance) {
          stockChartInstance.destroy()
        }

        // 準備資料集
        const datasets = topStocks.value.map((stock, index) => {
          const colors = [
            { border: 'rgb(75, 192, 192)', bg: 'rgba(75, 192, 192, 0.1)' },
            { border: 'rgb(255, 99, 132)', bg: 'rgba(255, 99, 132, 0.1)' },
            { border: 'rgb(54, 162, 235)', bg: 'rgba(54, 162, 235, 0.1)' }
          ]
          const color = colors[index % colors.length]

          return {
            label: `${stock.symbol} ${stock.name}`,
            data: stock.prices || [],
            borderColor: color.border,
            backgroundColor: color.bg,
            tension: 0.3,
            fill: true,
            pointRadius: 2,
            pointHoverRadius: 5
          }
        })

        // 建立圖表
        stockChartInstance = new Chart(ctx, {
          type: 'line',
          data: {
            labels: stockDates.value,
            datasets: datasets
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
              mode: 'index',
              intersect: false
            },
            plugins: {
              legend: {
                position: 'top',
                labels: {
                  padding: 10,
                  font: { size: 12 }
                }
              },
              tooltip: {
                callbacks: {
                  label: function(context) {
                    return context.dataset.label + ': NT$ ' + context.parsed.y.toFixed(2)
                  }
                }
              }
            },
            scales: {
              x: {
                display: true,
                title: { display: true, text: '日期' }
              },
              y: {
                display: true,
                beginAtZero: false,
                title: { display: true, text: '股價 (NT$)' }
              }
            }
          }
        })

        console.log('✅ 股票走勢圖繪製完成!')
      } catch (error) {
        console.error('❌ 繪製圖表時發生錯誤:', error)
      }
    }

    // ==========================================
    // 繪製波動率圖表
    // ==========================================
    const renderVolatilityChart = () => {
      console.log('🎨 開始繪製波動率圖表...')
      console.log('Canvas ref:', volatilityChart.value)

      if (!volatilityChart.value) {
        console.error('❌ Canvas 元素不存在!')
        // 使用 setTimeout 重試
        setTimeout(() => {
          if (volatilityChart.value) {
            console.log('✅ 延遲後找到 Canvas,重新繪製')
            renderVolatilityChart()
          }
        }, 100)
        return
      }

      console.log('✅ Canvas 元素存在')

      try {
        const ctx = volatilityChart.value.getContext('2d')

        // 銷毀舊圖表
        if (volatilityChartInstance) {
          volatilityChartInstance.destroy()
        }

        const labels = volatilityData.value.map(v => v.symbol)
        const hvData = volatilityData.value.map(v => v.hv)
        const ivData = volatilityData.value.map(v => v.iv)

        // 建立圖表
        volatilityChartInstance = new Chart(ctx, {
          type: 'bar',
          data: {
            labels: labels,
            datasets: [
              {
                label: '歷史波動率 (HV)',
                data: hvData,
                backgroundColor: 'rgba(54, 162, 235, 0.5)',
                borderColor: 'rgb(54, 162, 235)',
                borderWidth: 1
              },
              {
                label: '隱含波動率 (IV)',
                data: ivData,
                backgroundColor: 'rgba(255, 99, 132, 0.5)',
                borderColor: 'rgb(255, 99, 132)',
                borderWidth: 1
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
                  padding: 10,
                  font: { size: 12 }
                }
              },
              tooltip: {
                callbacks: {
                  label: function(context) {
                    return context.dataset.label + ': ' + context.parsed.y.toFixed(2) + '%'
                  }
                }
              }
            },
            scales: {
              x: {
                display: true,
                title: { display: true, text: '股票代碼' }
              },
              y: {
                display: true,
                beginAtZero: true,
                title: { display: true, text: '波動率 (%)' }
              }
            }
          }
        })

        console.log('✅ 波動率圖表繪製完成!')
      } catch (error) {
        console.error('❌ 繪製圖表時發生錯誤:', error)
      }
    }

    // ==========================================
    // Watch 監聽資料變化
    // ==========================================

    // 當股票資料載入完成時繪製圖表
    watch(topStocks, (newVal) => {
      if (newVal && newVal.length > 0) {
        console.log('👀 偵測到股票資料變化,準備繪製圖表...')
        setTimeout(() => {
          renderStockChart()
        }, 100)
      }
    })

    // 當波動率資料載入完成時繪製圖表
    watch(volatilityData, (newVal) => {
      if (newVal && newVal.length > 0) {
        console.log('👀 偵測到波動率資料變化,準備繪製圖表...')
        setTimeout(() => {
          renderVolatilityChart()
        }, 100)
      }
    })

    // ==========================================
    // 前往預測模型頁面
    // ==========================================
    const goToPredictions = () => {
      router.push({ name: 'PredictionAnalysis' })
    }

    // ==========================================
    // 生命週期
    // ==========================================
    onMounted(() => {
      console.log('🚀 Dashboard 載入完成')
      console.log('Canvas refs:', {
        stock: stockPriceChart.value,
        volatility: volatilityChart.value
      })

      // 初始載入資料
      loadStockTrends()
      loadVolatilityData()
    })

    // ==========================================
    // 返回
    // ==========================================
    return {
      // 狀態
      loadingStocks,
      loadingVolatility,
      topStocks,
      volatilityData,
      avgHV,
      avgIV,
      lastStockUpdate,
      lastVolatilityUpdate,
      stockError,
      volatilityError,
      // 圖表引用
      stockPriceChart,
      volatilityChart,
      // 方法
      loadStockTrends,
      loadVolatilityData,
      goToPredictions
    }
  }
}
</script>

<style scoped>
.dashboard-page {
  padding: 16px;
}

/* 確保圖表容器有明確的高度 */
.chart-container {
  position: relative;
  height: 350px;
  min-height: 350px;
  width: 100%;
}

.prediction-card {
  transition: all 0.3s ease;
}

.prediction-card:hover {
  transform: translateY(-5px);
  box-shadow: 0 8px 16px rgba(0, 0, 0, 0.2) !important;
}

.h-100 {
  height: 100%;
}

.gap-2 {
  gap: 8px;
}
</style>
