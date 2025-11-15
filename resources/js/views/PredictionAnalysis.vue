<template>
  <div class="prediction-page">
    <v-row>
      <v-col cols="12">
        <v-card elevation="2">
          <v-card-title>
            <v-icon class="mr-2">mdi-chart-line</v-icon>
            TXO 明日收盤價預測
            <v-spacer></v-spacer>
            <v-btn
              color="primary"
              prepend-icon="mdi-play"
              @click="runPrediction"
              :loading="loading"
              :disabled="!selectedOption"
              size="large"
            >
              執行預測
            </v-btn>
          </v-card-title>

          <v-card-text>
            <!-- 預測設定區域 -->
            <v-row class="mb-4">
              <!-- TXO 選擇權合約選擇 -->
              <v-col cols="12" md="5">
                <v-autocomplete
                  v-model="selectedOption"
                  :items="optionsList"
                  :loading="loadingOptions"
                  item-title="display_name"
                  item-value="id"
                  label="選擇 TXO 合約"
                  placeholder="輸入合約代碼或履約價搜尋..."
                  density="comfortable"
                  clearable
                >
                  <template v-slot:prepend-inner>
                    <v-icon color="primary">mdi-file-document-outline</v-icon>
                  </template>
                  <template v-slot:item="{ props, item }">
                    <v-list-item v-bind="props">
                      <template v-slot:prepend>
                        <v-chip
                          :color="item.raw.option_type === 'call' ? 'success' : 'error'"
                          size="small"
                          class="font-weight-bold"
                        >
                          {{ item.raw.option_type === 'call' ? 'Call' : 'Put' }}
                        </v-chip>
                      </template>
                      <template v-slot:subtitle>
                        履約價: {{ item.raw.strike_price }} | 到期: {{ item.raw.expiry_date }}
                      </template>
                    </v-list-item>
                  </template>
                </v-autocomplete>
              </v-col>

              <!-- 預測模型選擇 -->
              <v-col cols="12" md="3">
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
                </v-select>
              </v-col>

              <!-- 訓練期間 -->
              <v-col cols="12" md="2">
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

              <!-- 進階設定 -->
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
                    <!-- 合約資訊 -->
                    <div class="d-flex align-center mb-4">
                      <v-chip
                        :color="predictionResult.target_info.option_type === 'call' ? 'success' : 'error'"
                        size="large"
                        class="mr-3"
                      >
                        {{ predictionResult.target_info.option_type === 'call' ? 'Call' : 'Put' }}
                      </v-chip>
                      <div>
                        <div class="text-h6">{{ predictionResult.target_info.option_code }}</div>
                        <div class="text-caption text-grey">
                          履約價 {{ predictionResult.target_info.strike_price }} |
                          到期日 {{ predictionResult.target_info.expiry_date }}
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
                  <div class="text-h5 mt-4 text-grey-darken-1">選擇 TXO 合約並執行預測</div>
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
              :hint="`目前: ${modelParameters.lstm.epochs} 輪 (建議: 50-100)`"
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
              :hint="`目前: ${modelParameters.lstm.units} (建議: 128)`"
              persistent-hint
            ></v-slider>
          </template>
          <template v-else-if="selectedModel === 'arima'">
            <v-switch
              v-model="modelParameters.arima.auto_select"
              label="自動選擇最佳參數 (推薦)"
              color="primary"
              hint="系統會自動找出最適合的模型參數"
              persistent-hint
            ></v-switch>
          </template>
          <template v-else-if="selectedModel === 'garch'">
            <v-text-field
              v-model.number="modelParameters.garch.p"
              label="GARCH 階數 (p)"
              type="number"
              min="1"
              max="3"
              hint="建議: 1"
              persistent-hint
            ></v-text-field>
            <v-text-field
              v-model.number="modelParameters.garch.q"
              label="ARCH 階數 (q)"
              type="number"
              min="1"
              max="3"
              hint="建議: 1"
              persistent-hint
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
    const loadingOptions = ref(false)
    const predictionResult = ref(null)
    const predictionChart = ref(null)
    const showParametersDialog = ref(false)
    const showChart = ref(false)
    let chartInstance = null

    const selectedOption = ref(null)
    const optionsList = ref([])

    const selectedModel = ref('lstm')
    const models = ref([
      { text: 'LSTM', value: 'lstm' },
      { text: 'ARIMA', value: 'arima' },
      { text: 'GARCH', value: 'garch' }
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

    // 載入 TXO 選擇權列表（固定只查詢 TXO）
    const loadOptions = async () => {
      loadingOptions.value = true
      try {
        const response = await axios.get('/api/options', {
          params: {
            underlying: 'TXO',  // 固定只查詢 TXO
            active_only: true,
            per_page: 200  // 增加數量以顯示更多選項
          }
        })

        if (response.data.success) {
          optionsList.value = response.data.data.data.map(option => ({
            id: option.id,
            option_code: option.option_code,
            option_type: option.option_type,
            strike_price: option.strike_price,
            expiry_date: option.expiry_date,
            display_name: `${option.option_code} (${option.option_type === 'call' ? 'Call' : 'Put'} ${option.strike_price})`
          }))
        }
      } catch (error) {
        console.error('載入 TXO 選擇權列表失敗:', error)
        alert('無法載入選擇權列表，請檢查 API 連線')
      } finally {
        loadingOptions.value = false
      }
    }

    const runPrediction = async () => {
      if (!selectedOption.value) {
        alert('請選擇 TXO 合約')
        return
      }

      loading.value = true
      predictionResult.value = null

      try {
        const requestData = {
          option_id: selectedOption.value,
          model_type: selectedModel.value,
          prediction_days: 1,  // 固定預測明日
          parameters: {
            historical_days: trainingPeriod.value,
            ...modelParameters.value[selectedModel.value]
          }
        }

        const response = await axios.post('/api/predictions/run', requestData)

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

    watch(showChart, (newValue) => {
      if (newValue && predictionResult.value) {
        setTimeout(() => {
          updateChart()
        }, 100)
      }
    })

    onMounted(() => {
      loadOptions()
    })

    onUnmounted(() => {
      if (chartInstance) {
        chartInstance.destroy()
      }
    })

    return {
      loading,
      loadingOptions,
      predictionResult,
      predictionChart,
      showParametersDialog,
      showChart,
      selectedOption,
      optionsList,
      selectedModel,
      models,
      trainingPeriod,
      modelParameters,
      currentPrice,
      predictedPrice,
      confidenceLower,
      confidenceUpper,
      loadOptions,
      runPrediction,
      getPredictionChange,
      getPredictionColor,
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
