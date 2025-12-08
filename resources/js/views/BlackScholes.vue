<template>
  <div class="black-scholes-page">
    <v-container fluid>
      <v-row>
        <v-col cols="12">
          <h2 class="text-h4 font-weight-bold mb-4">
            <v-icon color="primary" class="mr-2">mdi-calculator-variant</v-icon>
            Black-Scholes 選擇權定價模型
          </h2>
        </v-col>
      </v-row>

      <v-row>
        <v-col cols="12" md="4">
          <v-card elevation="3">
            <v-tabs v-model="mode" color="primary" grow>
              <v-tab value="price">
                <v-icon start>mdi-function</v-icon>計算價格
              </v-tab>
              <v-tab value="iv">
                <v-icon start>mdi-eye-outline</v-icon>反推 IV
              </v-tab>
            </v-tabs>

            <v-card-text class="pt-4">
              <v-form ref="form">
                <v-btn-toggle
                  v-model="params.optionType"
                  color="primary"
                  mandatory
                  divided
                  class="mb-4 w-100"
                >
                  <v-btn value="call" width="50%">Call 買權</v-btn>
                  <v-btn value="put" width="50%">Put 賣權</v-btn>
                </v-btn-toggle>

                <v-text-field
                  v-model.number="params.spotPrice"
                  label="標的現價 (S)"
                  type="number"
                  step="1"
                  prefix="$"
                  variant="outlined"
                  density="comfortable"
                ></v-text-field>

                <v-text-field
                  v-model.number="params.strikePrice"
                  label="履約價 (K)"
                  type="number"
                  step="1"
                  prefix="$"
                  variant="outlined"
                  density="comfortable"
                ></v-text-field>

                <v-row dense>
                  <v-col cols="7">
                    <v-text-field
                      v-model="expiryDate"
                      label="到期日"
                      type="date"
                      variant="outlined"
                      density="comfortable"
                      @update:model-value="updateTimeFromDate"
                    ></v-text-field>
                  </v-col>
                  <v-col cols="5">
                    <v-text-field
                      :model-value="daysRemaining"
                      label="剩餘天數"
                      readonly
                      suffix="天"
                      variant="filled"
                      density="comfortable"
                    ></v-text-field>
                  </v-col>
                </v-row>
                <div class="text-caption text-grey mb-4 text-right">
                  年化時間 (T) = {{ params.timeToExpiry.toFixed(4) }} 年
                </div>

                <v-text-field
                  v-model.number="params.riskFreeRate"
                  label="無風險利率 (r)"
                  type="number"
                  step="0.1"
                  suffix="%"
                  variant="outlined"
                  density="comfortable"
                  hint="例如: 1.5%"
                  persistent-hint
                ></v-text-field>

                <v-divider class="my-4"></v-divider>

                <div v-if="mode === 'price'">
                  <div class="text-subtitle-2 text-primary mb-1">波動率設定 (σ)</div>
                  <v-slider
                    v-model="params.volatility"
                    min="1"
                    max="100"
                    step="0.5"
                    color="primary"
                    hide-details
                    thumb-label
                  ></v-slider>
                  <v-text-field
                    v-model.number="params.volatility"
                    label="波動率"
                    type="number"
                    suffix="%"
                    variant="outlined"
                    density="comfortable"
                  ></v-text-field>
                </div>

                <div v-else>
                  <v-alert type="info" variant="tonal" density="compact" class="mb-3">
                    輸入當前市場價格，反推市場預期的隱含波動率。
                  </v-alert>
                  <v-text-field
                    v-model.number="params.marketPrice"
                    label="選擇權市場成交價"
                    type="number"
                    prefix="$"
                    variant="outlined"
                    density="comfortable"
                    color="secondary"
                  ></v-text-field>
                </div>

                <v-btn
                  color="primary"
                  size="large"
                  block
                  class="mt-4"
                  :loading="loading"
                  @click="calculate"
                >
                  <v-icon left>mdi-calculator</v-icon>
                  {{ mode === 'price' ? '計算理論價格 & Greeks' : '反推隱含波動率 (IV)' }}
                </v-btn>
              </v-form>
            </v-card-text>
          </v-card>
        </v-col>

        <v-col cols="12" md="8">
          <v-card elevation="2" class="mb-4" :color="resultCardColor">
            <v-card-text class="text-center py-6">
              <div v-if="!result">
                <v-icon size="64" color="grey-lighten-2">mdi-chart-line</v-icon>
                <div class="text-h6 text-grey mt-2">請輸入參數並點擊計算</div>
              </div>

              <div v-else>
                <div class="text-subtitle-1 mb-2 opacity-75">
                  {{ mode === 'price' ? '理論價格 (Theoretical Price)' : '隱含波動率 (Implied Volatility)' }}
                </div>
                <div class="text-h2 font-weight-bold">
                  <span v-if="mode === 'price'">${{ result.theoretical_price }}</span>
                  <span v-else>{{ result.implied_volatility_percentage }}</span>
                </div>

                <v-chip
                  v-if="result.moneyness"
                  class="mt-3"
                  :color="getMoneynessColor(result.moneyness)"
                  variant="flat"
                >
                  {{ result.moneyness }}
                </v-chip>
              </div>
            </v-card-text>
          </v-card>

          <v-row v-if="mode === 'price' && result?.greeks">
            <v-col cols="6" md="3" v-for="(value, name) in result.greeks" :key="name">
              <v-card variant="outlined">
                <v-card-text class="text-center pa-2">
                  <div class="text-caption text-uppercase text-grey font-weight-bold">{{ name }}</div>
                  <div class="text-h6" :class="getValueColor(value)">{{ Number(value).toFixed(4) }}</div>
                </v-card-text>
              </v-card>
            </v-col>
          </v-row>

          <v-card elevation="2" class="mt-4">
            <v-card-title>
              <v-icon left size="small">mdi-chart-bell-curve-cumulative</v-icon>
              價格敏感度分析 (Price Sensitivity)
            </v-card-title>
            <v-card-text>
              <div style="height: 300px; position: relative;">
                <canvas ref="sensitivityChart"></canvas>
              </div>
              <div class="text-caption text-center text-grey mt-2">
                X軸：標的股價變動 | Y軸：選擇權理論價格
              </div>
            </v-card-text>
          </v-card>
        </v-col>
      </v-row>
    </v-container>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, nextTick } from 'vue'
