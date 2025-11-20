<template>
  <div class="dashboard-page">
    <!-- 頁面標題 -->
    <v-row class="mb-4">
      <v-col>
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
              熱門股票走勢
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
            <!-- 圖表 -->
            <div style="position: relative; height: 350px;">
              <canvas ref="stockPriceChart"></canvas>
            </div>

            <!-- 股票漲跌標籤 -->
            <div class="mt-4 d-flex flex-wrap justify-center gap-2">
              <v-chip
                v-for="stock in topStocks"
                :key="stock.symbol"
                :color="stock.change_percent >= 0 ? 'success' : 'error'"
                size="small"
                class="ma-1"
              >
                <v-icon start :icon="stock.change_percent >= 0 ? 'mdi-arrow-up' : 'mdi-arrow-down'"></v-icon>
                {{ stock.symbol }}: {{ stock.change_percent >= 0 ? '+' : '' }}{{ stock.change_percent }}%
              </v-chip>
            </div>

            <!-- 更新時間 -->
            <div class="text-center text-caption text-grey mt-2" v-if="lastStockUpdate">
              最後更新: {{ lastStockUpdate }}
            </div>
          </v-card-text>
        </v-card>
      </v-col>

      <!-- 📊 波動率監控 (IV/HV) -->
      <v-col cols="12" md="6">
        <v-card elevation="3" class="h-100">
          <v-card-title class="d-flex justify-space-between align-center bg-secondary">
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
            <!-- 圖表 -->
            <div style="position: relative; height: 350px;">
              <canvas ref="volatilityChart"></canvas>
            </div>

            <!-- 平均波動率顯示 -->
            <v-row dense class="mt-4">
              <v-col cols="6">
                <v-card variant="outlined" color="info">
                  <v-card-text class="pa-3 text-center">
                    <div class="text-caption text-grey">平均歷史波動率</div>
                    <div class="text-h5 font-weight-bold text-info">{{ avgHV }}%</div>
                  </v-card-text>
                </v-card>
              </v-col>
              <v-col cols="6">
                <v-card variant="outlined" color="error">
                  <v-card-text class="pa-3 text-center">
                    <div class="text-caption text-grey">平均隱含波動率</div>
                    <div class="text-h5 font-weight-bold text-error">{{ avgIV }}%</div>
                  </v-card-text>
                </v-card>
              </v-col>
            </v-row>

            <!-- 更新時間 -->
            <div class="text-center text-caption text-grey mt-2" v-if="lastVolatilityUpdate">
              最後更新: {{ lastVolatilityUpdate }}
            </div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- 🔮 AI 預測模型 - 美觀超連結卡片 -->
    <v-row>
      <v-col cols="12">
        <v-card
          elevation="3"
          class="prediction-card"
          hover
          @click="goToPredictions"
          style="cursor: pointer; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);"
        >
          <v-card-text class="pa-6">
            <v-row align="center">
              <v-col cols="12" md="2" class="text-center">
                <v-avatar size="80" color="white">
                  <v-icon size="50" color="purple">mdi-crystal-ball</v-icon>
                </v-avatar>
              </v-col>
              <v-col cols="12" md="8">
                <h2 class="text-h5 text-white font-weight-bold mb-2">
                  🤖 AI 預測模型綜合分析
                </h2>
                <p class="text-white text-body-1 mb-0">
                  使用 LSTM、ARIMA、GARCH 等深度學習模型,預測股票未來走勢與價格區間
                </p>
                <div class="mt-3">
                  <v-chip color="white" size="small" class="mr-2">
                    <v-icon start size="small">mdi-chart-timeline</v-icon>
                    LSTM 模型
                  </v-chip>
                  <v-chip color="white" size="small" class="mr-2">
                    <v-icon start size="small">mdi-chart-areaspline</v-icon>
                    ARIMA 模型
                  </v-chip>
                  <v-chip color="white" size="small">
                    <v-icon start size="small">mdi-chart-bell-curve-cumulative</v-icon>
                    GARCH 模型
                  </v-chip>
                </div>
              </v-col>
              <v-col cols="12" md="2" class="text-center">
                <v-btn
                  color="white"
                  size="large"
                  rounded="lg"
                  @click.stop="goToPredictions"
                >
                  前往分析
                  <v-icon end>mdi-arrow-right</v-icon>
                </v-btn>
              </v-col>
            </v-row>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- 載入錯誤提示 -->
    <v-snackbar v-model="showError" color="error" :timeout="5000" top>
      {{ errorMessage }}
      <template v-slot:actions>
        <v-btn variant="text" @click="showError = false">關閉</v-btn>
      </template>
    </v-snackbar>
  </div>
</template>

