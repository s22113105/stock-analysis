<template>
  <v-container class="prediction-page">
    <v-row>
      <v-col cols="12">
        <v-card elevation="3">
          <v-card-title class="text-h4 font-weight-bold primary--text">
            預測分析系統
          </v-card-title>

          <v-card-subtitle>
            使用 LSTM、ARIMA、GARCH 模型預測股價走勢
          </v-card-subtitle>

          <v-card-text class="pa-6">
            <!-- 步驟 1: 選擇預測目標 -->
            <v-row align="center" class="mb-4">
              <v-col cols="12" md="12">
                <div class="text-h6 mb-3">步驟 1：選擇預測目標</div>
              </v-col>

              <v-col cols="12" md="6">
                <v-radio-group
                  v-model="targetType"
                  row
                  hide-details
                  class="mt-0"
                >
                  <v-radio
                    label="TXO 市場指數"
                    value="market"
                    color="primary"
                  ></v-radio>
                  <v-radio
                    label="個股"
                    value="stock"
                    color="primary"
                  ></v-radio>
                </v-radio-group>
              </v-col>

              <v-col cols="12" md="6" v-if="targetType === 'stock'">
                <v-autocomplete
                  v-model="selectedStock"
                  :items="stocksList"
                  :loading="loadingStocks"
                  item-title="display"
                  item-value="value"
                  label="選擇股票"
                  placeholder="輸入股票代碼或名稱"
                  outlined
                  dense
                  clearable
                  no-data-text="目前沒有可用的股票資料"
                  @update:search="searchStocks"
                >
                  <template v-slot:item="{ props, item }">
                    <v-list-item v-bind="props">
                      <v-list-item-title>
                        {{ item.raw.symbol }} - {{ item.raw.name }}
                      </v-list-item-title>
                    </v-list-item>
                  </template>
                </v-autocomplete>
              </v-col>
            </v-row>

            <v-divider class="my-4"></v-divider>

            <!-- 步驟 2: 模型設定 -->
            <v-row align="center" class="mb-4">
              <v-col cols="12">
                <div class="text-h6 mb-3">步驟 2：模型設定</div>
              </v-col>

              <v-col cols="12" md="4">
                <v-select
                  v-model="selectedModel"
                  :items="models"
                  item-title="text"
                  item-value="value"
                  label="預測模型"
                  outlined
                  dense
                >
                  <template v-slot:item="{ props, item }">
                    <v-list-item v-bind="props">
                      <v-list-item-subtitle>
                        {{ item.raw.description }}
                      </v-list-item-subtitle>
                    </v-list-item>
                  </template>
                </v-select>
              </v-col>

              <v-col cols="12" md="3">
                <v-text-field
                  v-model.number="predictionDays"
                  label="預測天數"
                  type="number"
                  min="1"
                  max="30"
                  outlined
                  dense
                  suffix="天"
                ></v-text-field>
              </v-col>

              <v-col cols="12" md="3">
                <v-text-field
                  v-model.number="trainingPeriod"
                  label="訓練期間"
                  type="number"
                  min="60"
                  max="365"
                  outlined
                  dense
                  suffix="天"
                ></v-text-field>
              </v-col>

              <v-col cols="12" md="2">
                <v-btn
                  color="primary"
                  @click="showParametersDialog = true"
                  outlined
                  block
                >
                  <v-icon>mdi-cog</v-icon>
                  進階
                </v-btn>
              </v-col>
            </v-row>

            <v-divider class="my-4"></v-divider>

            <!-- 步驟 3: 執行預測 -->
            <v-row>
              <v-col cols="12">
                <div class="text-h6 mb-3">步驟 3：執行預測</div>
              </v-col>

              <v-col cols="12">
                <v-btn
                  color="primary"
                  @click="runPrediction"
                  :loading="loading"
                  :disabled="!canPredict"
                  x-large
                  block
                  elevation="3"
                >
                  <v-icon left>mdi-robot</v-icon>
                  執行 {{ selectedModel.toUpperCase() }} 預測
                </v-btn>
              </v-col>
            </v-row>

            <!-- 載入中提示 - 修正:只在執行預測時顯示 -->
            <v-alert
              v-if="loading"
              type="info"
              class="mt-6"
              prominent
            >
              <v-row align="center">
                <v-col class="grow">
                  正在執行 {{ selectedModel.toUpperCase() }} 模型訓練與預測，請稍候...
                  <div class="text-caption mt-2">預計需要 10-30 秒</div>
                </v-col>
                <v-col class="shrink">
                  <v-progress-circular
                    indeterminate
                    color="white"
                    size="32"
                  ></v-progress-circular>
                </v-col>
              </v-row>
            </v-alert>

            <!-- 錯誤訊息 -->
            <v-alert
              v-if="error"
              type="error"
              dismissible
              @input="error = null"
              class="mt-6"
            >
              {{ error }}
            </v-alert>

            <!-- 預測結果 -->
            <v-expand-transition>
              <v-card v-if="predictionResult && !loading" class="mt-6" elevation="4">
                <v-card-title class="primary white--text">
                  預測結果
                </v-card-title>

                <v-card-text class="pa-6">
                  <!-- 目標資訊 -->
                  <v-row>
                    <v-col cols="12">
                      <v-chip
                        :color="targetType === 'market' ? 'success' : 'primary'"
                        label
                        large
                      >
                        {{ targetType === 'market' ? 'TXO 市場指數' : '個股' }}
                      </v-chip>
                      <span class="ml-3 text-h6">
                        {{ predictionResult.target_info?.name || predictionResult.target_info?.underlying }}
                      </span>
                    </v-col>
                  </v-row>

                  <!-- 價格預測 -->
                  <v-row class="mt-6">
                    <v-col cols="12" md="6">
                      <v-card color="grey lighten-4" flat>
                        <v-card-text class="text-center">
                          <div class="text-caption">今日收盤價</div>
                          <div class="text-h3 font-weight-bold">
                            ${{ currentPrice }}
                          </div>
                          <!-- 修正:使用 formatDate 函數 -->
                          <div class="text-caption">{{ formatDate(predictionResult.current_date) }}</div>
                        </v-card-text>
                      </v-card>
                    </v-col>

                    <v-col cols="12" md="6">
                      <v-card
                        :color="predictedChange >= 0 ? 'success' : 'error'"
                        dark
                        flat
                      >
                        <v-card-text class="text-center">
                          <div class="text-caption">明日預測價格</div>
                          <div class="text-h3 font-weight-bold">
                            ${{ predictedPrice }}
                          </div>
                          <div class="text-h6">
                            {{ predictedChange >= 0 ? '▲' : '▼' }}
                            {{ Math.abs(predictedChange).toFixed(2) }}%
                          </div>
                        </v-card-text>
                      </v-card>
                    </v-col>
                  </v-row>

                  <!-- 信賴區間 -->
                  <v-row class="mt-4">
                    <v-col cols="12">
                      <v-card outlined>
                        <v-card-text class="text-center">
                          <div class="text-subtitle-1">95% 信賴區間</div>
                          <div class="text-h5 mt-2">
                            ${{ confidenceLower }} ~ ${{ confidenceUpper }}
                          </div>
                        </v-card-text>
                      </v-card>
                    </v-col>
                  </v-row>

                  <!-- 模型指標 -->
                  <v-row class="mt-4" v-if="predictionResult.metrics">
                    <v-col cols="12">
                      <v-simple-table>
                        <tbody>
                          <tr>
                            <td>模型類型</td>
                            <td class="text-right">{{ predictionResult.model_type?.toUpperCase() }}</td>
                          </tr>
                          <tr v-if="predictionResult.metrics.final_loss">
                            <td>最終損失 (Loss)</td>
                            <td class="text-right">{{ predictionResult.metrics.final_loss.toFixed(6) }}</td>
                          </tr>
                          <tr v-if="predictionResult.metrics.final_mae">
                            <td>平均絕對誤差 (MAE)</td>
                            <td class="text-right">{{ predictionResult.metrics.final_mae.toFixed(4) }}</td>
                          </tr>
                          <tr v-if="predictionResult.metrics.epochs_trained">
                            <td>訓練輪數</td>
                            <td class="text-right">{{ predictionResult.metrics.epochs_trained }}</td>
                          </tr>
                        </tbody>
                      </v-simple-table>
                    </v-col>
                  </v-row>

                  <!-- 圖表按鈕 -->
                  <v-row class="mt-4">
                    <v-col cols="12" class="text-center">
                      <v-btn
                        @click="showChart = !showChart"
                        color="primary"
                        outlined
                      >
                        <v-icon left>mdi-chart-line</v-icon>
                        {{ showChart ? '隱藏' : '顯示' }}歷史走勢圖
                      </v-btn>
                    </v-col>
                  </v-row>

                  <!-- 圖表 -->
                  <v-expand-transition>
                    <v-row v-if="showChart" class="mt-4">
                      <v-col cols="12">
                        <canvas ref="predictionChart" height="300"></canvas>
                      </v-col>
                    </v-row>
                  </v-expand-transition>
                </v-card-text>
              </v-card>
            </v-expand-transition>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- 參數設定對話框 -->
    <v-dialog v-model="showParametersDialog" max-width="600px">
      <v-card>
        <v-card-title>
          進階參數設定
        </v-card-title>
        <v-card-text>
          <v-row>
            <v-col cols="12" v-if="selectedModel === 'lstm'">
              <v-text-field
                v-model.number="modelParameters.epochs"
                label="訓練輪數 (Epochs)"
                type="number"
                min="10"
                max="200"
                outlined
                dense
              ></v-text-field>
              <v-text-field
                v-model.number="modelParameters.units"
                label="LSTM 單元數"
                type="number"
                min="32"
                max="256"
                outlined
                dense
              ></v-text-field>
              <v-text-field
                v-model.number="modelParameters.lookback"
                label="回顧期間（天）"
                type="number"
                min="30"
                max="120"
                outlined
                dense
              ></v-text-field>
            </v-col>

            <v-col cols="12" v-if="selectedModel === 'arima'">
              <v-text-field
                v-model.number="modelParameters.p"
                label="AR 參數 (p)"
                type="number"
                min="0"
                max="5"
                outlined
                dense
              ></v-text-field>
              <v-text-field
                v-model.number="modelParameters.d"
                label="差分階數 (d)"
                type="number"
                min="0"
                max="2"
                outlined
                dense
              ></v-text-field>
              <v-text-field
                v-model.number="modelParameters.q"
                label="MA 參數 (q)"
                type="number"
                min="0"
                max="5"
                outlined
                dense
              ></v-text-field>
            </v-col>

            <v-col cols="12" v-if="selectedModel === 'garch'">
              <v-text-field
                v-model.number="modelParameters.p"
                label="GARCH 參數 (p)"
                type="number"
                min="1"
                max="3"
                outlined
                dense
              ></v-text-field>
              <v-text-field
                v-model.number="modelParameters.q"
                label="ARCH 參數 (q)"
                type="number"
                min="1"
                max="3"
                outlined
                dense
              ></v-text-field>
            </v-col>
          </v-row>
        </v-card-text>
        <v-card-actions>
          <v-spacer></v-spacer>
          <v-btn text @click="showParametersDialog = false">取消</v-btn>
          <v-btn color="primary" @click="showParametersDialog = false">確定</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </v-container>
