<template>
  <v-container fluid>
    <!-- 頁面標題 -->
    <v-row>
      <v-col cols="12">
        <h1 class="text-h4 font-weight-bold mb-2">
          <v-icon left>mdi-chart-bell-curve</v-icon>
          波動率分析
        </h1>
        <p class="text-subtitle-1 text-grey">
          歷史波動率 (HV)、隱含波動率 (IV) 與波動率錐分析
        </p>
      </v-col>
    </v-row>

    <v-divider class="my-4"></v-divider>

    <!-- 功能標籤頁 -->
    <v-tabs v-model="currentTab" color="primary" align-tabs="start">
      <v-tab value="historical">
        <v-icon left>mdi-chart-line</v-icon>
        歷史波動率
      </v-tab>
      <v-tab value="implied">
        <v-icon left>mdi-eye</v-icon>
        隱含波動率
      </v-tab>
      <v-tab value="cone">
        <v-icon left>mdi-cone</v-icon>
        波動率錐
      </v-tab>
      <v-tab value="surface">
        <v-icon left>mdi-cube-outline</v-icon>
        波動率曲面
      </v-tab>
    </v-tabs>

    <v-window v-model="currentTab" class="mt-4">
      <!-- 歷史波動率分析 -->
      <v-window-item value="historical">
        <v-row>
          <v-col cols="12" md="4">
            <v-card elevation="2">
              <v-card-title>查詢條件</v-card-title>
              <v-card-text>
                <!-- 股票選擇 -->
                <v-autocomplete
                  v-model="historicalQuery.stockId"
                  :items="stocks"
                  item-title="name"
                  item-value="id"
                  label="選擇股票"
                  prepend-icon="mdi-chart-candlestick"
                  variant="outlined"
                  density="comfortable"
                >
                  <template v-slot:item="{ props, item }">
                    <v-list-item
                      v-bind="props"
                      :title="`${item.raw.symbol} - ${item.raw.name}`"
                    ></v-list-item>
                  </template>
                </v-autocomplete>

                <!-- 計算期間 -->
                <v-select
                  v-model="historicalQuery.period"
                  :items="periodOptions"
                  label="計算期間"
                  prepend-icon="mdi-calendar-range"
                  variant="outlined"
                  density="comfortable"
                ></v-select>

                <!-- 計算按鈕 -->
                <v-btn
                  @click="calculateHistoricalVolatility"
                  :loading="loading.historical"
                  color="primary"
                  block
                  size="large"
                >
                  <v-icon left>mdi-calculator</v-icon>
                  計算波動率
                </v-btn>
              </v-card-text>
            </v-card>
          </v-col>

          <v-col cols="12" md="8">
            <v-card elevation="2" v-if="historicalResult">
              <v-card-title class="bg-primary text-white">
                歷史波動率分析結果
              </v-card-title>
              <v-card-text class="pt-6">
                <v-row>
                  <v-col cols="6">
                    <v-card variant="outlined" color="blue-lighten-5">
                      <v-card-text class="text-center">
                        <div class="text-h4 font-weight-bold text-primary">
                          {{ historicalResult.historical_volatility_percentage }}
                        </div>
                        <div class="text-subtitle-2 text-grey mt-2">
                          歷史波動率 (HV)
                        </div>
                        <div class="text-caption mt-2">
                          {{ historicalResult.period_days }} 日期間
                        </div>
                      </v-card-text>
                    </v-card>
                  </v-col>

                  <v-col cols="6" v-if="historicalResult.realized_volatility">
                    <v-card variant="outlined" color="green-lighten-5">
                      <v-card-text class="text-center">
                        <div class="text-h4 font-weight-bold text-success">
                          {{ historicalResult.realized_volatility_percentage }}
                        </div>
                        <div class="text-subtitle-2 text-grey mt-2">
                          實現波動率 (RV)
                        </div>
                        <div class="text-caption mt-2">
                          Parkinson 估計法
                        </div>
                      </v-card-text>
                    </v-card>
                  </v-col>
                </v-row>

                <!-- 股票資訊 -->
                <v-row class="mt-4">
                  <v-col cols="12">
                    <v-alert type="info" variant="tonal">
                      <div class="font-weight-bold">{{ historicalResult.stock.symbol }}</div>
                      <div>{{ historicalResult.stock.name }}</div>
                      <div class="text-caption mt-1">
                        計算日期: {{ historicalResult.end_date }}
                      </div>
                    </v-alert>
                  </v-col>
                </v-row>

                <!-- 波動率圖表區域 -->
                <v-row class="mt-4">
                  <v-col cols="12">
                    <div class="pa-4 bg-grey-lighten-4 rounded text-center">
                      <v-icon size="64" color="grey">mdi-chart-line-variant</v-icon>
                      <div class="text-h6 text-grey mt-2">波動率趨勢圖</div>
                      <div class="text-caption text-grey">整合 Chart.js 顯示波動率歷史趨勢</div>
                    </div>
                  </v-col>
                </v-row>
              </v-card-text>
            </v-card>

            <v-alert
              v-if="error.historical"
              type="error"
              variant="tonal"
              class="mt-4"
              closable
              @click:close="error.historical = null"
            >
              {{ error.historical }}
            </v-alert>
          </v-col>
        </v-row>
      </v-window-item>

      <!-- 隱含波動率分析 -->
      <v-window-item value="implied">
        <v-row>
          <v-col cols="12" md="4">
            <v-card elevation="2">
              <v-card-title>查詢條件</v-card-title>
              <v-card-text>
                <!-- 選擇權選擇 -->
                <v-autocomplete
                  v-model="impliedQuery.optionId"
                  :items="options"
                  item-title="option_code"
                  item-value="id"
                  label="選擇選擇權"
                  prepend-icon="mdi-finance"
                  variant="outlined"
                  density="comfortable"
                >
                  <template v-slot:item="{ props, item }">
                    <v-list-item
                      v-bind="props"
                      :title="item.raw.option_code"
                      :subtitle="`${item.raw.option_type.toUpperCase()} ${item.raw.strike_price}`"
                    ></v-list-item>
                  </template>
                </v-autocomplete>

                <!-- 計算按鈕 -->
                <v-btn
                  @click="calculateImpliedVolatility"
                  :loading="loading.implied"
                  color="primary"
                  block
                  size="large"
                >
                  <v-icon left>mdi-calculator</v-icon>
                  計算隱含波動率
                </v-btn>
              </v-card-text>
            </v-card>
          </v-col>

          <v-col cols="12" md="8">
            <v-card elevation="2" v-if="impliedResult">
              <v-card-title class="bg-success text-white">
                隱含波動率計算結果
              </v-card-title>
              <v-card-text class="pt-6">
                <v-row>
                  <v-col cols="12">
                    <div class="text-center pa-8 bg-grey-lighten-4 rounded">
                      <div class="text-h2 font-weight-bold text-success">
                        {{ impliedResult.implied_volatility_percentage }}
                      </div>
                      <div class="text-h6 text-grey mt-2">
                        隱含波動率 (IV)
                      </div>
                      <div class="text-caption text-grey mt-2">
                        交易日期: {{ impliedResult.trade_date }}
                      </div>
                    </div>
                  </v-col>
                </v-row>
              </v-card-text>
            </v-card>

            <v-alert
              v-if="error.implied"
              type="error"
              variant="tonal"
              class="mt-4"
              closable
              @click:close="error.implied = null"
            >
              {{ error.implied }}
            </v-alert>
          </v-col>
        </v-row>
      </v-window-item>

      <!-- 波動率錐 -->
      <v-window-item value="cone">
        <v-row>
          <v-col cols="12" md="4">
            <v-card elevation="2">
              <v-card-title>查詢條件</v-card-title>
              <v-card-text>
                <!-- 股票選擇 -->
                <v-autocomplete
                  v-model="coneQuery.stockId"
                  :items="stocks"
                  item-title="name"
                  item-value="id"
                  label="選擇股票"
                  prepend-icon="mdi-chart-candlestick"
                  variant="outlined"
                  density="comfortable"
                >
                  <template v-slot:item="{ props, item }">
                    <v-list-item
                      v-bind="props"
                      :title="`${item.raw.symbol} - ${item.raw.name}`"
                    ></v-list-item>
                  </template>
                </v-autocomplete>

                <!-- 回測天數 -->
                <v-text-field
                  v-model.number="coneQuery.lookbackDays"
                  label="回測天數"
                  type="number"
                  prepend-icon="mdi-calendar"
                  variant="outlined"
                  density="comfortable"
                  hint="預設 252 個交易日 (約一年)"
                  persistent-hint
                ></v-text-field>

                <!-- 計算按鈕 -->
                <v-btn
                  @click="calculateVolatilityCone"
                  :loading="loading.cone"
                  color="primary"
                  block
                  size="large"
                  class="mt-4"
                >
                  <v-icon left>mdi-calculator</v-icon>
                  計算波動率錐
                </v-btn>
              </v-card-text>
            </v-card>
          </v-col>

          <v-col cols="12" md="8">
            <v-card elevation="2" v-if="coneResult">
              <v-card-title class="bg-warning text-white">
                波動率錐 (Volatility Cone)
              </v-card-title>
              <v-card-text class="pt-6">
                <v-simple-table>
                  <template v-slot:default>
                    <thead>
                      <tr>
                        <th>期間</th>
                        <th>最小值</th>
                        <th>10%分位</th>
                        <th>25%分位</th>
                        <th>中位數</th>
                        <th>75%分位</th>
                        <th>90%分位</th>
                        <th>最大值</th>
                        <th>當前</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="(data, period) in coneResult" :key="period">
                        <td class="font-weight-bold">{{ period }}天</td>
                        <td>{{ (data.min * 100).toFixed(2) }}%</td>
                        <td>{{ (data.percentile_10 * 100).toFixed(2) }}%</td>
                        <td>{{ (data.percentile_25 * 100).toFixed(2) }}%</td>
                        <td>{{ (data.median * 100).toFixed(2) }}%</td>
                        <td>{{ (data.percentile_75 * 100).toFixed(2) }}%</td>
                        <td>{{ (data.percentile_90 * 100).toFixed(2) }}%</td>
                        <td>{{ (data.max * 100).toFixed(2) }}%</td>
                        <td class="font-weight-bold text-primary">
                          {{ (data.current * 100).toFixed(2) }}%
                        </td>
                      </tr>
                    </tbody>
                  </template>
                </v-simple-table>

                <!-- 圖表區域 -->
                <v-row class="mt-6">
                  <v-col cols="12">
                    <div class="pa-4 bg-grey-lighten-4 rounded text-center">
                      <v-icon size="64" color="grey">mdi-chart-box-outline</v-icon>
                      <div class="text-h6 text-grey mt-2">波動率錐視覺化</div>
                      <div class="text-caption text-grey">使用 Chart.js 繪製波動率錐圖表</div>
                    </div>
                  </v-col>
                </v-row>
              </v-card-text>
            </v-card>

            <v-alert
              v-if="error.cone"
              type="error"
              variant="tonal"
              class="mt-4"
              closable
              @click:close="error.cone = null"
            >
              {{ error.cone }}
            </v-alert>
          </v-col>
        </v-row>
      </v-window-item>

      <!-- 波動率曲面 -->
      <v-window-item value="surface">
        <v-row>
          <v-col cols="12" md="4">
            <v-card elevation="2">
              <v-card-title>查詢條件</v-card-title>
              <v-card-text>
                <!-- 標的選擇 -->
                <v-select
                  v-model="surfaceQuery.underlying"
                  :items="underlyings"
                  label="選擇標的"
                  prepend-icon="mdi-chart-line"
                  variant="outlined"
                  density="comfortable"
                ></v-select>

                <!-- 計算按鈕 -->
                <v-btn
                  @click="calculateVolatilitySurface"
                  :loading="loading.surface"
                  color="primary"
                  block
                  size="large"
                >
                  <v-icon left>mdi-calculator</v-icon>
                  載入波動率曲面
                </v-btn>
              </v-card-text>
            </v-card>
          </v-col>

          <v-col cols="12" md="8">
            <v-card elevation="2" v-if="surfaceResult">
              <v-card-title class="bg-info text-white">
                波動率曲面 (Volatility Surface)
              </v-card-title>
              <v-card-text class="pt-6">
                <v-alert type="info" variant="tonal" class="mb-4">
                  <div>標的: {{ surfaceResult.underlying }}</div>
                  <div>標的價格: {{ surfaceResult.spot_price }}</div>
                  <div>資料點數: {{ surfaceResult.total_points }}</div>
                  <div>計算日期: {{ surfaceResult.date }}</div>
                </v-alert>

                <!-- 3D 圖表區域 -->
                <div class="pa-8 bg-grey-lighten-4 rounded text-center">
                  <v-icon size="80" color="grey">mdi-cube-outline</v-icon>
                  <div class="text-h5 text-grey mt-3">波動率曲面 3D 視覺化</div>
                  <div class="text-body-2 text-grey mt-2">
                    整合 Three.js 或 Plotly 顯示波動率曲面
                  </div>
                  <div class="text-caption text-grey mt-2">
                    X 軸: 履約價格 | Y 軸: 到期天數 | Z 軸: 隱含波動率
                  </div>
                </div>
              </v-card-text>
            </v-card>

            <v-alert
              v-if="error.surface"
              type="error"
              variant="tonal"
              class="mt-4"
              closable
              @click:close="error.surface = null"
            >
              {{ error.surface }}
            </v-alert>
          </v-col>
        </v-row>
      </v-window-item>
    </v-window>
  </v-container>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'