import axios from 'axios'
import Chart from 'chart.js/auto'

// 狀態
const mode = ref('price') // 'price' | 'iv'
const loading = ref(false)
const result = ref(null)
const chartInstance = ref(null)
const sensitivityChart = ref(null)

// 輸入參數
const params = reactive({
  optionType: 'call',
  spotPrice: 16500,
  strikePrice: 16600,
  timeToExpiry: 0.0822, // 預設約30天
  riskFreeRate: 1.5,    // %
  volatility: 20,       // %
  marketPrice: 150      // 用於 IV 計算
})

// 日期處理
const expiryDate = ref('')

// 計算剩餘天數
const daysRemaining = computed(() => {
  if (!expiryDate.value) return 0
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  const target = new Date(expiryDate.value)
  const diffTime = target - today
  const days = Math.ceil(diffTime / (1000 * 60 * 60 * 24))
  return days > 0 ? days : 0
})

// 當日期變動時，更新 T (年)
const updateTimeFromDate = () => {
  if (daysRemaining.value > 0) {
    params.timeToExpiry = daysRemaining.value / 365.0
  } else {
    params.timeToExpiry = 0
  }
}

// 初始化日期 (預設 30 天後)
onMounted(() => {
  const date = new Date()
  date.setDate(date.getDate() + 30)
  expiryDate.value = date.toISOString().split('T')[0]
  updateTimeFromDate()
})