</template>

<script>
import { ref, computed, onMounted, watch, nextTick } from 'vue'
import axios from 'axios'
import Chart from 'chart.js/auto'

export default {
  name: 'PredictionAnalysis',

  setup() {
    // 狀態管理
    const loading = ref(false)
    const loadingStocks = ref(false)
    const error = ref(null)
    const predictionResult = ref(null)
    const predictionChart = ref(null)
    const showParametersDialog = ref(false)
    const showChart = ref(false)

    // 表單資料
    const targetType = ref('market')  // 預設選擇市場
    const selectedStock = ref(null)
    const stocksList = ref([])
    const selectedModel = ref('lstm')
    const predictionDays = ref(1)  // 預設預測 1 天
    const trainingPeriod = ref(180)  // 預設使用 180 天資料
    const modelParameters = ref({
      // LSTM 參數
      epochs: 20,
      units: 64,
      lookback: 60,
      dropout: 0.2,
      // ARIMA 參數
      p: null,
      d: null,
      q: null,
      auto_select: true,
      // GARCH 參數
      dist: 'normal'
    })

    // 模型選項
    const models = ref([
      { value: 'lstm', text: 'LSTM', description: '長短期記憶神經網路，適合捕捉長期依賴關係' },
      { value: 'arima', text: 'ARIMA', description: '自回歸移動平均模型，適合時間序列預測' },
      { value: 'garch', text: 'GARCH', description: '廣義自回歸條件異方差模型，適合波動率預測' }
    ])

    let chartInstance = null

    // 計算屬性
    const canPredict = computed(() => {
      if (targetType.value === 'market') {
        return true
      }
      return selectedStock.value !== null
    })

    const currentPrice = computed(() => {
      return predictionResult.value?.current_price || 0
    })

    const predictedPrice = computed(() => {
      if (!predictionResult.value?.predictions?.[0]) return 0
      return predictionResult.value.predictions[0].predicted_price
    })

    const predictedChange = computed(() => {
      if (!currentPrice.value || !predictedPrice.value) return 0
      return ((predictedPrice.value - currentPrice.value) / currentPrice.value * 100)
    })

    const confidenceLower = computed(() => {
      if (!predictionResult.value?.predictions?.[0]) return 0
      return predictionResult.value.predictions[0].confidence_lower
    })

    const confidenceUpper = computed(() => {
      if (!predictionResult.value?.predictions?.[0]) return 0
      return predictionResult.value.predictions[0].confidence_upper
    })

    // ========================================
    // 修正 1: 新增日期格式化函數
    // ========================================
    const formatDate = (dateString) => {
      if (!dateString) return '---'

      try {
        const date = new Date(dateString)
        const year = date.getFullYear()
        const month = String(date.getMonth() + 1).padStart(2, '0')
        const day = String(date.getDate()).padStart(2, '0')

        return `${year}-${month}-${day}`
      } catch (error) {
        console.error('日期格式化錯誤:', error)
        return dateString
      }
    }

    // ========================================
    // 修正 3: 從資料庫動態載入股票列表並去重
    // ========================================
    const loadStocks = async () => {
      loadingStocks.value = true
      try {
        const response = await axios.get('/stocks', {
          params: {
            per_page: 200,
            has_prices: true  // 只載入有價格資料的股票
          }
        })

        if (response.data.success) {
          // 使用 Map 去重（以 symbol 為 key）
          const stocksMap = new Map()

          response.data.data.data.forEach(stock => {
            if (stock.symbol && stock.name) {
              stocksMap.set(stock.symbol, {
                symbol: stock.symbol,
                name: stock.name,
                value: stock.symbol,
                display: `${stock.symbol} - ${stock.name}`
              })
            }
          })

          // 轉換為陣列並排序
          stocksList.value = Array.from(stocksMap.values()).sort((a, b) => {
            return a.symbol.localeCompare(b.symbol)
          })

          console.log('✅ 載入股票列表成功:', stocksList.value.length, '個')
        }
      } catch (err) {
        console.error('❌ 載入股票列表失敗:', err)
        error.value = '無法載入股票列表'
        stocksList.value = []
      } finally {
        loadingStocks.value = false
      }
    }

    const searchStocks = (search) => {
      // 實作股票搜尋邏輯
      console.log('搜尋股票:', search)
    }

    const runPrediction = async () => {
      loading.value = true
      error.value = null
      predictionResult.value = null

      try {
        // 準備請求參數
        const requestData = {
          model_type: selectedModel.value,
          prediction_days: predictionDays.value,
          parameters: {
            historical_days: trainingPeriod.value,
            ...modelParameters.value
          }
        }

        // 根據目標類型設定參數
        if (targetType.value === 'market') {
          requestData.underlying = 'TXO'
        } else {
          requestData.stock_symbol = selectedStock.value
        }

        console.log('發送預測請求:', requestData)

        // 呼叫 API
        const response = await axios.post('/predictions/run', requestData)

        console.log('預測回應:', response.data)

        if (response.data.success) {
          predictionResult.value = response.data.data

          // 更新圖表
          await nextTick()
          if (showChart.value) {
            updateChart()
          }
        } else {
          error.value = response.data.message || '預測失敗'
        }
      } catch (err) {
        console.error('預測錯誤:', err)
        error.value = err.response?.data?.message || '執行預測時發生錯誤'
      } finally {
        loading.value = false
      }
    }

    const updateChart = () => {
      if (!predictionChart.value || !predictionResult.value) return

      // 銷毀舊圖表
      if (chartInstance) {
        chartInstance.destroy()
      }

      const ctx = predictionChart.value.getContext('2d')

      // 準備歷史資料
      const historicalData = predictionResult.value.historical_prices || []
      const predictions = predictionResult.value.predictions || []

      // 合併資料
      const allDates = [
        ...historicalData.slice(-30).map(d => formatDate(d.date)),
        ...predictions.map(p => formatDate(p.target_date))
      ]

      const historicalPrices = historicalData.slice(-30).map(d => d.close)
      const predictionPrices = new Array(historicalPrices.length).fill(null)

      // 連接最後一個歷史價格到預測
      predictionPrices[predictionPrices.length - 1] = historicalPrices[historicalPrices.length - 1]
      predictionPrices.push(...predictions.map(p => p.predicted_price))

      chartInstance = new Chart(ctx, {
        type: 'line',
        data: {
          labels: allDates,
          datasets: [
            {
              label: '歷史收盤價',
              data: [...historicalPrices, ...new Array(predictions.length).fill(null)],
              borderColor: 'rgb(75, 192, 192)',
              backgroundColor: 'rgba(75, 192, 192, 0.2)',
              tension: 0.1,
              pointRadius: 2
            },
            {
              label: '預測價格',
              data: predictionPrices,
              borderColor: 'rgb(255, 99, 132)',
              backgroundColor: 'rgba(255, 99, 132, 0.2)',
              borderDash: [5, 5],
              tension: 0.1,
              pointRadius: 4
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              display: true
            },
            title: {
              display: true,
              text: '價格走勢與預測'
            }
          },
          scales: {
            y: {
              beginAtZero: false
            }
          }
        }
      })
    }

    // 生命週期
    onMounted(() => {
      loadStocks()
    })

    // 監聽
    watch(targetType, () => {
      predictionResult.value = null
      error.value = null
    })

    watch(showChart, (newValue) => {
      if (newValue && predictionResult.value) {
        nextTick(() => {
          updateChart()
        })
      }
    })

    return {
      // 狀態
      loading,
      loadingStocks,
      error,
      predictionResult,
      predictionChart,
      showParametersDialog,
      showChart,

      // 表單
      targetType,
      selectedStock,
      stocksList,
      selectedModel,
      predictionDays,
      trainingPeriod,
      modelParameters,
      models,

      // 計算屬性
      canPredict,
      currentPrice,
      predictedPrice,
      predictedChange,
      confidenceLower,
      confidenceUpper,

      // 方法
      formatDate,
      loadStocks,
      searchStocks,
      runPrediction,
      updateChart
    }
  }
}
</script>

<style scoped>
.prediction-page {
  padding: 20px;
  background-color: #f5f5f5;
  min-height: 100vh;
}
</style>