// 標籤頁狀態
const currentTab = ref('historical')

// 載入狀態
const loading = reactive({
  historical: false,
  implied: false,
  cone: false,
  surface: false
})

// 錯誤狀態
const error = reactive({
  historical: null,
  implied: null,
  cone: null,
  surface: null
})

// 資料
const stocks = ref([])
const options = ref([])
const underlyings = ref(['TXO', '^TWII'])

// 查詢條件
const historicalQuery = reactive({
  stockId: null,
  period: 30
})

const impliedQuery = reactive({
  optionId: null
})

const coneQuery = reactive({
  stockId: null,
  lookbackDays: 252
})

const surfaceQuery = reactive({
  underlying: 'TXO'
})

// 結果
const historicalResult = ref(null)
const impliedResult = ref(null)
const coneResult = ref(null)
const surfaceResult = ref(null)

// 期間選項
const periodOptions = [
  { title: '10天', value: 10 },
  { title: '20天', value: 20 },
  { title: '30天', value: 30 },
  { title: '60天', value: 60 },
  { title: '90天', value: 90 },
  { title: '120天', value: 120 }
]

// 計算歷史波動率
const calculateHistoricalVolatility = async () => {
  if (!historicalQuery.stockId) {
    error.historical = '請選擇股票'
    return
  }

  loading.historical = true
  error.historical = null
  historicalResult.value = null

  try {
    const response = await axios.get(
      `/api/volatility/historical/${historicalQuery.stockId}`,
      {
        params: {
          period: historicalQuery.period
        }
      }
    )

    if (response.data.success) {
      historicalResult.value = response.data.data
    } else {
      error.historical = response.data.message || '計算失敗'
    }
  } catch (err) {
    error.historical = err.response?.data?.message || '網路錯誤，請稍後再試'
    console.error('計算錯誤:', err)
  } finally {
    loading.historical = false
  }
}

