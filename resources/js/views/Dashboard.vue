<template>
  <div class="dashboard-page">
    <!-- 頂部統計卡片 -->
    <v-row class="mb-4">
      <v-col cols="12" md="3">
        <v-card elevation="2">
          <v-card-text>
            <div class="d-flex justify-space-between align-center">
              <div>
                <div class="text-subtitle-2 text-grey">投資組合總值</div>
                <div class="text-h5 font-weight-bold">
                  ${{ totalValue.toLocaleString() }}
                </div>
                <div :class="totalValueChange >= 0 ? 'text-success' : 'text-error'" class="text-caption">
                  <v-icon size="small" :icon="totalValueChange >= 0 ? 'mdi-arrow-up' : 'mdi-arrow-down'"></v-icon>
                  {{ totalValueChange >= 0 ? '+' : '' }}{{ totalValueChange }}%
                </div>
              </div>
              <v-avatar color="primary" size="48">
                <v-icon color="white">mdi-wallet</v-icon>
              </v-avatar>
            </div>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="3">
        <v-card elevation="2">
          <v-card-text>
            <div class="d-flex justify-space-between align-center">
              <div>
                <div class="text-subtitle-2 text-grey">今日損益</div>
                <div class="text-h5 font-weight-bold" :class="todayPL >= 0 ? 'text-success' : 'text-error'">
                  {{ todayPL >= 0 ? '+' : '' }}${{ Math.abs(todayPL).toLocaleString() }}
                </div>
                <div :class="todayPLPercent >= 0 ? 'text-success' : 'text-error'" class="text-caption">
                  {{ todayPLPercent >= 0 ? '+' : '' }}{{ todayPLPercent }}%
                </div>
              </div>
              <v-avatar :color="todayPL >= 0 ? 'success' : 'error'" size="48">
                <v-icon color="white">mdi-chart-line</v-icon>
              </v-avatar>
            </div>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="3">
        <v-card elevation="2">
          <v-card-text>
            <div class="d-flex justify-space-between align-center">
              <div>
                <div class="text-subtitle-2 text-grey">持倉部位</div>
                <div class="text-h5 font-weight-bold">{{ openPositions }}</div>
                <div class="text-caption text-grey">{{ pendingOrders }} 筆待成交</div>
              </div>
              <v-avatar color="info" size="48">
                <v-icon color="white">mdi-chart-bar</v-icon>
              </v-avatar>
            </div>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="3">
        <v-card elevation="2">
          <v-card-text>
            <div class="d-flex justify-space-between align-center">
              <div>
                <div class="text-subtitle-2 text-grey">風險評分</div>
                <div class="text-h5 font-weight-bold">{{ riskScore }}/100</div>
                <div class="text-caption" :class="getRiskColor(riskScore)">
                  {{ getRiskLevel(riskScore) }}
                </div>
              </div>
              <v-avatar :color="getRiskColor(riskScore)" size="48">
                <v-icon color="white">mdi-shield-alert</v-icon>
              </v-avatar>
            </div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- 核心圖表區 -->
    <v-row class="mb-4">
      <!-- 📊 圖表 1: 股價走勢總覽 -->
      <v-col cols="12" md="6">
        <v-card elevation="2">
          <v-card-title class="d-flex justify-space-between align-center">
            <span>📈 熱門股票走勢</span>
            <v-btn size="small" variant="text" @click="refreshStockChart">
              <v-icon>mdi-refresh</v-icon>
            </v-btn>
          </v-card-title>
          <v-card-text>
            <div style="position: relative; height: 300px;">
              <canvas ref="stockPriceChart"></canvas>
            </div>
            <div class="mt-2 d-flex justify-space-around">
              <v-chip
                v-for="stock in topStocks"
                :key="stock.symbol"
                :color="stock.change >= 0 ? 'success' : 'error'"
                size="small"
                class="ma-1"
              >
                {{ stock.symbol }}: {{ stock.change >= 0 ? '+' : '' }}{{ stock.change }}%
              </v-chip>
            </div>
          </v-card-text>
        </v-card>
      </v-col>

      <!-- 📊 圖表 2: IV/HV 波動率監控 -->
      <v-col cols="12" md="6">
        <v-card elevation="2">
          <v-card-title class="d-flex justify-space-between align-center">
            <span>📊 波動率監控 (IV/HV)</span>
            <v-btn size="small" variant="text" @click="refreshVolatilityChart">
              <v-icon>mdi-refresh</v-icon>
            </v-btn>
          </v-card-title>
          <v-card-text>
            <div style="position: relative; height: 300px;">
              <canvas ref="volatilityChart"></canvas>
            </div>
            <div class="mt-2">
              <v-row dense>
                <v-col cols="6">
                  <div class="text-caption text-grey">平均 HV</div>
                  <div class="text-subtitle-1 font-weight-bold">{{ avgHV }}%</div>
                </v-col>
                <v-col cols="6">
                  <div class="text-caption text-grey">平均 IV</div>
                  <div class="text-subtitle-1 font-weight-bold">{{ avgIV }}%</div>
                </v-col>
              </v-row>
            </div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- 📊 圖表 3: 預測線綜合顯示 -->
    <v-row class="mb-4">
      <v-col cols="12">
        <v-card elevation="2">
          <v-card-title class="d-flex justify-space-between align-center">
            <span>🔮 AI 預測模型綜合分析</span>
            <div>
              <v-btn-toggle v-model="selectedPredictionModel" mandatory density="compact" class="mr-2">
                <v-btn value="lstm" size="small">LSTM</v-btn>
                <v-btn value="arima" size="small">ARIMA</v-btn>
                <v-btn value="garch" size="small">GARCH</v-btn>
              </v-btn-toggle>
              <v-btn size="small" variant="text" @click="refreshPredictionChart">
                <v-icon>mdi-refresh</v-icon>
              </v-btn>
            </div>
          </v-card-title>
          <v-card-text>
            <div style="position: relative; height: 350px;">
              <canvas ref="predictionChart"></canvas>
            </div>
            <v-row dense class="mt-2">
              <v-col cols="12" md="4">
                <v-card variant="outlined">
                  <v-card-text class="pa-2">
                    <div class="text-caption text-grey">預測漲跌</div>
                    <div class="text-h6" :class="predictionTrend >= 0 ? 'text-success' : 'text-error'">
                      {{ predictionTrend >= 0 ? '↑' : '↓' }} {{ Math.abs(predictionTrend) }}%
                    </div>
                  </v-card-text>
                </v-card>
              </v-col>
              <v-col cols="12" md="4">
                <v-card variant="outlined">
                  <v-card-text class="pa-2">
                    <div class="text-caption text-grey">預測準確度</div>
                    <div class="text-h6">{{ predictionAccuracy }}%</div>
                  </v-card-text>
                </v-card>
              </v-col>
              <v-col cols="12" md="4">
                <v-card variant="outlined">
                  <v-card-text class="pa-2">
                    <div class="text-caption text-grey">信心水準</div>
                    <div class="text-h6">{{ predictionConfidence }}%</div>
                  </v-card-text>
                </v-card>
              </v-col>
            </v-row>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- 持倉列表 -->
    <v-row>
      <v-col cols="12">
        <v-card elevation="2">
          <v-card-title>
            <v-row align="center">
              <v-col cols="12" md="6">
                <span>持倉部位</span>
              </v-col>
              <v-col cols="12" md="6">
                <v-text-field
                  v-model="search"
                  prepend-inner-icon="mdi-magnify"
                  label="搜尋股票"
                  single-line
                  hide-details
                  density="compact"
                ></v-text-field>
              </v-col>
            </v-row>
          </v-card-title>
          <v-card-text>
            <v-data-table
              :headers="positionHeaders"
              :items="positions"
              :search="search"
              items-per-page="10"
            >
              <template v-slot:item.pl="{ item }">
                <span :class="item.pl >= 0 ? 'text-success' : 'text-error'">
                  {{ item.pl >= 0 ? '+' : '' }}${{ Math.abs(item.pl).toLocaleString() }}
                </span>
              </template>

              <template v-slot:item.change="{ item }">
                <v-chip :color="item.change >= 0 ? 'success' : 'error'" size="small">
                  {{ item.change >= 0 ? '+' : '' }}{{ item.change }}%
                </v-chip>
              </template>

              <template v-slot:item.actions="{ item }">
                <v-btn size="small" color="primary" variant="text" @click="viewDetail(item)">
                  詳情
                </v-btn>
                <v-btn size="small" color="error" variant="text" @click="closePosition(item)">
                  平倉
                </v-btn>
              </template>
            </v-data-table>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>
  </div>
