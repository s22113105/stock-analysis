<template>
  <div class="prediction-page">
    <v-row>
      <v-col cols="12">
        <v-card elevation="2">
          <v-card-title>
            <v-icon class="mr-2">mdi-crystal-ball</v-icon>
            預測模型分析
            <v-spacer></v-spacer>
            <v-btn color="primary" prepend-icon="mdi-play" @click="runPrediction">
              執行預測
            </v-btn>
          </v-card-title>

          <v-card-text>
            <!-- 模型設定 -->
            <v-row class="mb-4">
              <v-col cols="12" md="3">
                <v-text-field
                  v-model="symbol"
                  label="股票代碼"
                  density="compact"
                  hide-details
                ></v-text-field>
              </v-col>
              <v-col cols="12" md="3">
                <v-select
                  v-model="selectedModel"
                  :items="models"
                  label="預測模型"
                  density="compact"
                  hide-details
                ></v-select>
              </v-col>
              <v-col cols="12" md="2">
                <v-select
                  v-model="predictionDays"
                  :items="[5, 10, 15, 20, 30]"
                  label="預測天數"
                  density="compact"
                  hide-details
                ></v-select>
              </v-col>
              <v-col cols="12" md="2">
                <v-select
                  v-model="trainingPeriod"
                  :items="[30, 60, 90, 180, 365]"
                  label="訓練期間"
                  density="compact"
                  hide-details
                ></v-select>
              </v-col>
              <v-col cols="12" md="2">
                <v-btn color="info" block prepend-icon="mdi-tune">
                  參數調整
                </v-btn>
              </v-col>
            </v-row>

            <!-- 預測結果卡片 -->
            <v-row class="mb-4">
              <v-col cols="12" md="4">
                <v-card color="primary" dark>
                  <v-card-text>
                    <div class="text-subtitle-2">當前價格</div>
                    <div class="text-h4">${{ currentPrice }}</div>
                  </v-card-text>
                </v-card>
              </v-col>
              <v-col cols="12" md="4">
                <v-card :color="getPredictionColor()" dark>
                  <v-card-text>
                    <div class="text-subtitle-2">{{ predictionDays }}天預測價格</div>
                    <div class="text-h4">${{ predictedPrice }}</div>
                    <div class="text-caption">
                      {{ getPredictionChange() }}%
                      <v-icon>{{ getPredictionChange() >= 0 ? 'mdi-arrow-up' : 'mdi-arrow-down' }}</v-icon>
                    </div>
                  </v-card-text>
                </v-card>
              </v-col>
              <v-col cols="12" md="4">
                <v-card color="info" dark>
                  <v-card-text>
                    <div class="text-subtitle-2">預測準確度</div>
                    <div class="text-h4">{{ accuracy }}%</div>
                    <v-progress-linear
                      :model-value="accuracy"
                      color="white"
                      height="8"
                      class="mt-2"
                    ></v-progress-linear>
                  </v-card-text>
                </v-card>
              </v-col>
            </v-row>

            <!-- 預測走勢圖 -->
            <v-row>
              <v-col cols="12">
                <v-card outlined>
                  <v-card-title>
                    預測走勢圖
                    <v-spacer></v-spacer>
                    <v-btn-toggle v-model="chartType" mandatory divided density="compact">
                      <v-btn value="line">線圖</v-btn>
                      <v-btn value="candlestick">K線</v-btn>
                      <v-btn value="confidence">信賴區間</v-btn>
                    </v-btn-toggle>
                  </v-card-title>
                  <v-card-text>
                    <canvas ref="predictionChart" height="350"></canvas>
                  </v-card-text>
                </v-card>
              </v-col>
            </v-row>

            <!-- 模型指標 -->
            <v-row class="mt-4">
              <v-col cols="12" md="6">
                <v-card outlined>
                  <v-card-title>模型評估指標</v-card-title>
                  <v-card-text>
                    <v-list density="compact">
                      <v-list-item>
                        <template v-slot:prepend>
                          <v-icon color="primary">mdi-chart-line</v-icon>
                        </template>
                        <v-list-item-title>RMSE (均方根誤差)</v-list-item-title>
                        <template v-slot:append>
                          <strong>{{ metrics.rmse }}</strong>
                        </template>
                      </v-list-item>

                      <v-list-item>
                        <template v-slot:prepend>
                          <v-icon color="success">mdi-percent</v-icon>
                        </template>
                        <v-list-item-title>MAPE (平均絕對百分比誤差)</v-list-item-title>
                        <template v-slot:append>
                          <strong>{{ metrics.mape }}%</strong>
                        </template>
                      </v-list-item>

                      <v-list-item>
                        <template v-slot:prepend>
                          <v-icon color="warning">mdi-trending-up</v-icon>
                        </template>
                        <v-list-item-title>MAE (平均絕對誤差)</v-list-item-title>
                        <template v-slot:append>
                          <strong>{{ metrics.mae }}</strong>
                        </template>
                      </v-list-item>

                      <v-list-item>
                        <template v-slot:prepend>
                          <v-icon color="error">mdi-chart-bell-curve</v-icon>
                        </template>
                        <v-list-item-title>R² (決定係數)</v-list-item-title>
                        <template v-slot:append>
                          <strong>{{ metrics.r2 }}</strong>
                        </template>
                      </v-list-item>
                    </v-list>
                  </v-card-text>
                </v-card>
              </v-col>

              <v-col cols="12" md="6">
                <v-card outlined>
                  <v-card-title>預測區間</v-card-title>
                  <v-card-text>
                    <v-data-table
                      :headers="intervalHeaders"
                      :items="predictionIntervals"
                      :items-per-page="5"
                      density="compact"
                    >
                      <template v-slot:item.confidence="{ item }">
                        <v-chip size="small" color="primary">
                          {{ item.confidence }}%
                        </v-chip>
                      </template>
                    </v-data-table>
                  </v-card-text>
                </v-card>
              </v-col>
            </v-row>

            <!-- 特徵重要性 -->
            <v-row class="mt-4">
              <v-col cols="12">
                <v-card outlined>
                  <v-card-title>特徵重要性分析</v-card-title>
                  <v-card-text>
                    <canvas ref="featureImportanceChart" height="200"></canvas>
                  </v-card-text>
                </v-card>
              </v-col>
            </v-row>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- 模型比較 -->
    <v-row class="mt-4">
      <v-col cols="12">
        <v-card elevation="2">
          <v-card-title>
            <v-icon class="mr-2">mdi-compare</v-icon>
            模型比較
          </v-card-title>
          <v-card-text>
            <v-data-table
              :headers="comparisonHeaders"
              :items="modelComparison"
              :items-per-page="10"
              item-value="model"
            >
              <template v-slot:item.accuracy="{ item }">
                <v-chip :color="getAccuracyColor(item.accuracy)" size="small">
                  {{ item.accuracy }}%
                </v-chip>
              </template>

              <template v-slot:item.speed="{ item }">
                <v-rating
                  :model-value="item.speed"
                  color="yellow-darken-3"
                  density="compact"
                  size="small"
                  readonly
                ></v-rating>
              </template>

              <template v-slot:item.status="{ item }">
                <v-chip :color="item.status === '已訓練' ? 'success' : 'grey'" size="small">
                  {{ item.status }}
                </v-chip>
              </template>

              <template v-slot:item.actions="{ item }">
                <v-btn icon="mdi-play" size="small" variant="text" color="primary" @click="runModel(item)"></v-btn>
                <v-btn icon="mdi-cog" size="small" variant="text" @click="configureModel(item)"></v-btn>
              </template>
            </v-data-table>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>
  </div>