// 計算隱含波動率
const calculateImpliedVolatility = async () => {
  if (!impliedQuery.optionId) {
    error.implied = '請選擇選擇權'
    return
  }

  loading.implied = true
  error.implied = null
  impliedResult.value = null

  try {
    const response = await axios.get(
      `/api/volatility/implied/${impliedQuery.optionId}`
    )

    if (response.data.success) {
      impliedResult.value = response.data.data
    } else {
      error.implied = response.data.message || '計算失敗'
    }
  } catch (err) {
    error.implied = err.response?.data?.message || '網路錯誤，請稍後再試'
    console.error('計算錯誤:', err)
  } finally {
    loading.implied = false
  }
}

// 計算波動率錐
const calculateVolatilityCone = async () => {
  if (!coneQuery.stockId) {
    error.cone = '請選擇股票'
    return
  }

  loading.cone = true
  error.cone = null
  coneResult.value = null

  try {
    const response = await axios.get(
      `/api/volatility/cone/${coneQuery.stockId}`,
      {
        params: {
          lookback_days: coneQuery.lookbackDays
        }
      }
    )

    if (response.data.success) {
      coneResult.value = response.data.data.cone
    } else {
      error.cone = response.data.message || '計算失敗'
    }
  } catch (err) {
    error.cone = err.response?.data?.message || '網路錯誤，請稍後再試'
    console.error('計算錯誤:', err)
  } finally {
    loading.cone = false
  }
}

