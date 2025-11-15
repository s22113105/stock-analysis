<template>
  <div class="realtime-page">
    <!-- 連線狀態 -->
    <v-alert
      :type="connectionStatus === 'connected' ? 'success' : 'warning'"
      variant="tonal"
      class="mb-4"
    >
      <template v-slot:prepend>
        <v-icon>{{ connectionStatus === 'connected' ? 'mdi-wifi' : 'mdi-wifi-off' }}</v-icon>
      </template>
      {{ connectionStatus === 'connected' ? '即時連線中' : '連線中斷' }}
      <template v-slot:append>
        <v-chip size="small">
          最後更新: {{ lastUpdate }}
        </v-chip>
      </template>
    </v-alert>

    <v-row>
      <!-- 自選股票監控 -->
      <v-col cols="12" md="8">
        <v-card elevation="2">
          <v-card-title>
            <v-icon class="mr-2">mdi-monitor-eye</v-icon>
            自選股即時監控
            <v-spacer></v-spacer>
            <v-btn-toggle v-model="viewMode" mandatory divided density="compact">
              <v-btn value="table">表格</v-btn>
              <v-btn value="grid">卡片</v-btn>
            </v-btn-toggle>
          </v-card-title>

          <v-card-text>
            <!-- 表格視圖 -->
            <v-data-table
              v-if="viewMode === 'table'"
              :headers="headers"
              :items="watchlist"
              :items-per-page="10"
              item-value="symbol"
              density="compact"
            >
              <template v-slot:item.symbol="{ item }">
                <v-btn variant="text" color="primary">{{ item.symbol }}</v-btn>
              </template>

              <template v-slot:item.price="{ item }">
                <div :class="getPriceClass(item.change)">
                  ${{ item.price }}
                </div>
              </template>

              <template v-slot:item.change="{ item }">
                <v-chip :color="item.change >= 0 ? 'success' : 'error'" size="small">
                  <v-icon start>{{ item.change >= 0 ? 'mdi-arrow-up' : 'mdi-arrow-down' }}</v-icon>
                  {{ item.change >= 0 ? '+' : '' }}{{ item.change }}%
                </v-chip>
              </template>

              <template v-slot:item.volume="{ item }">
                <div class="d-flex align-center">
                  <span>{{ formatVolume(item.volume) }}</span>
                  <v-progress-linear
                    :model-value="(item.volume / item.avgVolume) * 100"
                    :color="item.volume > item.avgVolume ? 'success' : 'grey'"
                    height="4"
                    class="ml-2"
                    style="width: 50px;"
                  ></v-progress-linear>
                </div>
              </template>

              <template v-slot:item.actions="{ item }">
                <v-btn icon="mdi-chart-line" size="small" variant="text" @click="viewChart(item)"></v-btn>
                <v-btn icon="mdi-bell-ring" size="small" variant="text" @click="setAlert(item)"></v-btn>
                <v-btn icon="mdi-delete" size="small" variant="text" color="error" @click="removeFromWatchlist(item)"></v-btn>
              </template>
            </v-data-table>

            <!-- 卡片視圖 -->
            <v-row v-else>
              <v-col v-for="stock in watchlist" :key="stock.symbol" cols="12" md="6" lg="4">
                <v-card :class="{'pulse': stock.justUpdated}" outlined>
                  <v-card-text>
                    <div class="d-flex justify-space-between align-center">
                      <div>
                        <div class="text-h6">{{ stock.symbol }}</div>
                        <div class="text-caption text-grey">{{ stock.name }}</div>
                      </div>
                      <v-chip :color="stock.change >= 0 ? 'success' : 'error'">
                        {{ stock.change >= 0 ? '+' : '' }}{{ stock.change }}%
                      </v-chip>
                    </div>
                    <div class="text-h4 my-2" :class="getPriceClass(stock.change)">
                      ${{ stock.price }}
                    </div>
                    <v-divider class="my-2"></v-divider>
                    <div class="d-flex justify-space-between text-caption">
                      <span>成交量: {{ formatVolume(stock.volume) }}</span>
                      <span>時間: {{ stock.time }}</span>
                    </div>
                  </v-card-text>
                </v-card>
              </v-col>
            </v-row>
          </v-card-text>
        </v-card>
      </v-col>

      <!-- 右側面板 -->
      <v-col cols="12" md="4">
        <!-- 市場動態 -->
        <v-card elevation="2" class="mb-4">
          <v-card-title>
            <v-icon class="mr-2">mdi-view-dashboard-variant</v-icon>
            市場動態
          </v-card-title>
          <v-card-text>
            <v-list density="compact">
              <v-list-item>
                <v-list-item-title>加權指數</v-list-item-title>
                <template v-slot:append>
                  <div :class="marketIndex.change >= 0 ? 'text-success' : 'text-error'">
                    {{ marketIndex.value }}
                    <v-icon>{{ marketIndex.change >= 0 ? 'mdi-arrow-up' : 'mdi-arrow-down' }}</v-icon>
                    {{ Math.abs(marketIndex.change) }}%
                  </div>
                </template>
              </v-list-item>

              <v-list-item>
                <v-list-item-title>上漲家數</v-list-item-title>
                <template v-slot:append>
                  <span class="text-success">{{ marketStats.up }}</span>
                </template>
              </v-list-item>

              <v-list-item>
                <v-list-item-title>下跌家數</v-list-item-title>
                <template v-slot:append>
                  <span class="text-error">{{ marketStats.down }}</span>
                </template>
              </v-list-item>

              <v-list-item>
                <v-list-item-title>總成交量</v-list-item-title>
                <template v-slot:append>
                  <span>{{ formatVolume(marketStats.volume) }}</span>
                </template>
              </v-list-item>
            </v-list>
          </v-card-text>
        </v-card>

        <!-- 即時警示 -->
        <v-card elevation="2">
          <v-card-title>
            <v-icon class="mr-2">mdi-bell-alert</v-icon>
            即時警示
            <v-spacer></v-spacer>
            <v-badge :content="alerts.length" color="error">
              <v-icon>mdi-bell</v-icon>
            </v-badge>
          </v-card-title>
          <v-card-text>
            <v-list>
              <v-list-item
                v-for="alert in alerts"
                :key="alert.id"
                class="mb-2"
              >
                <template v-slot:prepend>
                  <v-icon :color="alert.type === 'danger' ? 'error' : 'warning'">
                    mdi-alert-{{ alert.type === 'danger' ? 'circle' : 'outline' }}
                  </v-icon>
                </template>
                <v-list-item-title>{{ alert.title }}</v-list-item-title>
                <v-list-item-subtitle>{{ alert.message }}</v-list-item-subtitle>
                <template v-slot:append>
                  <v-btn icon="mdi-close" size="x-small" variant="text" @click="dismissAlert(alert)"></v-btn>
                </template>
              </v-list-item>

              <v-list-item v-if="alerts.length === 0">
                <v-list-item-title class="text-center text-grey">
                  目前沒有警示
                </v-list-item-title>
              </v-list-item>
            </v-list>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- 即時走勢圖 -->
    <v-row class="mt-4">
      <v-col cols="12">
        <v-card elevation="2">
          <v-card-title>
            即時走勢圖
            <v-spacer></v-spacer>
            <v-btn-toggle v-model="chartInterval" mandatory divided density="compact">
              <v-btn value="1">1分</v-btn>
              <v-btn value="5">5分</v-btn>
              <v-btn value="15">15分</v-btn>
              <v-btn value="60">1時</v-btn>
            </v-btn-toggle>
          </v-card-title>
          <v-card-text>
            <canvas ref="realtimeChart" height="350"></canvas>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>
  </div>
