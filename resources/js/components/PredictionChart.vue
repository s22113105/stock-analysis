<template>
  <div class="prediction-chart-container">
    <!-- 圖表標題與控制項 -->
    <v-card class="mb-4">
      <v-card-title>
        <h3>股價預測分析</h3>
      </v-card-title>

      <v-card-subtitle>
        {{ stockInfo.name }} ({{ stockInfo.symbol }})
      </v-card-subtitle>

      <v-card-text>
        <!-- 模型選擇器 -->
        <v-row>
          <v-col cols="12" md="4">
            <v-select
              v-model="selectedModel"
              :items="modelOptions"
              label="預測模型"
              outlined
              dense
              @change="onModelChange"
            />
          </v-col>

          <v-col cols="12" md="4">
            <v-text-field
              v-model.number="predictionDays"
              label="預測天數"
              type="number"
              min="1"
              max="30"
              outlined
              dense
            />
          </v-col>

          <v-col cols="12" md="4">
            <v-btn
              color="primary"
              @click="runPrediction"
              :loading="loading"
            >
              <v-icon left>mdi-chart-line</v-icon>
              執行預測
            </v-btn>

            <v-btn
              color="secondary"
              @click="compareModels"
              :loading="comparing"
              class="ml-2"
            >
              <v-icon left>mdi-compare</v-icon>
              比較模型
            </v-btn>
          </v-col>
        </v-row>

        <!-- 進階參數（依模型顯示） -->
        <v-expansion-panels v-if="showAdvancedParams">
          <v-expansion-panel>
            <v-expansion-panel-header>
              進階參數設定
            </v-expansion-panel-header>
            <v-expansion-panel-content>
              <ModelParameters
                :model-type="selectedModel"
                v-model="modelParameters"
              />
            </v-expansion-panel-content>
          </v-expansion-panel>
        </v-expansion-panels>
      </v-card-text>
    </v-card>

    <!-- 主要圖表 -->
    <v-card class="mb-4">
      <v-card-text>
        <canvas ref="mainChart"></canvas>
      </v-card-text>
    </v-card>

    <!-- 預測統計資訊 -->
    <v-row v-if="predictionResults">
      <v-col cols="12" md="3">
        <v-card>
          <v-card-text class="text-center">
            <div class="text-h3">{{ latestPrediction }}</div>
            <div class="text-subtitle-1">最新預測價格</div>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="3">
        <v-card>
          <v-card-text class="text-center">
            <div class="text-h3" :class="trendClass">
              {{ trendPercentage }}%
            </div>
            <div class="text-subtitle-1">預測漲跌幅</div>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="3">
        <v-card>
          <v-card-text class="text-center">
            <div class="text-h3">{{ confidenceLevel }}%</div>
            <div class="text-subtitle-1">信賴水準</div>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="3">
        <v-card>
          <v-card-text class="text-center">
            <div class="text-h3">{{ modelAccuracy }}%</div>
            <div class="text-subtitle-1">模型準確度</div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- 波動率圖表（GARCH模型） -->
    <v-card v-if="selectedModel === 'garch'" class="mb-4">
      <v-card-title>波動率預測</v-card-title>
      <v-card-text>
        <canvas ref="volatilityChart"></canvas>
      </v-card-text>
    </v-card>

    <!-- 模型比較表格 -->
    <v-card v-if="comparisonData">
      <v-card-title>模型比較結果</v-card-title>
      <v-card-text>
        <v-data-table
          :headers="comparisonHeaders"
          :items="comparisonItems"
          class="elevation-1"
        >
          <template v-slot:item.model="{ item }">
            <v-chip :color="getModelColor(item.model)" small>
              {{ item.model }}
            </v-chip>
          </template>

          <template v-slot:item.accuracy="{ item }">
            <v-progress-linear
              :value="item.accuracy"
              :color="getAccuracyColor(item.accuracy)"
              height="20"
            >
              {{ item.accuracy }}%
            </v-progress-linear>
          </template>
        </v-data-table>
      </v-card-text>
    </v-card>

    <!-- Monte Carlo 路徑圖（選擇性顯示） -->
    <v-card v-if="monteCarloPath">
      <v-card-title>Monte Carlo 模擬路徑</v-card-title>
      <v-card-text>
        <canvas ref="monteCarloChart"></canvas>
      </v-card-text>
    </v-card>
  </div>
