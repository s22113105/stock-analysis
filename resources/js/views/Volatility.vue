<template>
  <div class="volatility-page">
    <v-row>
      <v-col cols="12">
        <v-card elevation="2">
          <v-card-title>
            <v-icon class="mr-2">mdi-chart-bell-curve</v-icon>
            波動率分析
            <v-spacer></v-spacer>
            <v-btn color="primary" prepend-icon="mdi-refresh" @click="refreshData">
              更新資料
            </v-btn>
          </v-card-title>

          <v-card-text>
            <!-- 搜尋與參數設定 -->
            <v-row class="mb-4">
              <v-col cols="12" md="3">
                <v-text-field
                  v-model="symbol"
                  label="股票代碼"
                  density="compact"
                  hide-details
                  append-inner-icon="mdi-magnify"
                  @click:append-inner="searchVolatility"
                ></v-text-field>
              </v-col>
              <v-col cols="12" md="2">
                <v-select
                  v-model="period"
                  :items="periods"
                  label="計算期間"
                  density="compact"
                  hide-details
                ></v-select>
              </v-col>
              <v-col cols="12" md="2">
                <v-select
                  v-model="method"
                  :items="methods"
                  label="計算方法"
                  density="compact"
                  hide-details
                ></v-select>
              </v-col>
              <v-col cols="12" md="3">
                <v-select
                  v-model="volatilityType"
                  :items="['歷史波動率 (HV)', '隱含波動率 (IV)', 'GARCH 模型']"
                  label="波動率類型"
                  density="compact"
                  hide-details
                ></v-select>
              </v-col>
              <v-col cols="12" md="2">
                <v-btn color="secondary" block @click="calculateVolatility">
                  計算
                </v-btn>
              </v-col>
            </v-row>

            <!-- 波動率統計卡片 -->
            <v-row class="mb-4">
              <v-col cols="12" md="3">
                <v-card color="primary" dark>
                  <v-card-text>
                    <div class="text-subtitle-2">當前 HV</div>
                    <div class="text-h4">{{ currentHV }}%</div>
                    <div class="text-caption">{{ period }} 天歷史波動率</div>
                  </v-card-text>
                </v-card>
              </v-col>
              <v-col cols="12" md="3">
                <v-card color="success" dark>
                  <v-card-text>
                    <div class="text-subtitle-2">當前 IV</div>
                    <div class="text-h4">{{ currentIV }}%</div>
                    <div class="text-caption">選擇權隱含波動率</div>
                  </v-card-text>
                </v-card>
              </v-col>
              <v-col cols="12" md="3">
                <v-card :color="getIVHVRatioColor(ivHVRatio)" dark>
                  <v-card-text>
                    <div class="text-subtitle-2">IV / HV 比率</div>
                    <div class="text-h4">{{ ivHVRatio.toFixed(2) }}</div>
                    <div class="text-caption">{{ getIVHVRatioText(ivHVRatio) }}</div>
                  </v-card-text>
                </v-card>
              </v-col>
              <v-col cols="12" md="3">
                <v-card color="info" dark>
                  <v-card-text>
                    <div class="text-subtitle-2">波動率等級</div>
                    <div class="text-h4">{{ volatilityRank }}%</div>
                    <div class="text-caption">歷史百分位數</div>
                  </v-card-text>
                </v-card>
              </v-col>
            </v-row>

            <!-- 波動率走勢圖 -->
            <v-row>
              <v-col cols="12">
                <v-card outlined>
                  <v-card-title>波動率走勢圖</v-card-title>
                  <v-card-text>
                    <canvas ref="volatilityChart" height="300"></canvas>
                  </v-card-text>
                </v-card>
              </v-col>
            </v-row>

            <!-- 波動率錐形圖 -->
            <v-row class="mt-4">
              <v-col cols="12" md="6">
                <v-card outlined>
                  <v-card-title>波動率錐形圖 (Volatility Cone)</v-card-title>
                  <v-card-text>
                    <canvas ref="volatilityCone" height="300"></canvas>
                  </v-card-text>
                </v-card>
              </v-col>

              <!-- 波動率分布 -->
              <v-col cols="12" md="6">
                <v-card outlined>
                  <v-card-title>波動率分布直方圖</v-card-title>
                  <v-card-text>
                    <canvas ref="volatilityDistribution" height="300"></canvas>
                  </v-card-text>
                </v-card>
              </v-col>
            </v-row>

            <!-- 波動率統計表格 -->
            <v-row class="mt-4">
              <v-col cols="12">
                <v-card outlined>
                  <v-card-title>波動率統計數據</v-card-title>
                  <v-card-text>
                    <v-data-table
                      :headers="statsHeaders"
                      :items="volatilityStats"
                      :items-per-page="10"
                      item-value="period"
                    >
                      <template v-slot:item.current="{ item }">
                        <v-chip :color="getVolatilityColor(item.current)" size="small">
                          {{ item.current }}%
                        </v-chip>
                      </template>
                    </v-data-table>
                  </v-card-text>
                </v-card>
              </v-col>
            </v-row>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- 波動率事件與分析 -->
    <v-row class="mt-4">
      <v-col cols="12" md="6">
        <v-card elevation="2">
          <v-card-title>
            <v-icon class="mr-2">mdi-alert</v-icon>
            波動率事件
          </v-card-title>
          <v-card-text>
            <v-list>
              <v-list-item
                v-for="event in volatilityEvents"
                :key="event.id"
              >
                <template v-slot:prepend>
                  <v-icon :color="event.type === 'spike' ? 'error' : 'success'">
                    mdi-{{ event.type === 'spike' ? 'arrow-up-bold' : 'arrow-down-bold' }}
                  </v-icon>
                </template>
                <v-list-item-title>{{ event.title }}</v-list-item-title>
                <v-list-item-subtitle>
                  {{ event.date }} - 波動率 {{ event.type === 'spike' ? '飆升' : '下降' }} {{ event.change }}%
                </v-list-item-subtitle>
              </v-list-item>
            </v-list>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="6">
        <v-card elevation="2">
          <v-card-title>
            <v-icon class="mr-2">mdi-lightbulb</v-icon>
            交易建議
          </v-card-title>
          <v-card-text>
            <v-alert
              :type="getRecommendationType()"
              variant="tonal"
              prominent
            >
              <v-alert-title>{{ recommendation.title }}</v-alert-title>
              <div>{{ recommendation.description }}</div>
              <template v-slot:append>
                <v-btn @click="viewDetails">
                  詳情
                </v-btn>
              </template>
            </v-alert>
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
  name: 'Volatility',
  setup() {
    // 狀態
    const symbol = ref('2330')
    const period = ref(30)
    const method = ref('Close-to-Close')
    const volatilityType = ref('歷史波動率 (HV)')

    const periods = [10, 20, 30, 60, 90, 120, 252]
    const methods = ['Close-to-Close', 'Parkinson', 'Garman-Klass', 'Rogers-Satchell']

    // 波動率數據
    const currentHV = ref(24.5)
    const currentIV = ref(26.8)
    const volatilityRank = ref(65)

    // 圖表參考
    const volatilityChart = ref(null)
    const volatilityCone = ref(null)
    const volatilityDistribution = ref(null)
    let chartInstances = []

    // 統計表格
    const statsHeaders = ref([
      { title: '期間', key: 'period' },
      { title: '當前值', key: 'current' },
      { title: '最小值', key: 'min' },
      { title: '最大值', key: 'max' },
      { title: '平均值', key: 'mean' },
      { title: '中位數', key: 'median' },
      { title: '標準差', key: 'std' }
    ])

    const volatilityStats = ref([
      { period: '10天', current: 22.5, min: 15.2, max: 35.8, mean: 23.1, median: 22.8, std: 4.2 },
      { period: '20天', current: 23.8, min: 16.5, max: 34.2, mean: 24.3, median: 23.9, std: 3.8 },
      { period: '30天', current: 24.5, min: 17.8, max: 33.5, mean: 25.2, median: 24.8, std: 3.5 },
      { period: '60天', current: 25.2, min: 18.9, max: 32.8, mean: 26.1, median: 25.7, std: 3.2 },
      { period: '252天', current: 26.8, min: 20.5, max: 38.5, mean: 27.5, median: 27.2, std: 4.5 }
    ])

    const volatilityEvents = ref([
      { id: 1, title: '財報公布', date: '2025-10-15', type: 'spike', change: 15.5 },
      { id: 2, title: '除息交易', date: '2025-09-20', type: 'drop', change: 8.2 },
      { id: 3, title: '市場修正', date: '2025-08-10', type: 'spike', change: 22.3 }
    ])

    const recommendation = ref({
      title: 'IV 高於 HV - 考慮賣出策略',
      description: '當前隱含波動率(26.8%)高於歷史波動率(24.5%)，表示選擇權價格可能被高估。可以考慮賣出選擇權策略，如賣出 Covered Call 或 Cash-Secured Put。'
    })

    // 計算屬性
    const ivHVRatio = computed(() => currentIV.value / currentHV.value)

    // 方法
    const getIVHVRatioColor = (ratio) => {
      if (ratio < 0.9) return 'success'
      if (ratio > 1.1) return 'error'
      return 'warning'
    }

    const getIVHVRatioText = (ratio) => {
      if (ratio < 0.9) return 'IV 低估'
      if (ratio > 1.1) return 'IV 高估'
      return 'IV 合理'
    }

    const getVolatilityColor = (value) => {
      if (value < 20) return 'success'
      if (value < 30) return 'warning'
      return 'error'
    }

    const getRecommendationType = () => {
      const ratio = ivHVRatio.value
      if (ratio > 1.1) return 'warning'
      if (ratio < 0.9) return 'success'
      return 'info'
    }

    const searchVolatility = () => {
      console.log('搜尋波動率:', symbol.value)
    }

    const calculateVolatility = () => {
      console.log('計算波動率')
    }

    const refreshData = () => {
      console.log('更新波動率資料')
    }

    const viewDetails = () => {
      console.log('查看詳細建議')
    }

    const initCharts = () => {
      // Volatility Trend Chart
      if (volatilityChart.value) {
        const ctx = volatilityChart.value.getContext('2d')
        const chart = new Chart(ctx, {
          type: 'line',
          data: {
            labels: Array.from({ length: 30 }, (_, i) => `Day ${i + 1}`),
            datasets: [
              {
                label: 'HV 30天',
                data: Array.from({ length: 30 }, () => Math.random() * 10 + 20),
                borderColor: 'rgb(75, 192, 192)',
                backgroundColor: 'rgba(75, 192, 192, 0.2)',
                tension: 0.1
              },
              {
                label: 'IV',
                data: Array.from({ length: 30 }, () => Math.random() * 10 + 22),
                borderColor: 'rgb(255, 99, 132)',
                backgroundColor: 'rgba(255, 99, 132, 0.2)',
                tension: 0.1
              }
            ]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false
          }
        })
        chartInstances.push(chart)
      }

      // Volatility Cone
      if (volatilityCone.value) {
        const ctx = volatilityCone.value.getContext('2d')
        const chart = new Chart(ctx, {
          type: 'line',
          data: {
            labels: ['10天', '20天', '30天', '60天', '90天', '120天', '252天'],
            datasets: [
              {
                label: '最大值',
                data: [35, 34, 33, 32, 35, 36, 38],
                borderColor: 'rgb(255, 99, 132)',
                fill: false
              },
              {
                label: '75分位數',
                data: [30, 29, 28, 27, 29, 30, 32],
                borderColor: 'rgb(255, 206, 86)',
                fill: '-1'
              },
              {
                label: '中位數',
                data: [25, 24, 24, 24, 25, 26, 27],
                borderColor: 'rgb(75, 192, 192)',
                fill: '-1'
              },
              {
                label: '25分位數',
                data: [20, 19, 19, 20, 21, 22, 23],
                borderColor: 'rgb(54, 162, 235)',
                fill: '-1'
              },
              {
                label: '最小值',
                data: [15, 16, 17, 18, 19, 20, 20],
                borderColor: 'rgb(153, 102, 255)',
                fill: '-1'
              }
            ]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false
          }
        })
        chartInstances.push(chart)
      }

      // Volatility Distribution
      if (volatilityDistribution.value) {
        const ctx = volatilityDistribution.value.getContext('2d')
        const chart = new Chart(ctx, {
          type: 'bar',
          data: {
            labels: ['10-15%', '15-20%', '20-25%', '25-30%', '30-35%', '35-40%'],
            datasets: [{
              label: '頻率',
              data: [5, 15, 35, 30, 12, 3],
              backgroundColor: 'rgba(54, 162, 235, 0.6)'
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false
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
      period,
      method,
      volatilityType,
      periods,
      methods,
      currentHV,
      currentIV,
      volatilityRank,
      ivHVRatio,
      volatilityChart,
      volatilityCone,
      volatilityDistribution,
      statsHeaders,
      volatilityStats,
      volatilityEvents,
      recommendation,
      getIVHVRatioColor,
      getIVHVRatioText,
      getVolatilityColor,
      getRecommendationType,
      searchVolatility,
      calculateVolatility,
      refreshData,
      viewDetails
    }
  }
}
</script>

<style scoped>
.volatility-page {
  padding: 16px;
}
</style>