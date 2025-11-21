<template>
  <div class="options-page">
    <!-- 頁面標題 -->
    <v-row class="mb-4">
      <v-col>
        <h1 class="text-h4">選擇權分析</h1>
        <p class="text-subtitle-1 text-grey">TXO 臺指選擇權市場分析</p>
      </v-col>
      <v-col cols="auto">
        <v-btn color="primary" @click="refreshAllData" :loading="loading">
          <v-icon left>mdi-refresh</v-icon>
          更新資料
        </v-btn>
      </v-col>
    </v-row>

    <!-- 市場情緒總覽卡片 -->
    <v-row class="mb-4">
      <v-col cols="12">
        <v-card elevation="2">
          <v-card-title class="d-flex align-center">
            <v-icon class="mr-2">mdi-heart-pulse</v-icon>
            市場情緒總覽
            <v-spacer></v-spacer>
            <v-chip v-if="sentiment" :color="sentiment.sentiment.color" size="large">
              {{ sentiment.sentiment.description }}
            </v-chip>
          </v-card-title>
          <v-card-text>
            <v-row v-if="sentiment">
              <!-- Put/Call Ratio -->
              <v-col cols="12" md="3">
                <v-card outlined class="pa-3 text-center">
                  <div class="text-caption text-grey mb-1">Put/Call 成交量比</div>
                  <div class="text-h5">{{ sentiment.put_call_volume_ratio }}</div>
                  <div class="text-caption">
                    <span :class="sentiment.put_call_volume_ratio > 1 ? 'text-error' : 'text-success'">
                      {{ sentiment.put_call_volume_ratio > 1 ? '偏空' : '偏多' }}
                    </span>
                  </div>
                </v-card>
              </v-col>

              <!-- 平均 IV -->
              <v-col cols="12" md="3">
                <v-card outlined class="pa-3 text-center">
                  <div class="text-caption text-grey mb-1">平均隱含波動率</div>
                  <div class="text-h5">{{ sentiment.avg_iv ? (sentiment.avg_iv * 100).toFixed(2) + '%' : 'N/A' }}</div>
                  <div class="text-caption">
                    <v-chip v-if="sentiment.iv_level" :color="sentiment.iv_level.color" size="small">
                      {{ sentiment.iv_level.description }}
                    </v-chip>
                  </div>
                </v-card>
              </v-col>

              <!-- 總成交量 -->
              <v-col cols="12" md="3">
                <v-card outlined class="pa-3 text-center">
                  <div class="text-caption text-grey mb-1">今日總成交量</div>
                  <div class="text-h5">{{ formatNumber(sentiment.total_volume) }}</div>
                  <div class="text-caption text-grey">口</div>
                </v-card>
              </v-col>

              <!-- 總未平倉量 -->
              <v-col cols="12" md="3">
                <v-card outlined class="pa-3 text-center">
                  <div class="text-caption text-grey mb-1">總未平倉量</div>
                  <div class="text-h5">{{ formatNumber(sentiment.total_oi) }}</div>
                  <div class="text-caption text-grey">口</div>
                </v-card>
              </v-col>
            </v-row>
            <v-row v-else>
              <v-col class="text-center py-8">
                <v-progress-circular indeterminate color="primary"></v-progress-circular>
                <p class="mt-2 text-grey">載入中...</p>
              </v-col>
            </v-row>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- 圖表區域 -->
    <v-row>
      <!-- TXO 走勢圖 -->
      <v-col cols="12" md="6">
        <v-card elevation="2">
          <v-card-title class="d-flex align-center">
            <v-icon class="mr-2">mdi-chart-line</v-icon>
            TXO 平均收盤價走勢
            <v-spacer></v-spacer>
            <v-btn-toggle v-model="trendPeriod" mandatory density="compact" @update:model-value="loadTrend">
              <v-btn value="7">7天</v-btn>
              <v-btn value="30">30天</v-btn>
              <v-btn value="90">90天</v-btn>
            </v-btn-toggle>
          </v-card-title>
          <v-card-text>
            <canvas ref="trendChart"></canvas>
          </v-card-text>
        </v-card>
      </v-col>

      <!-- 成交量分析 -->
      <v-col cols="12" md="6">
        <v-card elevation="2">
          <v-card-title class="d-flex align-center">
            <v-icon class="mr-2">mdi-chart-bar</v-icon>
            Call vs Put 成交量分析
          </v-card-title>
          <v-card-text>
            <canvas ref="volumeChart"></canvas>
          </v-card-text>
        </v-card>
      </v-col>

      <!-- IV 趨勢圖 -->
      <v-col cols="12" md="6">
        <v-card elevation="2">
          <v-card-title class="d-flex align-center">
            <v-icon class="mr-2">mdi-chart-timeline-variant</v-icon>
            隱含波動率趨勢
            <v-spacer></v-spacer>
            <v-btn-toggle v-model="ivPeriod" mandatory density="compact" @update:model-value="loadIvAnalysis">
              <v-btn value="7">7天</v-btn>
              <v-btn value="30">30天</v-btn>
              <v-btn value="90">90天</v-btn>
            </v-btn-toggle>
          </v-card-title>
          <v-card-text>
            <canvas ref="ivChart"></canvas>
          </v-card-text>
        </v-card>
      </v-col>

      <!-- OI 分佈圖 -->
      <v-col cols="12" md="6">
        <v-card elevation="2">
          <v-card-title class="d-flex align-center">
            <v-icon class="mr-2">mdi-chart-box-outline</v-icon>
            未平倉量分佈
          </v-card-title>
          <v-card-text>
            <canvas ref="oiChart"></canvas>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>
  </div>
