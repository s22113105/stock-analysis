<template>
  <div class="dashboard">
    <!-- 頁面標題 -->
    <v-row class="mb-4">
      <v-col>
        <h1 class="text-h4">儀表板</h1>
        <p class="text-subtitle-1 text-grey">即時市場概覽與交易分析</p>
      </v-col>
    </v-row>

    <!-- 統計卡片 -->
    <v-row class="mb-4">
      <v-col cols="12" md="3">
        <v-card>
          <v-card-text>
            <div class="d-flex align-center">
              <v-icon large color="primary" class="mr-3">mdi-trending-up</v-icon>
              <div>
                <p class="text-caption mb-0">總資產價值</p>
                <p class="text-h5 font-weight-bold">NT$ {{ formatNumber(totalValue) }}</p>
                <v-chip
                  :color="totalValueChange >= 0 ? 'success' : 'error'"
                  size="x-small"
                >
                  {{ totalValueChange >= 0 ? '+' : '' }}{{ totalValueChange }}%
                </v-chip>
              </div>
            </div>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="3">
        <v-card>
          <v-card-text>
            <div class="d-flex align-center">
              <v-icon large color="success" class="mr-3">mdi-cash</v-icon>
              <div>
                <p class="text-caption mb-0">今日損益</p>
                <p class="text-h5 font-weight-bold">NT$ {{ formatNumber(todayPL) }}</p>
                <v-chip
                  :color="todayPL >= 0 ? 'success' : 'error'"
                  size="x-small"
                >
                  {{ todayPLPercent }}%
                </v-chip>
              </div>
            </div>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="3">
        <v-card>
          <v-card-text>
            <div class="d-flex align-center">
              <v-icon large color="info" class="mr-3">mdi-file-document-multiple</v-icon>
              <div>
                <p class="text-caption mb-0">持倉部位</p>
                <p class="text-h5 font-weight-bold">{{ openPositions }}</p>
                <p class="text-caption text-grey">{{ pendingOrders }} 待成交</p>
              </div>
            </div>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="3">
        <v-card>
          <v-card-text>
            <div class="d-flex align-center">
              <v-icon large color="warning" class="mr-3">mdi-alert-circle</v-icon>
              <div>
                <p class="text-caption mb-0">風險值</p>
                <p class="text-h5 font-weight-bold">{{ riskScore }}/100</p>
                <v-progress-linear
                  :value="riskScore"
                  :color="getRiskColor(riskScore)"
                  height="4"
                  class="mt-2"
                ></v-progress-linear>
              </div>
            </div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- 圖表區域 -->
    <v-row class="mb-4">
      <v-col cols="12" md="8">
        <v-card>
          <v-card-title>投資組合表現</v-card-title>
          <v-card-text>
            <canvas ref="portfolioChart" height="100"></canvas>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="4">
        <v-card>
          <v-card-title>資產配置</v-card-title>
          <v-card-text>
            <canvas ref="allocationChart"></canvas>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- 持倉明細 -->
    <v-row class="mb-4">
      <v-col cols="12">
        <v-card>
          <v-card-title>
            <span>持倉明細</span>
            <v-spacer></v-spacer>
            <v-text-field
              v-model="search"
              append-icon="mdi-magnify"
              label="搜尋"
              single-line
              hide-details
              density="compact"
            ></v-text-field>
          </v-card-title>
          <v-card-text>
            <v-data-table
              :headers="positionHeaders"
              :items="positions"
              :search="search"
              :items-per-page="10"
            >
              <template v-slot:item.change="{ item }">
                <v-chip
                  :color="item.change >= 0 ? 'success' : 'error'"
                  size="small"
                >
                  {{ item.change >= 0 ? '+' : '' }}{{ item.change }}%
                </v-chip>
              </template>
              <template v-slot:item.actions="{ item }">
                <v-btn icon size="small" @click="viewDetails(item)">
                  <v-icon>mdi-eye</v-icon>
                </v-btn>
                <v-btn icon size="small" @click="closePosition(item)">
                  <v-icon>mdi-close</v-icon>
                </v-btn>
              </template>
            </v-data-table>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- 市場新聞 -->
    <v-row>
      <v-col cols="12" md="6">
        <v-card>
          <v-card-title>市場新聞</v-card-title>
          <v-card-text>
            <v-list>
              <v-list-item
                v-for="news in marketNews"
                :key="news.id"
                @click="openNews(news)"
              >
                <v-list-item-title>{{ news.title }}</v-list-item-title>
                <v-list-item-subtitle>{{ news.time }}</v-list-item-subtitle>
              </v-list-item>
            </v-list>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="6">
        <v-card>
          <v-card-title>即將到期選擇權</v-card-title>
          <v-card-text>
            <v-list>
              <v-list-item
                v-for="option in expiringOptions"
                :key="option.id"
              >
                <v-list-item-title>
                  {{ option.symbol }} {{ option.type }} ${{ option.strike }}
                </v-list-item-title>
                <v-list-item-subtitle>
                  到期日: {{ option.expiry }} ({{ option.daysLeft }} 天)
                </v-list-item-subtitle>
                <template v-slot:append>
                  <v-chip :color="option.status === 'ITM' ? 'success' : 'error'" size="small">
                    {{ option.status }}
                  </v-chip>
                </template>
              </v-list-item>
            </v-list>
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
  name: 'Dashboard',
  setup() {
    const portfolioChart = ref(null)
    const allocationChart = ref(null)
    let portfolioChartInstance = null
    let allocationChartInstance = null
    
    const search = ref('')
    const totalValue = ref(5280000)
    const totalValueChange = ref(2.8)
    const todayPL = ref(48500)
    const todayPLPercent = ref(0.92)
    const openPositions = ref(12)
    const pendingOrders = ref(3)
    const riskScore = ref(65)
    
    const positionHeaders = ref([
      { title: '代碼', key: 'symbol' },
      { title: '名稱', key: 'name' },
      { title: '類型', key: 'type' },
      { title: '數量', key: 'quantity' },
      { title: '成本', key: 'cost' },
      { title: '現價', key: 'currentPrice' },
      { title: '損益', key: 'pl' },
      { title: '漲跌', key: 'change' },
      { title: '操作', key: 'actions', sortable: false }
    ])
    
    const positions = ref([
      {
        id: 1,
        symbol: '2330',
        name: '台積電',
        type: '股票',
        quantity: 1000,
        cost: 580,
        currentPrice: 595,
        pl: 15000,
        change: 2.59
      },
      {
        id: 2,
        symbol: '2330C600',
        name: '台積電 Call 600',
        type: 'Call',
        quantity: 10,
        cost: 8.5,
        currentPrice: 12.3,
        pl: 3800,
        change: 44.71
      },
      {
        id: 3,
        symbol: '2454',
        name: '聯發科',
        type: '股票',
        quantity: 500,
        cost: 780,
        currentPrice: 765,
        pl: -7500,
        change: -1.92
      }
    ])
    
    const marketNews = ref([
      { id: 1, title: '美股三大指數收紅 科技股領漲', time: '10:30' },
      { id: 2, title: '台股早盤上漲百點 權值股強勢', time: '09:15' },
      { id: 3, title: 'Fed 暗示可能暫停升息', time: '08:45' },
      { id: 4, title: '外資買超台股 150 億元', time: '昨天' }
    ])
    
    const expiringOptions = ref([
      { id: 1, symbol: '2330', type: 'Call', strike: 600, expiry: '2025/01/17', daysLeft: 14, status: 'ITM' },
      { id: 2, symbol: '2454', type: 'Put', strike: 750, expiry: '2025/01/17', daysLeft: 14, status: 'OTM' },
      { id: 3, symbol: '2317', type: 'Call', strike: 110, expiry: '2025/01/24', daysLeft: 21, status: 'ITM' }
    ])
    
    const formatNumber = (num) => {
      return new Intl.NumberFormat('zh-TW').format(num)
    }
    
    const getRiskColor = (score) => {
      if (score < 30) return 'success'
      if (score < 70) return 'warning'
      return 'error'
    }
    
    const viewDetails = (item) => {
      console.log('View details:', item)
    }
    
    const closePosition = (item) => {
      console.log('Close position:', item)
    }
    
    const openNews = (news) => {
      console.log('Open news:', news)
    }
    
    const initCharts = () => {
      // Portfolio Performance Chart
      if (portfolioChart.value) {
        const ctx = portfolioChart.value.getContext('2d')
        portfolioChartInstance = new Chart(ctx, {
          type: 'line',
          data: {
            labels: ['1月', '2月', '3月', '4月', '5月', '6月'],
            datasets: [{
              label: '投資組合價值',
              data: [4800000, 4950000, 5100000, 4980000, 5150000, 5280000],
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
                display: false
              }
            }
          }
        })
      }
      
      // Allocation Chart
      if (allocationChart.value) {
        const ctx = allocationChart.value.getContext('2d')
        allocationChartInstance = new Chart(ctx, {
          type: 'doughnut',
          data: {
            labels: ['股票', '選擇權', '現金', '債券'],
            datasets: [{
              data: [60, 20, 15, 5],
              backgroundColor: [
                'rgba(54, 162, 235, 0.8)',
                'rgba(255, 99, 132, 0.8)',
                'rgba(75, 192, 192, 0.8)',
                'rgba(255, 206, 86, 0.8)'
              ]
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false
          }
        })
      }
    }
    
    onMounted(() => {
      initCharts()
    })
    
    onUnmounted(() => {
      if (portfolioChartInstance) portfolioChartInstance.destroy()
      if (allocationChartInstance) allocationChartInstance.destroy()
    })
    
    return {
      portfolioChart,
      allocationChart,
      search,
      totalValue,
      totalValueChange,
      todayPL,
      todayPLPercent,
      openPositions,
      pendingOrders,
      riskScore,
      positionHeaders,
      positions,
      marketNews,
      expiringOptions,
      formatNumber,
      getRiskColor,
      viewDetails,
      closePosition,
      openNews
    }
  }
}
</script>

<style scoped>
.dashboard {
  padding: 16px;
}

.v-card {
  border-radius: 8px;
}

.v-data-table {
  font-size: 14px;
}
</style>