// 顏色邏輯
const resultCardColor = computed(() => {
  if (!result.value) return 'surface'
  return mode.value === 'price' ? 'blue-lighten-5' : 'green-lighten-5'
})

const getMoneynessColor = (val) => {
  if (val === 'ITM') return 'success'
  if (val === 'ATM') return 'warning'
  return 'error' // OTM
}

const getValueColor = (val) => {
  if (val > 0) return 'text-success'
  if (val < 0) return 'text-error'
  return ''
}

// ✅ 核心計算 API - 修正 URL 路徑
const calculate = async () => {
  loading.value = true
  result.value = null

  try {
    let url = ''
    let payload = {
      spot_price: params.spotPrice,
      strike_price: params.strikePrice,
      time_to_expiry: params.timeToExpiry,
      risk_free_rate: params.riskFreeRate / 100, // 轉小數
      option_type: params.optionType,
    }

    if (mode.value === 'price') {
      // ✅ 修正: 移除 /api 前綴，因為 axios baseURL 已經是 /api
      url = 'black-scholes/calculate'
      payload.volatility = params.volatility / 100
    } else {
      // ✅ 修正: 移除 /api 前綴
      url = 'black-scholes/implied-volatility'
      payload.market_price = params.marketPrice
    }

    const response = await axios.post(url, payload)

    if (response.data.success) {
      result.value = response.data.data

      // 如果是價格模式，更新圖表
      if (mode.value === 'price') {
        await nextTick()
        updateChart()
      }
    }
  } catch (error) {
    console.error('計算錯誤:', error)
    alert('計算失敗，請檢查參數或網路連線')
  } finally {
    loading.value = false
  }
}

// 繪製敏感度圖表 (Spot Price vs Option Price)
const updateChart = () => {
  if (!sensitivityChart.value || !result.value) return

  // 銷毀舊圖表
  if (chartInstance.value) {
    chartInstance.value.destroy()
  }

  // 產生模擬數據 (股價 +/- 10%)
  const currentSpot = params.spotPrice
  const labels = []
  const dataPoints = []
  const delta = result.value.greeks.delta
  const gamma = result.value.greeks.gamma
  const currentPrice = result.value.theoretical_price

  for (let i = -10; i <= 10; i += 2) {
    const percentChange = i / 100
    const spot = currentSpot * (1 + percentChange)
    labels.push(spot.toFixed(0))

    // 使用 Delta-Gamma 近似法繪製曲線
    // P_new ≈ P + Delta * dS + 0.5 * Gamma * (dS)^2
    const dS = spot - currentSpot
    const estPrice = currentPrice + (delta * dS) + (0.5 * gamma * Math.pow(dS, 2))

    dataPoints.push(Math.max(0, estPrice)) // 價格不能為負
  }

  const ctx = sensitivityChart.value.getContext('2d')

  chartInstance.value = new Chart(ctx, {
    type: 'line',
    data: {
      labels: labels,
      datasets: [{
        label: '選擇權理論價格預測',
        data: dataPoints,
        borderColor: params.optionType === 'call' ? '#D32F2F' : '#388E3C', // Call 紅 Put 綠
        backgroundColor: params.optionType === 'call' ? 'rgba(211, 47, 47, 0.1)' : 'rgba(56, 142, 60, 0.1)',
        tension: 0.4,
        fill: true,
        pointRadius: 4,
        pointHoverRadius: 6
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: true },
        tooltip: {
          mode: 'index',
          intersect: false,
          callbacks: {
            label: (context) => `價格: $${context.parsed.y.toFixed(2)}`
          }
        }
      },
      scales: {
        y: {
          beginAtZero: false,
          title: { display: true, text: '權利金 ($)' }
        },
        x: {
          title: { display: true, text: '標的股價 ($)' }
        }
      }
    }
  })
}
</script>

<style scoped>
.black-scholes-page {
  padding: 16px;
  background-color: #f5f5f5;
  min-height: 100vh;
}
.w-100 {
  width: 100%;
}
</style>