</template>

<script>
import { ref, onMounted, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'
import Chart from 'chart.js/auto'

export default {
  name: 'Options',
  setup() {
    const router = useRouter()

    // 資料狀態
    const loading = ref(false)

    // 圖表實例
    const trendChart = ref(null)
    const volumeChart = ref(null)
    const ivChart = ref(null)
    const oiChart = ref(null)
    let trendChartInstance = null
    let volumeChartInstance = null
    let ivChartInstance = null
    let oiChartInstance = null

    // 圖表時間範圍
    const trendPeriod = ref('30')
    const ivPeriod = ref('30')

    // 分析數據
    const sentiment = ref(null)
    const trendData = ref(null)
    const volumeData = ref(null)
    const ivData = ref(null)
    const oiDistribution = ref(null)

    // API 基礎 URL
    const API_BASE_URL = '/api'

    // 載入市場情緒
    const loadSentiment = async () => {
      try {
        const response = await axios.get(`${API_BASE_URL}/options/txo/sentiment`)
        if (response.data.success) {
          sentiment.value = response.data.data
        }
      } catch (error) {
        console.error('載入市場情緒失敗:', error)
      }
    }

    // 載入走勢數據
    const loadTrend = async () => {
      try {
        const response = await axios.get(`${API_BASE_URL}/options/txo/trend`, {
          params: { days: trendPeriod.value }
        })
        if (response.data.success) {
          trendData.value = response.data.data
          await nextTick()
          renderTrendChart()
        }
      } catch (error) {
        console.error('載入走勢數據失敗:', error)
      }
    }

    // 載入成交量分析
    const loadVolumeAnalysis = async () => {
      try {
        const response = await axios.get(`${API_BASE_URL}/options/txo/volume-analysis`)
        if (response.data.success) {
          volumeData.value = response.data.data
          await nextTick()
          renderVolumeChart()
        }
      } catch (error) {
        console.error('載入成交量分析失敗:', error)
      }
    }

    // 載入 IV 分析
    const loadIvAnalysis = async () => {
      try {
        const response = await axios.get(`${API_BASE_URL}/options/txo/iv-analysis`, {
          params: { days: ivPeriod.value }
        })
        if (response.data.success) {
          ivData.value = response.data.data
          await nextTick()
          renderIvChart()
        }
      } catch (error) {
        console.error('載入 IV 分析失敗:', error)
      }
    }

    // 載入 OI 分佈
    const loadOiDistribution = async () => {
      try {
        const response = await axios.get(`${API_BASE_URL}/options/txo/oi-distribution`)
        if (response.data.success) {
          oiDistribution.value = response.data.data
          await nextTick()
          renderOiChart()
        }
      } catch (error) {
        console.error('載入 OI 分佈失敗:', error)
      }
    }

    // 渲染走勢圖
    const renderTrendChart = () => {
      if (!trendChart.value || !trendData.value) return

      const ctx = trendChart.value.getContext('2d')

      if (trendChartInstance) {
        trendChartInstance.destroy()
      }

      trendChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
          labels: trendData.value.map(d => d.date),
          datasets: [{
            label: 'TXO 平均收盤價',
            data: trendData.value.map(d => d.close),
            borderColor: 'rgb(75, 192, 192)',
            backgroundColor: 'rgba(75, 192, 192, 0.2)',
            tension: 0.1
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              display: true,
              position: 'top'
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

    // 渲染成交量圖
    const renderVolumeChart = () => {
      if (!volumeChart.value || !volumeData.value) return

      const ctx = volumeChart.value.getContext('2d')

      if (volumeChartInstance) {
        volumeChartInstance.destroy()
      }

      volumeChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
          labels: ['Call', 'Put'],
          datasets: [{
            label: '成交量',
            data: [volumeData.value.call.volume, volumeData.value.put.volume],
            backgroundColor: [
              'rgba(75, 192, 192, 0.6)',
              'rgba(255, 99, 132, 0.6)'
            ],
            borderColor: [
              'rgb(75, 192, 192)',
              'rgb(255, 99, 132)'
            ],
            borderWidth: 1
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              display: false
            }
          },
          scales: {
            y: {
              beginAtZero: true
            }
          }
        }
      })
    }

    // 渲染 IV 趨勢圖
    const renderIvChart = () => {
      if (!ivChart.value || !ivData.value) return

      const ctx = ivChart.value.getContext('2d')

      if (ivChartInstance) {
        ivChartInstance.destroy()
      }

      ivChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
          labels: ivData.value.map(d => d.date),
          datasets: [
            {
              label: 'Call IV',
              data: ivData.value.map(d => d.call_iv),
              borderColor: 'rgb(75, 192, 192)',
              backgroundColor: 'rgba(75, 192, 192, 0.2)',
              tension: 0.1
            },
            {
              label: 'Put IV',
              data: ivData.value.map(d => d.put_iv),
              borderColor: 'rgb(255, 99, 132)',
              backgroundColor: 'rgba(255, 99, 132, 0.2)',
              tension: 0.1
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              display: true,
              position: 'top'
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

    // 渲染 OI 分佈圖
    const renderOiChart = () => {
      if (!oiChart.value || !oiDistribution.value) return

      const ctx = oiChart.value.getContext('2d')

      if (oiChartInstance) {
        oiChartInstance.destroy()
      }

      oiChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
          labels: oiDistribution.value.map(d => d.strike_price),
          datasets: [
            {
              label: 'Call OI',
              data: oiDistribution.value.map(d => d.call_oi),
              backgroundColor: 'rgba(75, 192, 192, 0.6)',
              borderColor: 'rgb(75, 192, 192)',
              borderWidth: 1
            },
            {
              label: 'Put OI',
              data: oiDistribution.value.map(d => -d.put_oi),
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
              display: true,
              position: 'top'
            }
          },
          scales: {
            y: {
              ticks: {
                callback: function(value) {
                  return Math.abs(value).toLocaleString()
                }
              }
            }
          }
        }
      })
    }

    // 更新所有數據
    const refreshAllData = async () => {
      loading.value = true
      try {
        await Promise.all([
          loadSentiment(),
          loadTrend(),
          loadVolumeAnalysis(),
          loadIvAnalysis(),
          loadOiDistribution()
        ])
      } finally {
        loading.value = false
      }
    }

    // 工具函數
    const formatNumber = (num) => {
      return num ? num.toLocaleString('zh-TW') : '0'
    }

    // 生命週期
    onMounted(() => {
      refreshAllData()
    })

    return {
      loading,
      trendChart,
      volumeChart,
      ivChart,
      oiChart,
      trendPeriod,
      ivPeriod,
      sentiment,
      formatNumber,
      refreshAllData,
      loadTrend,
      loadIvAnalysis
    }
  }
}
</script>

<style scoped>
.options-page {
  padding: 16px;
}

canvas {
  max-height: 300px;
}
</style>