// 計算波動率曲面
const calculateVolatilitySurface = async () => {
  if (!surfaceQuery.underlying) {
    error.surface = '請選擇標的'
    return
  }

  loading.surface = true
  error.surface = null
  surfaceResult.value = null

  try {
    const response = await axios.get(
      `/api/volatility/surface/${surfaceQuery.underlying}`
    )

    if (response.data.success) {
      surfaceResult.value = response.data.data
    } else {
      error.surface = response.data.message || '載入失敗'
    }
  } catch (err) {
    error.surface = err.response?.data?.message || '網路錯誤，請稍後再試'
    console.error('載入錯誤:', err)
  } finally {
    loading.surface = false
  }
}

// 載入股票清單
const loadStocks = async () => {
  try {
    const response = await axios.get('/api/stocks', {
      params: { per_page: 100 }
    })
    if (response.data.success) {
      stocks.value = response.data.data.data
    }
  } catch (err) {
    console.error('載入股票清單失敗:', err)
  }
}

// 載入選擇權清單
const loadOptions = async () => {
  try {
    const response = await axios.get('/api/options', {
      params: { per_page: 100 }
    })
    if (response.data.success) {
      options.value = response.data.data.data
    }
  } catch (err) {
    console.error('載入選擇權清單失敗:', err)
  }
}

onMounted(() => {
  loadStocks()
  loadOptions()
})
</script>

<style scoped>
.bg-grey-lighten-4 {
  background-color: #f5f5f5;
}
</style>