</template>

<script>
import { ref, onMounted, onUnmounted } from 'vue'
import { Chart, registerables } from 'chart.js'
import axios from 'axios'

Chart.register(...registerables)

export default {
  name: 'Dashboard',
  setup() {
    // 資料狀態
    const totalValue = ref(1250000)
    const totalValueChange = ref(2.5)
    const todayPL = ref(15000)
    const todayPLPercent = ref(1.2)
    const openPositions = ref(12)
    const pendingOrders = ref(3)
    const riskScore = ref(35)
    const search = ref('')

    // 圖表參考
    const stockPriceChart = ref(null)
    const volatilityChart = ref(null)
    const predictionChart = ref(null)

    let stockChartInstance = null
    let volatilityChartInstance = null
    let predictionChartInstance = null

    // 波動率數據
    const avgHV = ref(24.5)
    const avgIV = ref(28.3)

    // 預測數據
    const selectedPredictionModel = ref('lstm')
    const predictionTrend = ref(3.2)
    const predictionAccuracy = ref(82.5)
    const predictionConfidence = ref(95)

    // 熱門股票
    const topStocks = ref([
      { symbol: '2330', name: '台積電', change: 2.5 },
      { symbol: '2317', name: '鴻海', change: -0.8 },
      { symbol: '2454', name: '聯發科', change: 1.3 }
    ])

    // 表格標題
    const positionHeaders = ref([
      { title: '股票代碼', key: 'symbol' },
      { title: '名稱', key: 'name' },
      { title: '持倉數量', key: 'quantity' },
      { title: '成本價', key: 'cost' },
      { title: '現價', key: 'current' },
      { title: '損益', key: 'pl' },
      { title: '漲跌幅', key: 'change' },
      { title: '操作', key: 'actions', sortable: false }
    ])

    // 模擬持倉資料
    const positions = ref([
      { symbol: '2330', name: '台積電', quantity: 1000, cost: 580, current: 595, pl: 15000, change: 2.59 },
      { symbol: '2317', name: '鴻海', quantity: 2000, cost: 105, current: 103, pl: -4000, change: -1.90 },
      { symbol: '2454', name: '聯發科', quantity: 500, cost: 920, current: 935, pl: 7500, change: 1.63 }
    ])

    // 風險評分顏色
    const getRiskColor = (score) => {
      if (score < 30) return 'success'
      if (score < 60) return 'warning'
      return 'error'
    }

    const getRiskLevel = (score) => {
      if (score < 30) return '低風險'
      if (score < 60) return '中風險'
      return '高風險'
    }

    // 📊 初始化股價走勢圖
    const initStockPriceChart = async () => {
      if (!stockPriceChart.value) return

      try {
        // 調用真實 API 獲取數據
        const response = await axios.get('/api/dashboard/stock-trends')
        const data = response.data.data

        const ctx = stockPriceChart.value.getContext('2d')
        
        if (stockChartInstance) {
          stockChartInstance.destroy()
        }

        stockChartInstance = new Chart(ctx, {
          type: 'line',
          data: {
            labels: data.dates || generateDateLabels(30),
            datasets: [
              {
                label: '2330 台積電',
                data: data.tsmc || generateMockPrices(30, 580, 610),
                borderColor: 'rgb(75, 192, 192)',
                backgroundColor: 'rgba(75, 192, 192, 0.1)',
                tension: 0.3,
                fill: true
              },
              {
                label: '2317 鴻海',
                data: data.hon_hai || generateMockPrices(30, 100, 110),
                borderColor: 'rgb(255, 99, 132)',
                backgroundColor: 'rgba(255, 99, 132, 0.1)',
                tension: 0.3,
                fill: true
              },
              {
                label: '2454 聯發科',
                data: data.mediatek || generateMockPrices(30, 900, 950),
                borderColor: 'rgb(54, 162, 235)',
                backgroundColor: 'rgba(54, 162, 235, 0.1)',
                tension: 0.3,
                fill: true
              }
            ]
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
                  padding: 15
                }
              },
              tooltip: {
                callbacks: {
                  label: function(context) {
                    return context.dataset.label + ': NT$' + context.parsed.y.toFixed(2)
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
      } catch (error) {
        console.error('載入股價圖表失敗:', error)
        // 使用模擬數據
        initStockPriceChartWithMockData()
      }
    }

    // 使用模擬數據初始化股價圖表
    const initStockPriceChartWithMockData = () => {
      if (!stockPriceChart.value) return

      const ctx = stockPriceChart.value.getContext('2d')
      
      if (stockChartInstance) {
        stockChartInstance.destroy()
      }

      stockChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
          labels: generateDateLabels(30),
          datasets: [
            {
              label: '2330 台積電',
              data: generateMockPrices(30, 580, 610),
              borderColor: 'rgb(75, 192, 192)',
              backgroundColor: 'rgba(75, 192, 192, 0.1)',
              tension: 0.3,
              fill: true
            },
            {
              label: '2317 鴻海',
              data: generateMockPrices(30, 100, 110),
              borderColor: 'rgb(255, 99, 132)',
              backgroundColor: 'rgba(255, 99, 132, 0.1)',
              tension: 0.3,
              fill: true
            },
            {
              label: '2454 聯發科',
              data: generateMockPrices(30, 900, 950),
              borderColor: 'rgb(54, 162, 235)',
              backgroundColor: 'rgba(54, 162, 235, 0.1)',
              tension: 0.3,
              fill: true
            }
          ]
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
              position: 'top'
            }
          },
          scales: {
            y: {
              title: {
                display: true,
                text: '價格 (NT$)'
              }
            }
          }
        }
      })
    }

    // 📊 初始化波動率圖表
    const initVolatilityChart = async () => {
      if (!volatilityChart.value) return

      try {
        const response = await axios.get('/api/dashboard/volatility-overview')
        const data = response.data.data

        const ctx = volatilityChart.value.getContext('2d')
        
        if (volatilityChartInstance) {
          volatilityChartInstance.destroy()
        }

        volatilityChartInstance = new Chart(ctx, {
          type: 'bar',
          data: {
            labels: data.stocks || ['2330', '2317', '2454', '2412', '2308'],
            datasets: [
              {
                label: '歷史波動率 (HV)',
                data: data.hv || [24.5, 28.3, 31.2, 22.8, 26.7],
                backgroundColor: 'rgba(54, 162, 235, 0.6)',
                borderColor: 'rgb(54, 162, 235)',
                borderWidth: 1
              },
              {
                label: '隱含波動率 (IV)',
                data: data.iv || [28.3, 32.1, 35.4, 26.5, 30.2],
                backgroundColor: 'rgba(255, 99, 132, 0.6)',
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
                position: 'top'
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
              y: {
                beginAtZero: true,
                title: {
                  display: true,
                  text: '波動率 (%)'
                }
              }
            }
          }
        })

        // 更新平均值
        avgHV.value = (data.avg_hv || 26.7).toFixed(1)
        avgIV.value = (data.avg_iv || 30.5).toFixed(1)
      } catch (error) {
        console.error('載入波動率圖表失敗:', error)
        initVolatilityChartWithMockData()
      }
    }

    // 使用模擬數據初始化波動率圖表
    const initVolatilityChartWithMockData = () => {
      if (!volatilityChart.value) return

      const ctx = volatilityChart.value.getContext('2d')
      
      if (volatilityChartInstance) {
        volatilityChartInstance.destroy()
      }

      volatilityChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
          labels: ['2330', '2317', '2454', '2412', '2308'],
          datasets: [
            {
              label: '歷史波動率 (HV)',
              data: [24.5, 28.3, 31.2, 22.8, 26.7],
              backgroundColor: 'rgba(54, 162, 235, 0.6)',
              borderColor: 'rgb(54, 162, 235)',
              borderWidth: 1
            },
            {
              label: '隱含波動率 (IV)',
              data: [28.3, 32.1, 35.4, 26.5, 30.2],
              backgroundColor: 'rgba(255, 99, 132, 0.6)',
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
              position: 'top'
            }
          },
          scales: {
            y: {
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

    // 📊 初始化預測圖表
    const initPredictionChart = async () => {
      if (!predictionChart.value) return

      try {
        const response = await axios.get('/api/dashboard/prediction-overview', {
          params: { model: selectedPredictionModel.value }
        })
        const data = response.data.data

        const ctx = predictionChart.value.getContext('2d')
        
        if (predictionChartInstance) {
          predictionChartInstance.destroy()
        }

        predictionChartInstance = new Chart(ctx, {
          type: 'line',
          data: {
            labels: data.dates || generateFutureDateLabels(7),
            datasets: [
              {
                label: '歷史價格',
                data: data.historical || generateMockPrices(7, 580, 600),
                borderColor: 'rgb(201, 203, 207)',
                backgroundColor: 'rgba(201, 203, 207, 0.1)',
                tension: 0.3,
                fill: false,
                pointRadius: 3
              },
              {
                label: '預測價格',
                data: data.predictions || generateMockPrices(7, 595, 615),
                borderColor: 'rgb(75, 192, 192)',
                backgroundColor: 'rgba(75, 192, 192, 0.1)',
                tension: 0.3,
                fill: true,
                pointRadius: 5,
                pointStyle: 'circle',
                borderWidth: 2
              },
              {
                label: '信賴上界',
                data: data.upper_bound || generateMockPrices(7, 605, 625),
                borderColor: 'rgba(255, 99, 132, 0.5)',
                borderDash: [5, 5],
                fill: false,
                pointRadius: 0,
                borderWidth: 1
              },
              {
                label: '信賴下界',
                data: data.lower_bound || generateMockPrices(7, 585, 595),
                borderColor: 'rgba(255, 99, 132, 0.5)',
                borderDash: [5, 5],
                fill: '-1',
                backgroundColor: 'rgba(255, 99, 132, 0.1)',
                pointRadius: 0,
                borderWidth: 1
              }
            ]
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
                position: 'top'
              },
              title: {
                display: true,
                text: `${selectedPredictionModel.value.toUpperCase()} 模型 7 日預測`
              }
            },
            scales: {
              y: {
                title: {
                  display: true,
                  text: '價格 (NT$)'
                }
              }
            }
          }
        })

        // 更新預測指標
        if (data.metrics) {
          predictionTrend.value = data.metrics.trend || 3.2
          predictionAccuracy.value = data.metrics.accuracy || 82.5
          predictionConfidence.value = data.metrics.confidence || 95
        }
      } catch (error) {
        console.error('載入預測圖表失敗:', error)
        initPredictionChartWithMockData()
      }
    }

    // 使用模擬數據初始化預測圖表
    const initPredictionChartWithMockData = () => {
      if (!predictionChart.value) return

      const ctx = predictionChart.value.getContext('2d')
      
      if (predictionChartInstance) {
        predictionChartInstance.destroy()
      }

      predictionChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
          labels: generateFutureDateLabels(7),
          datasets: [
            {
              label: '歷史價格',
              data: [580, 585, 582, 588, 590, 592, 595],
              borderColor: 'rgb(201, 203, 207)',
              backgroundColor: 'rgba(201, 203, 207, 0.1)',
              tension: 0.3,
              fill: false
            },
            {
              label: '預測價格',
              data: [null, null, null, null, null, 595, 598, 602, 605, 608, 610, 612, 615],
              borderColor: 'rgb(75, 192, 192)',
              backgroundColor: 'rgba(75, 192, 192, 0.1)',
              tension: 0.3,
              fill: true,
              pointRadius: 5
            },
            {
              label: '信賴上界',
              data: [null, null, null, null, null, 605, 608, 612, 615, 618, 620, 622, 625],
              borderColor: 'rgba(255, 99, 132, 0.5)',
              borderDash: [5, 5],
              fill: false,
              pointRadius: 0
            },
            {
              label: '信賴下界',
              data: [null, null, null, null, null, 585, 588, 592, 595, 598, 600, 602, 605],
              borderColor: 'rgba(255, 99, 132, 0.5)',
              borderDash: [5, 5],
              fill: '-1',
              backgroundColor: 'rgba(255, 99, 132, 0.1)',
              pointRadius: 0
            }
          ]
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
              position: 'top'
            },
            title: {
              display: true,
              text: `${selectedPredictionModel.value.toUpperCase()} 模型 7 日預測`
            }
          },
          scales: {
            y: {
              title: {
                display: true,
                text: '價格 (NT$)'
              }
            }
          }
        }
      })
    }

    // 輔助函數:生成日期標籤
    const generateDateLabels = (days) => {
      const labels = []
      const today = new Date()
      for (let i = days - 1; i >= 0; i--) {
        const date = new Date(today)
        date.setDate(date.getDate() - i)
        labels.push(date.toLocaleDateString('zh-TW', { month: '2-digit', day: '2-digit' }))
      }
      return labels
    }

    // 輔助函數:生成未來日期標籤
    const generateFutureDateLabels = (days) => {
      const labels = []
      const today = new Date()
      // 先加入歷史 5 天
      for (let i = 4; i >= 0; i--) {
        const date = new Date(today)
        date.setDate(date.getDate() - i)
        labels.push(date.toLocaleDateString('zh-TW', { month: '2-digit', day: '2-digit' }))
      }
      // 再加入未來 7 天
      for (let i = 1; i <= days; i++) {
        const date = new Date(today)
        date.setDate(date.getDate() + i)
        labels.push(date.toLocaleDateString('zh-TW', { month: '2-digit', day: '2-digit' }))
      }
      return labels
    }

    // 輔助函數:生成模擬價格
    const generateMockPrices = (count, min, max) => {
      const prices = []
      let current = (min + max) / 2
      for (let i = 0; i < count; i++) {
        current += (Math.random() - 0.5) * (max - min) * 0.05
        current = Math.max(min, Math.min(max, current))
        prices.push(parseFloat(current.toFixed(2)))
      }
      return prices
    }

    // 刷新圖表
    const refreshStockChart = () => {
      initStockPriceChart()
    }

    const refreshVolatilityChart = () => {
      initVolatilityChart()
    }

    const refreshPredictionChart = () => {
      initPredictionChart()
    }

    // 其他方法
    const viewDetail = (item) => {
      console.log('查看詳情:', item)
    }

    const closePosition = (item) => {
      console.log('平倉:', item)
    }

    // 生命週期
    onMounted(() => {
      // 延遲初始化圖表以確保 DOM 已渲染
      setTimeout(() => {
        initStockPriceChart()
        initVolatilityChart()
        initPredictionChart()
      }, 100)
    })

    onUnmounted(() => {
      if (stockChartInstance) stockChartInstance.destroy()
      if (volatilityChartInstance) volatilityChartInstance.destroy()
      if (predictionChartInstance) predictionChartInstance.destroy()
    })

    return {
      totalValue,
      totalValueChange,
      todayPL,
      todayPLPercent,
      openPositions,
      pendingOrders,
      riskScore,
      search,
      stockPriceChart,
      volatilityChart,
      predictionChart,
      avgHV,
      avgIV,
      selectedPredictionModel,
      predictionTrend,
      predictionAccuracy,
      predictionConfidence,
      topStocks,
      positionHeaders,
      positions,
      getRiskColor,
      getRiskLevel,
      refreshStockChart,
      refreshVolatilityChart,
      refreshPredictionChart,
      viewDetail,
      closePosition
    }
  }
}
</script>

<style scoped>
.dashboard-page {
  padding: 16px;
}
</style>