</template>

<script>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import Chart from 'chart.js/auto'

export default {
  name: 'PredictionAnalysis',
  setup() {
    // 狀態
    const symbol = ref('2330')
    const selectedModel = ref('LSTM')
    const predictionDays = ref(10)
    const trainingPeriod = ref(90)
    const chartType = ref('line')

    const models = ['LSTM', 'ARIMA', 'GARCH', 'Prophet', 'Random Forest']

    // 預測數據
    const currentPrice = ref(595)
    const predictedPrice = ref(612)
    const accuracy = ref(85)

    // 評估指標
    const metrics = ref({
      rmse: 8.23,
      mape: 2.45,
      mae: 6.78,
      r2: 0.92
    })

    // 圖表參考
    const predictionChart = ref(null)
    const featureImportanceChart = ref(null)
    let chartInstances = []

    // 表格標題
    const intervalHeaders = ref([
      { title: '信賴區間', key: 'confidence' },
      { title: '下界', key: 'lower' },
      { title: '預測值', key: 'predicted' },
      { title: '上界', key: 'upper' }
    ])

    const predictionIntervals = ref([
      { confidence: 99, lower: 585, predicted: 612, upper: 639 },
      { confidence: 95, lower: 592, predicted: 612, upper: 632 },
      { confidence: 90, lower: 596, predicted: 612, upper: 628 },
      { confidence: 80, lower: 600, predicted: 612, upper: 624 },
      { confidence: 68, lower: 604, predicted: 612, upper: 620 }
    ])

    const comparisonHeaders = ref([
      { title: '模型', key: 'model' },
      { title: '準確度', key: 'accuracy' },
      { title: 'RMSE', key: 'rmse' },
      { title: '訓練時間', key: 'trainingTime' },
      { title: '速度', key: 'speed' },
      { title: '狀態', key: 'status' },
      { title: '操作', key: 'actions', sortable: false }
    ])

    const modelComparison = ref([
      { model: 'LSTM', accuracy: 85, rmse: 8.23, trainingTime: '15分鐘', speed: 3, status: '已訓練' },
      { model: 'ARIMA', accuracy: 78, rmse: 12.45, trainingTime: '2分鐘', speed: 5, status: '已訓練' },
      { model: 'GARCH', accuracy: 72, rmse: 15.67, trainingTime: '3分鐘', speed: 4, status: '已訓練' },
      { model: 'Prophet', accuracy: 80, rmse: 10.89, trainingTime: '8分鐘', speed: 4, status: '已訓練' },
      { model: 'Random Forest', accuracy: 76, rmse: 13.21, trainingTime: '5分鐘', speed: 4, status: '未訓練' }
    ])

    // 計算屬性
    const getPredictionChange = () => {
      return (((predictedPrice.value - currentPrice.value) / currentPrice.value) * 100).toFixed(2)
    }

    const getPredictionColor = () => {
      const change = getPredictionChange()
      return change >= 0 ? 'success' : 'error'
    }

    const getAccuracyColor = (accuracy) => {
      if (accuracy >= 80) return 'success'
      if (accuracy >= 70) return 'warning'
      return 'error'
    }

    // 方法
    const runPrediction = () => {
      console.log('執行預測')
    }

    const runModel = (model) => {
      console.log('執行模型:', model)
    }

    const configureModel = (model) => {
      console.log('設定模型:', model)
    }

    const initCharts = () => {
      // Prediction Chart
      if (predictionChart.value) {
        const ctx = predictionChart.value.getContext('2d')
        const chart = new Chart(ctx, {
          type: 'line',
          data: {
            labels: Array.from({ length: 40 }, (_, i) => `Day ${i + 1}`),
            datasets: [
              {
                label: '歷史價格',
                data: Array.from({ length: 30 }, () => Math.random() * 50 + 570),
                borderColor: 'rgb(75, 192, 192)',
                backgroundColor: 'rgba(75, 192, 192, 0.2)',
                tension: 0.1
              },
              {
                label: '預測價格',
                data: Array.from({ length: 11 }, (_, i) => {
                  if (i === 0) return null
                  return 595 + (i * 1.7) + (Math.random() * 5 - 2.5)
                }),
                borderColor: 'rgb(255, 99, 132)',
                backgroundColor: 'rgba(255, 99, 132, 0.2)',
                borderDash: [5, 5],
                tension: 0.1
              }
            ]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
              mode: 'index',
              intersect: false
            }
          }
        })
        chartInstances.push(chart)
      }

      // Feature Importance Chart
      if (featureImportanceChart.value) {
        const ctx = featureImportanceChart.value.getContext('2d')
        const chart = new Chart(ctx, {
          type: 'bar',
          data: {
            labels: ['收盤價', '成交量', '波動率', '技術指標', 'RSI', 'MACD', '外部因素'],
            datasets: [{
              label: '重要性分數',
              data: [0.85, 0.72, 0.68, 0.55, 0.48, 0.42, 0.35],
              backgroundColor: [
                'rgba(255, 99, 132, 0.6)',
                'rgba(54, 162, 235, 0.6)',
                'rgba(255, 206, 86, 0.6)',
                'rgba(75, 192, 192, 0.6)',
                'rgba(153, 102, 255, 0.6)',
                'rgba(255, 159, 64, 0.6)',
                'rgba(199, 199, 199, 0.6)'
              ]
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y'
          }
        })
        chartInstances.push(chart)
      }
    }

    onMounted(() => {
      initCharts()
    })

    onUnmounted(() => {
      chartInstances.forEach(chart => chart.destroy())
    })

    return {
      symbol,
      selectedModel,
      predictionDays,
      trainingPeriod,
      chartType,
      models,
      currentPrice,
      predictedPrice,
      accuracy,
      metrics,
      predictionChart,
      featureImportanceChart,
      intervalHeaders,
      predictionIntervals,
      comparisonHeaders,
      modelComparison,
      getPredictionChange,
      getPredictionColor,
      getAccuracyColor,
      runPrediction,
      runModel,
      configureModel
    }
  }
}
</script>

<style scoped>
.prediction-page {
  padding: 16px;
}
</style>