<script>
import { ref, onMounted, onUnmounted } from 'vue'
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
    const volatilityData = ref([])
    const avgHV = ref('-')
    const avgIV = ref('-')
    const lastStockUpdate = ref('')
    const lastVolatilityUpdate = ref('')
    const showError = ref(false)
    const errorMessage = ref('')

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
      try {
        // 取得前5名熱門股票的最近30天價格資料
        const response = await axios.get('/api/dashboard/stock-trends', {
          params: {
            limit: 5,
            days: 30
          }
        })

        if (response.data.success) {
          const data = response.data.data
          topStocks.value = data.stocks || []

          // 繪製圖表
          renderStockChart(data)

          lastStockUpdate.value = new Date().toLocaleString('zh-TW')
        }
      } catch (error) {
        console.error('載入股票走勢失敗:', error)
        errorMessage.value = '載入股票走勢失敗: ' + (error.response?.data?.message || error.message)
        showError.value = true
      } finally {
        loadingStocks.value = false
      }
    }

    // ==========================================
    // API 呼叫 - 波動率資料
    // ==========================================
    const loadVolatilityData = async () => {
      loadingVolatility.value = true
      try {
        const response = await axios.get('/api/dashboard/volatility-overview', {
          params: {
            limit: 5
          }
        })

        if (response.data.success) {
          const data = response.data.data
          volatilityData.value = data.volatilities || []
          avgHV.value = data.avg_hv?.toFixed(2) || '-'
          avgIV.value = data.avg_iv?.toFixed(2) || '-'

          // 繪製圖表
          renderVolatilityChart(data)

          lastVolatilityUpdate.value = new Date().toLocaleString('zh-TW')
        }
      } catch (error) {
        console.error('載入波動率資料失敗:', error)
        errorMessage.value = '載入波動率資料失敗: ' + (error.response?.data?.message || error.message)
        showError.value = true
      } finally {
        loadingVolatility.value = false
      }
    }

    // ==========================================
    // 繪製股票走勢圖
    // ==========================================
    const renderStockChart = (data) => {
      if (!stockPriceChart.value) return

      const ctx = stockPriceChart.value.getContext('2d')

      // 銷毀舊圖表
      if (stockChartInstance) {
        stockChartInstance.destroy()
      }

      // 準備資料集
      const datasets = (data.stocks || []).map((stock, index) => {
        const colors = [
          { border: 'rgb(75, 192, 192)', bg: 'rgba(75, 192, 192, 0.1)' },
          { border: 'rgb(255, 99, 132)', bg: 'rgba(255, 99, 132, 0.1)' },
          { border: 'rgb(54, 162, 235)', bg: 'rgba(54, 162, 235, 0.1)' },
          { border: 'rgb(255, 206, 86)', bg: 'rgba(255, 206, 86, 0.1)' },
          { border: 'rgb(153, 102, 255)', bg: 'rgba(153, 102, 255, 0.1)' }
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
          labels: data.dates || [],
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
                usePointStyle: true,
                padding: 15,
                font: {
                  size: 12
                }
              }
            },
            tooltip: {
              callbacks: {
                label: function(context) {
                  return context.dataset.label + ': $' + context.parsed.y.toFixed(2)
                }
              }
            }
          },
          scales: {
            x: {
              display: true,
              title: {
                display: true,
                text: '日期'
              }
            },
            y: {
              display: true,
              title: {
                display: true,
                text: '價格 (NT$)'
              }
            }
          }
        }
      })
    }

    // ==========================================
    // 繪製波動率圖表
    // ==========================================
    const renderVolatilityChart = (data) => {
      if (!volatilityChart.value) return

      const ctx = volatilityChart.value.getContext('2d')

      // 銷毀舊圖表
      if (volatilityChartInstance) {
        volatilityChartInstance.destroy()
      }

      // 準備資料
      const labels = (data.volatilities || []).map(v => v.symbol)
      const hvData = (data.volatilities || []).map(v => v.hv)
      const ivData = (data.volatilities || []).map(v => v.iv)

      // 建立圖表
      volatilityChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
          labels: labels,
          datasets: [
            {
              label: '歷史波動率 (HV)',
              data: hvData,
              backgroundColor: 'rgba(54, 162, 235, 0.7)',
              borderColor: 'rgb(54, 162, 235)',
              borderWidth: 1
            },
            {
              label: '隱含波動率 (IV)',
              data: ivData,
              backgroundColor: 'rgba(255, 99, 132, 0.7)',
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
                usePointStyle: true,
                padding: 15,
                font: {
                  size: 12
                }
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
              title: {
                display: true,
                text: '股票代碼'
              }
            },
            y: {
              display: true,
              beginAtZero: true,
              title: {
                display: true,
                text: '波動率 (%)'
              }
            }
          }
        }
      })
    }

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
      // 初始載入資料
      loadStockTrends()
      loadVolatilityData()

      // 每5分鐘自動更新
      const interval = setInterval(() => {
        loadStockTrends()
        loadVolatilityData()
      }, 300000) // 5分鐘

      // 清理定時器
      onUnmounted(() => {
        clearInterval(interval)
        if (stockChartInstance) stockChartInstance.destroy()
        if (volatilityChartInstance) volatilityChartInstance.destroy()
      })
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
      showError,
      errorMessage,
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