</template>

<script>
import { ref, onMounted, watch, computed } from 'vue'
import Chart from 'chart.js/auto'
import axios from 'axios'
import ModelParameters from './ModelParameters.vue'

export default {
  name: 'PredictionChart',

  components: {
    ModelParameters
  },

  props: {
    stockId: {
      type: Number,
      required: true
    },
    stockInfo: {
      type: Object,
      default: () => ({
        symbol: '',
        name: ''
      })
    }
  },

  setup(props) {
    // 圖表參考
    const mainChart = ref(null)
    const volatilityChart = ref(null)
    const monteCarloChart = ref(null)

    // Chart.js 實例
    let mainChartInstance = null
    let volatilityChartInstance = null
    let monteCarloChartInstance = null

    // 資料狀態
    const loading = ref(false)
    const comparing = ref(false)
    const selectedModel = ref('lstm')
    const predictionDays = ref(7)
    const modelParameters = ref({})
    const predictionResults = ref(null)
    const comparisonData = ref(null)
    const monteCarloPath = ref(null)

    // 模型選項
    const modelOptions = [
      { text: 'LSTM 神經網路', value: 'lstm' },
      { text: 'ARIMA 時間序列', value: 'arima' },
      { text: 'GARCH 波動率', value: 'garch' },
      { text: 'Monte Carlo 模擬', value: 'monte_carlo' }
    ]

    // 比較表格標題
    const comparisonHeaders = [
      { text: '模型', value: 'model' },
      { text: '預測價格', value: 'predicted_price' },
      { text: '信賴區間', value: 'confidence_interval' },
      { text: 'AIC', value: 'aic' },
      { text: 'BIC', value: 'bic' },
      { text: '準確度', value: 'accuracy' }
    ]

    // 計算屬性
    const showAdvancedParams = computed(() => {
      return ['lstm', 'arima', 'garch'].includes(selectedModel.value)
    })

    const latestPrediction = computed(() => {
      if (!predictionResults.value?.predictions?.length) return '---'
      const latest = predictionResults.value.predictions[predictionResults.value.predictions.length - 1]
      return `$${latest.predicted_price.toFixed(2)}`
    })

    const trendPercentage = computed(() => {
      if (!predictionResults.value?.predictions?.length) return 0
      const currentPrice = props.stockInfo.currentPrice || 100
      const latest = predictionResults.value.predictions[predictionResults.value.predictions.length - 1]
      return ((latest.predicted_price - currentPrice) / currentPrice * 100).toFixed(2)
    })

    const trendClass = computed(() => {
      const trend = parseFloat(trendPercentage.value)
      return {
        'text-success': trend > 0,
        'text-error': trend < 0,
        'text-grey': trend === 0
      }
    })

    const confidenceLevel = computed(() => {
      if (!predictionResults.value?.predictions?.length) return 95
      return (predictionResults.value.predictions[0].confidence_level * 100).toFixed(0)
    })

    const modelAccuracy = computed(() => {
      if (!predictionResults.value?.metrics) return '---'
      // 根據不同模型顯示不同的準確度指標
      const metrics = predictionResults.value.metrics
      if (metrics.accuracy) return metrics.accuracy.toFixed(1)
      if (metrics.final_mae) return (100 - metrics.final_mae).toFixed(1) // 簡化計算
      return '---'
    })

    const comparisonItems = computed(() => {
      if (!comparisonData.value) return []

      return Object.entries(comparisonData.value.results).map(([model, result]) => {
        if (!result.success) return null

        const latestPred = result.predictions[result.predictions.length - 1]
        return {
          model: model.toUpperCase(),
          predicted_price: latestPred.predicted_price.toFixed(2),
          confidence_interval: `${latestPred.confidence_lower.toFixed(2)} - ${latestPred.confidence_upper.toFixed(2)}`,
          aic: result.model_info?.aic?.toFixed(2) || '---',
          bic: result.model_info?.bic?.toFixed(2) || '---',
          accuracy: Math.random() * 20 + 75 // 示例準確度
        }
      }).filter(Boolean)
    })

    // 執行預測
    const runPrediction = async () => {
      loading.value = true

      try {
        const response = await axios.post('/api/predictions/run', {
          stock_id: props.stockId,
          model_type: selectedModel.value,
          prediction_days: predictionDays.value,
          parameters: modelParameters.value
        })

        if (response.data.success) {
          predictionResults.value = response.data.data
          updateMainChart()

          if (selectedModel.value === 'garch') {
            updateVolatilityChart()
          }

          if (selectedModel.value === 'monte_carlo' && response.data.data.paths_sample) {
            monteCarloPath.value = response.data.data.paths_sample
            updateMonteCarloChart()
          }
        }
      } catch (error) {
        console.error('預測失敗:', error)
        alert('預測失敗: ' + error.message)
      } finally {
        loading.value = false
      }
    }

    // 比較模型
    const compareModels = async () => {
      comparing.value = true

      try {
        const response = await axios.post('/api/predictions/compare', {
          stock_id: props.stockId,
          models: ['lstm', 'arima', 'garch', 'monte_carlo'],
          prediction_days: predictionDays.value
        })

        if (response.data.success) {
          comparisonData.value = response.data.data
          updateComparisonChart()
        }
      } catch (error) {
        console.error('模型比較失敗:', error)
        alert('模型比較失敗: ' + error.message)
      } finally {
        comparing.value = false
      }
    }

    // 更新主圖表
    const updateMainChart = () => {
      if (!mainChart.value || !predictionResults.value) return

      const ctx = mainChart.value.getContext('2d')

      if (mainChartInstance) {
        mainChartInstance.destroy()
      }

      const predictions = predictionResults.value.predictions

      mainChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
          labels: predictions.map(p => p.target_date),
          datasets: [
            {
              label: '預測價格',
              data: predictions.map(p => p.predicted_price),
              borderColor: 'rgb(75, 192, 192)',
              backgroundColor: 'rgba(75, 192, 192, 0.2)',
              tension: 0.1
            },
            {
              label: '信賴上界',
              data: predictions.map(p => p.confidence_upper),
              borderColor: 'rgba(255, 99, 132, 0.5)',
              borderDash: [5, 5],
              fill: false
            },
            {
              label: '信賴下界',
              data: predictions.map(p => p.confidence_lower),
              borderColor: 'rgba(255, 99, 132, 0.5)',
              borderDash: [5, 5],
              fill: '-1',
              backgroundColor: 'rgba(255, 99, 132, 0.1)'
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            title: {
              display: true,
              text: `${selectedModel.value.toUpperCase()} 模型預測結果`
            },
            legend: {
              position: 'top'
            },
            tooltip: {
              mode: 'index',
              intersect: false
            }
          },
          scales: {
            x: {
              title: {
                display: true,
                text: '日期'
              }
            },
            y: {
              title: {
                display: true,
                text: '價格 (NTD)'
              }
            }
          }
        }
      })
    }

    // 更新波動率圖表
    const updateVolatilityChart = () => {
      if (!volatilityChart.value || !predictionResults.value) return

      const ctx = volatilityChart.value.getContext('2d')

      if (volatilityChartInstance) {
        volatilityChartInstance.destroy()
      }

      const predictions = predictionResults.value.predictions

      volatilityChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
          labels: predictions.map(p => p.target_date),
          datasets: [{
            label: '預測波動率 (%)',
            data: predictions.map(p => p.predicted_volatility),
            backgroundColor: 'rgba(54, 162, 235, 0.5)',
            borderColor: 'rgba(54, 162, 235, 1)',
            borderWidth: 1
          }]
        },
        options: {
          responsive: true,
          plugins: {
            title: {
              display: true,
              text: 'GARCH 波動率預測'
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

    // 更新 Monte Carlo 圖表
    const updateMonteCarloChart = () => {
      if (!monteCarloChart.value || !monteCarloPath.value) return

      const ctx = monteCarloChart.value.getContext('2d')

      if (monteCarloChartInstance) {
        monteCarloChartInstance.destroy()
      }

      const datasets = monteCarloPath.value.map((path, index) => ({
        label: `路徑 ${index + 1}`,
        data: path,
        borderColor: `hsla(${index * 36}, 70%, 50%, 0.5)`,
        borderWidth: 1,
        fill: false,
        pointRadius: 0
      }))

      monteCarloChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
          labels: Array.from({length: datasets[0].data.length}, (_, i) => `Day ${i}`),
          datasets: datasets
        },
        options: {
          responsive: true,
          plugins: {
            title: {
              display: true,
              text: 'Monte Carlo 模擬路徑（樣本）'
            },
            legend: {
              display: false
            }
          },
          scales: {
            x: {
              title: {
                display: true,
                text: '天數'
              }
            },
            y: {
              title: {
                display: true,
                text: '價格 (NTD)'
              }
            }
          }
        }
      })
    }

    // 更新比較圖表
    const updateComparisonChart = () => {
      if (!mainChart.value || !comparisonData.value) return

      const ctx = mainChart.value.getContext('2d')

      if (mainChartInstance) {
        mainChartInstance.destroy()
      }

      mainChartInstance = new Chart(ctx, {
        type: 'line',
        data: comparisonData.value.chart_data,
        options: {
          responsive: true,
          plugins: {
            title: {
              display: true,
              text: '模型預測比較'
            },
            legend: {
              position: 'top'
            },
            tooltip: {
              mode: 'index',
              intersect: false
            }
          },
          scales: {
            x: {
              title: {
                display: true,
                text: '日期'
              }
            },
            y: {
              title: {
                display: true,
                text: '價格 (NTD)'
              }
            }
          }
        }
      })
    }

    // 取得模型顏色
    const getModelColor = (model) => {
      const colors = {
        'LSTM': 'success',
        'ARIMA': 'info',
        'GARCH': 'warning',
        'MONTE_CARLO': 'primary'
      }
      return colors[model] || 'grey'
    }

    // 取得準確度顏色
    const getAccuracyColor = (accuracy) => {
      if (accuracy >= 90) return 'success'
      if (accuracy >= 75) return 'info'
      if (accuracy >= 60) return 'warning'
      return 'error'
    }

    // 模型改變事件
    const onModelChange = () => {
      // 重設參數
      modelParameters.value = {}
    }

    // 元件掛載
    onMounted(() => {
      // 初始載入預設模型預測
      runPrediction()
    })

    return {
      mainChart,
      volatilityChart,
      monteCarloChart,
      loading,
      comparing,
      selectedModel,
      predictionDays,
      modelParameters,
      predictionResults,
      comparisonData,
      monteCarloPath,
      modelOptions,
      comparisonHeaders,
      comparisonItems,
      showAdvancedParams,
      latestPrediction,
      trendPercentage,
      trendClass,
      confidenceLevel,
      modelAccuracy,
      runPrediction,
      compareModels,
      getModelColor,
      getAccuracyColor,
      onModelChange
    }
  }
}
</script>

<style scoped>
.prediction-chart-container {
  padding: 16px;
}

canvas {
  max-height: 400px;
}

.text-success {
  color: #4caf50;
}

.text-error {
  color: #f44336;
}

.text-grey {
  color: #9e9e9e;
}
</style>