</template>

<script>
import { ref, onMounted, onUnmounted } from 'vue'
import Chart from 'chart.js/auto'

export default {
  name: 'Realtime',
  setup() {
    // 狀態
    const connectionStatus = ref('connected')
    const lastUpdate = ref(new Date().toLocaleTimeString())
    const viewMode = ref('table')
    const chartInterval = ref('5')

    // 表格標題
    const headers = ref([
      { title: '代碼', key: 'symbol' },
      { title: '名稱', key: 'name' },
      { title: '價格', key: 'price' },
      { title: '漲跌幅', key: 'change' },
      { title: '成交量', key: 'volume' },
      { title: '更新時間', key: 'time' },
      { title: '操作', key: 'actions', sortable: false }
    ])

    // 自選股清單
    const watchlist = ref([
      { symbol: '2330', name: '台積電', price: 595.00, change: 2.59, volume: 25000000, avgVolume: 20000000, time: '13:30:00', justUpdated: false },
      { symbol: '2317', name: '鴻海', price: 108.50, change: 2.86, volume: 45000000, avgVolume: 35000000, time: '13:30:00', justUpdated: false },
      { symbol: '2454', name: '聯發科', price: 830.00, change: -2.35, volume: 8000000, avgVolume: 10000000, time: '13:30:00', justUpdated: false },
      { symbol: '2308', name: '台達電', price: 335.00, change: 4.69, volume: 12000000, avgVolume: 9000000, time: '13:30:00', justUpdated: false }
    ])

    // 市場資訊
    const marketIndex = ref({
      value: 18523,
      change: 1.25
    })

    const marketStats = ref({
      up: 845,
      down: 523,
      volume: 2850000000
    })

    // 警示
    const alerts = ref([
      { id: 1, type: 'danger', title: '2330 突破阻力位', message: '台積電價格突破 600 元阻力位' },
      { id: 2, type: 'warning', title: '2317 成交量異常', message: '鴻海成交量超過平均 120%' }
    ])

    // 圖表
    const realtimeChart = ref(null)
    let chartInstance = null
    let updateInterval = null

    // 方法
    const formatVolume = (volume) => {
      if (volume >= 100000000) {
        return (volume / 100000000).toFixed(2) + '億'
      } else if (volume >= 10000) {
        return (volume / 10000).toFixed(0) + '萬'
      }
      return volume.toLocaleString()
    }

    const getPriceClass = (change) => {
      if (change > 0) return 'text-success'
      if (change < 0) return 'text-error'
      return ''
    }

    const viewChart = (stock) => {
      console.log('查看走勢圖:', stock)
    }

    const setAlert = (stock) => {
      console.log('設定警示:', stock)
    }

    const removeFromWatchlist = (stock) => {
      const index = watchlist.value.findIndex(item => item.symbol === stock.symbol)
      if (index > -1) {
        watchlist.value.splice(index, 1)
      }
    }

    const dismissAlert = (alert) => {
      const index = alerts.value.findIndex(item => item.id === alert.id)
      if (index > -1) {
        alerts.value.splice(index, 1)
      }
    }

    const simulateRealtimeUpdate = () => {
      // 模擬即時更新
      watchlist.value.forEach(stock => {
        const priceChange = (Math.random() - 0.5) * 2
        stock.price = parseFloat((stock.price + priceChange).toFixed(2))
        stock.change = parseFloat(((stock.price / (stock.price - priceChange) - 1) * 100).toFixed(2))
        stock.time = new Date().toLocaleTimeString()
        stock.justUpdated = true
        setTimeout(() => {
          stock.justUpdated = false
        }, 500)
      })
      lastUpdate.value = new Date().toLocaleTimeString()
    }

    const initChart = () => {
      if (realtimeChart.value) {
        const ctx = realtimeChart.value.getContext('2d')
        const labels = Array.from({ length: 50 }, (_, i) => {
          const time = new Date()
          time.setMinutes(time.getMinutes() - (50 - i))
          return time.toLocaleTimeString('zh-TW', { hour: '2-digit', minute: '2-digit' })
        })

        chartInstance = new Chart(ctx, {
          type: 'line',
          data: {
            labels: labels,
            datasets: [{
              label: '台積電 (2330)',
              data: Array.from({ length: 50 }, () => 595 + (Math.random() - 0.5) * 10),
              borderColor: 'rgb(75, 192, 192)',
              backgroundColor: 'rgba(75, 192, 192, 0.1)',
              fill: true,
              tension: 0.1
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: {
              duration: 0
            },
            scales: {
              y: {
                beginAtZero: false
              }
            }
          }
        })
      }
    }

    const updateChart = () => {
      if (chartInstance) {
        const newLabel = new Date().toLocaleTimeString('zh-TW', { hour: '2-digit', minute: '2-digit' })
        const newData = chartInstance.data.datasets[0].data[chartInstance.data.datasets[0].data.length - 1] + (Math.random() - 0.5) * 2

        chartInstance.data.labels.push(newLabel)
        chartInstance.data.labels.shift()
        chartInstance.data.datasets[0].data.push(newData)
        chartInstance.data.datasets[0].data.shift()
        chartInstance.update()
      }
    }

    onMounted(() => {
      initChart()
      // 每 5 秒更新一次數據
      updateInterval = setInterval(() => {
        simulateRealtimeUpdate()
        updateChart()
      }, 5000)
    })

    onUnmounted(() => {
      if (chartInstance) chartInstance.destroy()
      if (updateInterval) clearInterval(updateInterval)
    })

    return {
      connectionStatus,
      lastUpdate,
      viewMode,
      chartInterval,
      headers,
      watchlist,
      marketIndex,
      marketStats,
      alerts,
      realtimeChart,
      formatVolume,
      getPriceClass,
      viewChart,
      setAlert,
      removeFromWatchlist,
      dismissAlert
    }
  }
}
</script>

<style scoped>
.realtime-page {
  padding: 16px;
}

.pulse {
  animation: pulse 0.5s;
}

@keyframes pulse {
  0% {
    box-shadow: 0 0 0 0 rgba(75, 192, 192, 0.7);
  }
  100% {
    box-shadow: 0 0 0 10px rgba(75, 192, 192, 0);
  }
}
</style>