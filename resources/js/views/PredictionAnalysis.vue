<template>
  <div class="prediction-page">
    <v-row>
      <v-col cols="12">
        <v-card elevation="2">
          <v-card-title>
            <v-icon class="mr-2">mdi-chart-line</v-icon>
            明日收盤價預測
            <v-spacer></v-spacer>
            <v-btn
              color="primary"
              prepend-icon="mdi-play"
              @click="runPrediction"
              :loading="loading"
              :disabled="!canPredict"
              size="large"
            >
              執行預測
            </v-btn>
          </v-card-title>

          <v-card-text>
            <!-- 步驟 1：選擇標的類型 -->
            <v-row class="mb-4">
              <v-col cols="12">
                <div class="text-h6 mb-3">步驟 1：選擇標的類型</div>
                <v-btn-toggle
                  v-model="targetType"
                  color="primary"
                  mandatory
                  divided
                  class="mb-4"
                >
                  <v-btn value="stock" size="large">
                    <v-icon start>mdi-chart-line-variant</v-icon>
                    股票
                  </v-btn>
                  <v-btn value="option" size="large">
                    <v-icon start>mdi-chart-bell-curve</v-icon>
                    選擇權 (TXO)
                  </v-btn>
                </v-btn-toggle>
              </v-col>
            </v-row>

            <!-- 步驟 2：選擇具體標的（只有股票需要） -->
            <v-row v-if="targetType === 'stock'" class="mb-4">
              <v-col cols="12">
                <div class="text-h6 mb-3">步驟 2：選擇股票</div>
                <v-autocomplete
                  v-model="selectedStock"
                  :items="stocksList"
                  :loading="loadingStocks"
                  item-title="display_name"
                  item-value="id"
                  label="選擇股票"
                  placeholder="輸入股票代碼或名稱搜尋..."
                  density="comfortable"
                  clearable
                >
                  <template v-slot:prepend-inner>
                    <v-icon color="primary">mdi-chart-line-variant</v-icon>
                  </template>
                  <template v-slot:item="{ props, item }">
                    <v-list-item v-bind="props">
                      <template v-slot:title>
                        {{ item.raw.symbol }} {{ item.raw.name }}
                      </template>
                      <template v-slot:subtitle>
                        最新價格: ${{ item.raw.latest_price || '---' }}
                      </template>
                    </v-list-item>
                  </template>
                </v-autocomplete>
              </v-col>
            </v-row>

            <!-- TXO 說明（選擇權時顯示） -->
            <v-row v-if="targetType === 'option'" class="mb-4">
              <v-col cols="12">
                <v-alert type="info" variant="tonal" class="mb-0">
                  <v-alert-title>
                    <v-icon>mdi-information</v-icon>
                    台指選擇權 (TXO) 預測
                  </v-alert-title>
                  系統將使用 TXO 整體歷史資料，預測明日台指選擇權指數價格
                </v-alert>
              </v-col>
            </v-row>

            <!-- 步驟 3：選擇模型和參數 -->
            <v-row class="mb-4">
              <v-col cols="12">
                <div class="text-h6 mb-3">步驟 {{ targetType === 'stock' ? '3' : '2' }}：模型設定</div>
              </v-col>

              <v-col cols="12" md="4">
                <v-select
                  v-model="selectedModel"
                  :items="models"
                  item-title="text"
                  item-value="value"
                  label="預測模型"
                  density="comfortable"
                >
                  <template v-slot:prepend-inner>
                    <v-icon color="primary">mdi-brain</v-icon>
                  </template>
                  <template v-slot:item="{ props, item }">
                    <v-list-item v-bind="props">
                      <template v-slot:subtitle>
                        {{ item.raw.description }}
                      </template>
                    </v-list-item>
                  </template>
                </v-select>
              </v-col>

              <v-col cols="12" md="3">
                <v-select
                  v-model="trainingPeriod"
                  :items="[30, 60, 90]"
                  label="訓練期間"
                  density="comfortable"
                  suffix="天"
                >
                  <template v-slot:prepend-inner>
                    <v-icon color="primary">mdi-calendar</v-icon>
                  </template>
                </v-select>
              </v-col>

              <v-col cols="12" md="2">
                <v-btn
                  color="grey-darken-1"
                  block
                  prepend-icon="mdi-cog"
                  @click="showParametersDialog = true"
                  variant="outlined"
                  height="40"
                >
                  進階設定
                </v-btn>
              </v-col>
            </v-row>

            <!-- 預測結果卡片 -->
            <v-row v-if="predictionResult" class="mt-2">
              <v-col cols="12">
                <v-card elevation="4" class="prediction-result-card">
                  <v-card-text class="pa-6">
                    <!-- 標的資訊 -->
                    <div class="d-flex align-center mb-4">
                      <v-chip
                        :color="targetType === 'stock' ? 'primary' : 'success'"
                        size="large"
                        class="mr-3"
                      >
                        {{ targetType === 'stock' ? '股票' : 'TXO' }}
                      </v-chip>
                      <div>
                        <div class="text-h6">{{ getTargetName() }}</div>
                        <div class="text-caption text-grey">
                          {{ getTargetInfo() }}
                        </div>
                      </div>
                    </div>

                    <v-divider class="my-4"></v-divider>

                    <!-- 價格預測 -->
                    <v-row align="center" class="my-4">
                      <!-- 今日收盤價 -->
                      <v-col cols="12" md="5">
                        <v-card color="grey-lighten-4" flat class="pa-4 text-center">
                          <div class="text-caption text-grey-darken-1 mb-1">今日收盤價</div>
                          <div class="text-h4 font-weight-bold text-grey-darken-3">
                            ${{ currentPrice }}
                          </div>
                          <div class="text-caption text-grey mt-1">
                            {{ predictionResult.current_date }}
                          </div>
                        </v-card>
                      </v-col>

                      <!-- 箭頭 -->
                      <v-col cols="12" md="2" class="text-center">
                        <v-icon size="48" :color="getPredictionColor()">
                          mdi-arrow-right-thick
                        </v-icon>
                        <div class="text-caption text-grey mt-2">預測</div>
                      </v-col>

                      <!-- 明日預測價格 -->
                      <v-col cols="12" md="5">
                        <v-card :color="getPredictionColor()" dark flat class="pa-4 text-center">
                          <div class="text-caption mb-1" style="opacity: 0.9">明日預測收盤價</div>
                          <div class="text-h4 font-weight-bold">
                            ${{ predictedPrice }}
                          </div>
                          <div class="text-h6 mt-2">
                            <v-icon size="20">
                              {{ getPredictionChange() >= 0 ? 'mdi-trending-up' : 'mdi-trending-down' }}
                            </v-icon>
                            {{ getPredictionChange() >= 0 ? '+' : '' }}{{ getPredictionChange() }}%
                          </div>
                        </v-card>
                      </v-col>
                    </v-row>

                    <v-divider class="my-4"></v-divider>

                    <!-- 信賴區間 -->
                    <div class="text-center">
                      <div class="text-caption text-grey mb-2">
                        95% 信賴區間
                      </div>
                      <div class="text-h6 text-grey-darken-2">
                        ${{ confidenceLower }} ~ ${{ confidenceUpper }}
                      </div>
                      <div class="text-caption text-grey mt-1">
                        預測價格有 95% 機率落在此區間
                      </div>
                    </div>
                  </v-card-text>
                </v-card>
              </v-col>
            </v-row>

            <!-- 歷史走勢圖（可選顯示） -->
            <v-row v-if="predictionResult && showChart" class="mt-4">
              <v-col cols="12">
                <v-card outlined>
                  <v-card-title class="d-flex justify-space-between align-center">
                    <span>價格走勢</span>
                    <v-chip size="small" :color="getModelColor(selectedModel)">
                      {{ getModelName(selectedModel) }} 模型
                    </v-chip>
                  </v-card-title>
                  <v-card-text>
                    <canvas ref="predictionChart" height="300"></canvas>
                  </v-card-text>
                </v-card>
              </v-col>
            </v-row>

            <!-- 顯示圖表按鈕 -->
            <v-row v-if="predictionResult" class="mt-2">
              <v-col cols="12" class="text-center">
                <v-btn
                  @click="showChart = !showChart"
                  variant="text"
                  prepend-icon="mdi-chart-line"
                  color="primary"
                >
                  {{ showChart ? '隱藏' : '顯示' }}歷史走勢圖
                </v-btn>
              </v-col>
            </v-row>

            <!-- 空狀態 -->
            <v-row v-if="!predictionResult && !loading">
              <v-col cols="12">
                <v-card outlined class="text-center pa-12">
                  <v-icon size="80" color="grey-lighten-2">mdi-chart-timeline-variant</v-icon>
                  <div class="text-h5 mt-4 text-grey-darken-1">
                    {{ targetType === 'stock' ? '選擇股票並執行預測' : '執行 TXO 預測' }}
                  </div>
                  <div class="text-body-2 text-grey mt-2">
                    系統將分析歷史價格資料，預測明日可能的收盤價
                  </div>
                </v-card>
              </v-col>
            </v-row>

            <!-- 載入狀態 -->
            <v-row v-if="loading">
              <v-col cols="12">
                <v-card outlined class="text-center pa-12">
                  <v-progress-circular
                    indeterminate
                    color="primary"
                    size="64"
                    width="6"
                  ></v-progress-circular>
                  <div class="text-h6 mt-4">正在分析歷史資料...</div>
                  <div class="text-caption text-grey mt-2">
                    使用 {{ getModelName(selectedModel) }} 模型預測明日收盤價（約需 30-60 秒）
                  </div>
                  <v-progress-linear
                    indeterminate
                    color="primary"
                    class="mt-4"
                  ></v-progress-linear>
                </v-card>
              </v-col>
            </v-row>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- 模型參數調整對話框 -->
    <v-dialog v-model="showParametersDialog" max-width="600">
      <v-card>
        <v-card-title>{{ getModelName(selectedModel) }} 模型參數</v-card-title>
        <v-card-text>
          <template v-if="selectedModel === 'lstm'">
            <v-slider
              v-model="modelParameters.lstm.epochs"
              label="訓練輪數"
              min="20"
              max="200"
              step="10"
              thumb-label
              :hint="`目前: ${modelParameters.lstm.epochs} 輪`"
              persistent-hint
              class="mb-4"
            ></v-slider>
            <v-slider
              v-model="modelParameters.lstm.units"
              label="神經元數量"
              min="64"
              max="256"
              step="32"
              thumb-label
              :hint="`目前: ${modelParameters.lstm.units}`"
              persistent-hint
            ></v-slider>
          </template>
          <template v-else-if="selectedModel === 'arima'">
            <v-switch
              v-model="modelParameters.arima.auto_select"
              label="自動選擇最佳參數"
              color="primary"
            ></v-switch>
          </template>
          <template v-else-if="selectedModel === 'garch'">
            <v-text-field
              v-model.number="modelParameters.garch.p"
              label="GARCH 階數 (p)"
              type="number"
              min="1"
              max="3"
            ></v-text-field>
            <v-text-field
              v-model.number="modelParameters.garch.q"
              label="ARCH 階數 (q)"
              type="number"
              min="1"
              max="3"
              class="mt-2"
            ></v-text-field>
          </template>
        </v-card-text>
        <v-card-actions>
          <v-spacer></v-spacer>
          <v-btn @click="showParametersDialog = false">確定</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import axios from 'axios'
import Chart from 'chart.js/auto'

export default {
  name: 'PredictionAnalysis',
  setup() {
    const loading = ref(false)
    const loadingStocks = ref(false)
    const predictionResult = ref(null)
    const predictionChart = ref(null)
    const showParametersDialog = ref(false)
    const showChart = ref(false)
    let chartInstance = null

    // 標的類型：stock 或 option
    const targetType = ref('option')  // 預設選擇權

    // 股票相關
    const selectedStock = ref(null)
    const stocksList = ref([])

    const selectedModel = ref('lstm')
    const models = ref([
      { text: 'LSTM', value: 'lstm', description: '深度學習 - 準確度高' },
      { text: 'ARIMA', value: 'arima', description: '統計模型 - 速度快' },
      { text: 'GARCH', value: 'garch', description: '波動率模型' }
    ])

    const trainingPeriod = ref(60)

    const modelParameters = ref({
      lstm: {
        epochs: 100,
        units: 128,
        lookback: 60
      },
      arima: {
        auto_select: true
      },
      garch: {
        p: 1,
        q: 1
      }
    })

    // 計算屬性
    const canPredict = computed(() => {
      if (targetType.value === 'stock') {
        return selectedStock.value !== null
      } else {
        return true  // TXO 不需要選擇
      }
    })

    const currentPrice = computed(() => {
      if (!predictionResult.value) return '---'
      return predictionResult.value.current_price?.toFixed(2) || '---'
    })

    const predictedPrice = computed(() => {
      if (!predictionResult.value || !predictionResult.value.predictions) return '---'
      return predictionResult.value.predictions[0]?.predicted_price?.toFixed(2) || '---'
    })

    const confidenceLower = computed(() => {
      if (!predictionResult.value || !predictionResult.value.predictions) return '---'
      return predictionResult.value.predictions[0]?.confidence_lower?.toFixed(2) || '---'
    })

    const confidenceUpper = computed(() => {
      if (!predictionResult.value || !predictionResult.value.predictions) return '---'
      return predictionResult.value.predictions[0]?.confidence_upper?.toFixed(2) || '---'
    })

    // 載入股票列表
    const loadStocks = async () => {
      loadingStocks.value = true
      try {
        const response = await axios.get('/stocks', {
          params: {
            per_page: 200
          }
        })

        if (response.data.success) {
          stocksList.value = response.data.data.data.map(stock => ({
            id: stock.id,
            symbol: stock.symbol,
            name: stock.name,
            latest_price: stock.latest_price?.close,
            display_name: `${stock.symbol} ${stock.name}`
          }))
        }
      } catch (error) {
        console.error('載入股票列表失敗:', error)
        alert('無法載入股票列表，請檢查 API 連線')
      } finally {
        loadingStocks.value = false
      }
    }

    // 執行預測
    const runPrediction = async () => {
      if (!canPredict.value) {
        alert(targetType.value === 'stock' ? '請選擇股票' : '請設定預測參數')
        return
      }

      loading.value = true
      predictionResult.value = null

      try {
        const requestData = {
          target_type: targetType.value,  // 'stock' 或 'option'
          target_id: targetType.value === 'stock' ? selectedStock.value : null,
          underlying: targetType.value === 'option' ? 'TXO' : null,
          model_type: selectedModel.value,
          prediction_days: 1,
          parameters: {
            historical_days: trainingPeriod.value,
            ...modelParameters.value[selectedModel.value]
          }
        }

        const response = await axios.post('/predictions/run', requestData)

        if (response.data.success) {
          predictionResult.value = response.data.data

          if (showChart.value) {
            setTimeout(() => {
              updateChart()
            }, 100)
          }
        } else {
          alert('預測失敗: ' + (response.data.message || '未知錯誤'))
        }
      } catch (error) {
        console.error('預測執行失敗:', error)
        alert('預測失敗: ' + (error.response?.data?.message || error.message))
      } finally {
        loading.value = false
      }
    }

    const getPredictionChange = () => {
      if (!predictionResult.value || !predictionResult.value.current_price) return 0
      const current = predictionResult.value.current_price
      const predicted = parseFloat(predictedPrice.value)
      if (isNaN(predicted) || current === 0) return 0
      return ((predicted - current) / current * 100).toFixed(2)
    }

    const getPredictionColor = () => {
      const change = parseFloat(getPredictionChange())
      if (change > 0) return 'success'
      if (change < 0) return 'error'
      return 'warning'
    }

    const getTargetName = () => {
      if (targetType.value === 'stock' && predictionResult.value) {
        return predictionResult.value.target_info?.name || '股票'
      }
      return '台指選擇權 (TXO)'
    }

    const getTargetInfo = () => {
      if (targetType.value === 'stock' && predictionResult.value) {
        return `代碼: ${predictionResult.value.target_info?.symbol || '---'}`
      }
      return '台灣期貨交易所 - 加權指數選擇權'
    }

    const getModelColor = (model) => {
      const colors = {
        'lstm': 'primary',
        'arima': 'success',
        'garch': 'warning'
      }
      return colors[model] || 'grey'
    }

    const getModelName = (model) => {
      const names = {
        'lstm': 'LSTM',
        'arima': 'ARIMA',
        'garch': 'GARCH'
      }
      return names[model] || model.toUpperCase()
    }

    const updateChart = () => {
      if (!predictionChart.value || !predictionResult.value) return

      if (chartInstance) {
        chartInstance.destroy()
      }

      const ctx = predictionChart.value.getContext('2d')
      const historicalData = predictionResult.value.historical_prices || []
      const predictions = predictionResult.value.predictions || []

      const historicalDates = historicalData.map(item => item.date || item.trade_date)
      const historicalPrices = historicalData.map(item => item.close)
      const predictionDates = predictions.map(item => item.target_date)
      const predictionPrices = predictions.map(item => item.predicted_price)

      const allDates = [...historicalDates, ...predictionDates]
      const historicalFull = [...historicalPrices, ...new Array(predictions.length).fill(null)]
      const predictionFull = [...new Array(historicalData.length).fill(null), ...predictionPrices]

      chartInstance = new Chart(ctx, {
        type: 'line',
        data: {
          labels: allDates,
          datasets: [
            {
              label: '歷史收盤價',
              data: historicalFull,
              borderColor: 'rgb(75, 192, 192)',
              backgroundColor: 'rgba(75, 192, 192, 0.1)',
              tension: 0.1,
              pointRadius: 2
            },
            {
              label: '預測收盤價',
              data: predictionFull,
              borderColor: 'rgb(255, 99, 132)',
              backgroundColor: 'rgba(255, 99, 132, 0.1)',
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
            }
          }
        }
      })
    }

    // 監聽類型變化，清空結果
    watch(targetType, () => {
      predictionResult.value = null
      selectedStock.value = null
    })

    watch(showChart, (newValue) => {
      if (newValue && predictionResult.value) {
        setTimeout(() => {
          updateChart()
        }, 100)
      }
    })

    onMounted(() => {
      loadStocks()
    })

    onUnmounted(() => {
      if (chartInstance) {
        chartInstance.destroy()
      }
    })

    return {
      loading,
      loadingStocks,
      predictionResult,
      predictionChart,
      showParametersDialog,
      showChart,
      targetType,
      selectedStock,
      stocksList,
      selectedModel,
      models,
      trainingPeriod,
      modelParameters,
      canPredict,
      currentPrice,
      predictedPrice,
      confidenceLower,
      confidenceUpper,
      loadStocks,
      runPrediction,
      getPredictionChange,
      getPredictionColor,
      getTargetName,
      getTargetInfo,
      getModelColor,
      getModelName
    }
  }
}
</script>

<style scoped>
.prediction-page {
  padding: 16px;
}

.prediction-result-card {
  border-left: 4px solid #1976d2;
}
</style>
