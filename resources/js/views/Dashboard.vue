<template>
  <div class="dashboard">
    <!-- 統計卡片區 -->
    <v-row>
      <v-col cols="12" md="3">
        <v-card elevation="2">
          <v-card-text>
            <div class="text-h6 text-grey">投資組合總值</div>
            <div class="text-h4 mt-2">
              ${{ formatNumber(totalValue) }}
              <v-icon :color="totalValueChange >= 0 ? 'success' : 'error'" size="small">
                {{ totalValueChange >= 0 ? 'mdi-arrow-up' : 'mdi-arrow-down' }}
              </v-icon>
              <span :class="totalValueChange >= 0 ? 'text-success' : 'text-error'" class="text-body-1">
                {{ Math.abs(totalValueChange) }}%
              </span>
            </div>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="3">
        <v-card elevation="2">
          <v-card-text>
            <div class="text-h6 text-grey">今日損益</div>
            <div class="text-h4 mt-2" :class="todayPL >= 0 ? 'text-success' : 'text-error'">
              {{ todayPL >= 0 ? '+' : '' }}${{ formatNumber(Math.abs(todayPL)) }}
              <span class="text-body-1">
                ({{ todayPL >= 0 ? '+' : '' }}{{ todayPLPercent }}%)
              </span>
            </div>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="3">
        <v-card elevation="2">
          <v-card-text>
            <div class="text-h6 text-grey">持倉部位</div>
            <div class="text-h4 mt-2">
              {{ openPositions }}
              <span class="text-body-1 text-grey">個部位</span>
            </div>
            <div class="text-caption text-grey mt-1">
              掛單中: {{ pendingOrders }}
            </div>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="3">
        <v-card elevation="2">
          <v-card-text>
            <div class="text-h6 text-grey">風險評分</div>
            <div class="text-h4 mt-2">
              <v-chip :color="getRiskColor(riskScore)" size="large">
                {{ riskScore }}/100
              </v-chip>
            </div>
            <v-progress-linear
              :color="getRiskColor(riskScore)"
              :model-value="riskScore"
              height="8"
              class="mt-2"
            ></v-progress-linear>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- 圖表區 -->
    <v-row class="mt-4">
      <v-col cols="12" md="8">
        <v-card elevation="2">
          <v-card-title>投資組合走勢</v-card-title>
          <v-card-text>
            <canvas ref="portfolioChart" height="300"></canvas>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="4">
        <v-card elevation="2">
          <v-card-title>資產配置</v-card-title>
          <v-card-text>
            <canvas ref="allocationChart" height="300"></canvas>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- 持倉明細 -->
    <v-row class="mt-4">
      <v-col cols="12">
        <v-card elevation="2">
          <v-card-title>
            持倉明細
            <v-spacer></v-spacer>
            <v-text-field
              v-model="search"
              append-inner-icon="mdi-magnify"
              label="搜尋股票"
              single-line
              hide-details
              density="compact"
              style="max-width: 300px;"
            ></v-text-field>
          </v-card-title>
          <v-card-text>
            <v-data-table
              :headers="positionHeaders"
              :items="positions"
              :search="search"
              item-value="symbol"
              items-per-page="10"
            >
              <template v-slot:item.pl="{ item }">
                <span :class="item.pl >= 0 ? 'text-success' : 'text-error'">
                  {{ item.pl >= 0 ? '+' : '' }}${{ formatNumber(Math.abs(item.pl)) }}
                </span>
              </template>
              <template v-slot:item.change="{ item }">
                <v-chip :color="item.change >= 0 ? 'success' : 'error'" size="small">
                  {{ item.change >= 0 ? '+' : '' }}{{ item.change }}%
                </v-chip>
              </template>
              <template v-slot:item.actions="{ item }">
                <v-btn icon="mdi-eye" size="small" variant="text" @click="viewDetails(item)"></v-btn>
                <v-btn icon="mdi-close" size="small" variant="text" color="error" @click="closePosition(item)"></v-btn>
              </template>
            </v-data-table>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- 市場資訊與即將到期選擇權 -->
    <v-row class="mt-4">
      <v-col cols="12" md="6">
        <v-card elevation="2">
          <v-card-title>
            <v-icon>mdi-newspaper</v-icon>
            市場資訊
          </v-card-title>
          <v-card-text>
            <v-list>
              <v-list-item
                v-for="news in marketNews"
                :key="news.id"
                @click="openNews(news)"
              >
                <template v-slot:prepend>
                  <v-icon :color="news.sentiment === 'positive' ? 'success' : news.sentiment === 'negative' ? 'error' : 'grey'">
                    mdi-{{ news.sentiment === 'positive' ? 'trending-up' : news.sentiment === 'negative' ? 'trending-down' : 'minus' }}
                  </v-icon>
                </template>
                <v-list-item-title>{{ news.title }}</v-list-item-title>
                <v-list-item-subtitle>{{ news.time }}</v-list-item-subtitle>
              </v-list-item>
            </v-list>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="6">
        <v-card elevation="2">
          <v-card-title>
            <v-icon>mdi-calendar-alert</v-icon>
            即將到期選擇權
          </v-card-title>
          <v-card-text>
            <v-list>
              <v-list-item
                v-for="option in expiringOptions"
                :key="option.id"
              >
                <template v-slot:prepend>
                  <v-chip :color="option.type === 'call' ? 'green' : 'red'" size="small">
                    {{ option.type.toUpperCase() }}
                  </v-chip>
                </template>
                <v-list-item-title>
                  {{ option.symbol }} - 履約價 {{ option.strike }}
                </v-list-item-title>
                <v-list-item-subtitle>
                  到期日: {{ option.expiry }} (剩 {{ option.daysLeft }} 天)
                </v-list-item-subtitle>
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
    // 資料狀態
    const totalValue = ref(1250000)
    const totalValueChange = ref(2.5)
    const todayPL = ref(15000)
    const todayPLPercent = ref(1.2)
    const openPositions = ref(12)
    const pendingOrders = ref(3)
    const riskScore = ref(35)
    const search = ref('')

    // 圖表參考
    const portfolioChart = ref(null)
    const allocationChart = ref(null)
    let portfolioChartInstance = null
    let allocationChartInstance = null

    // 表格標題
    const positionHeaders = ref([
      { title: '股票代碼', key: 'symbol' },
      { title: '名稱', key: 'name' },
      { title: '持倉數量', key: 'quantity' },
      { title: '成本價', key: 'cost' },
      { title: '現價', key: 'current' },
      { title: '損益', key: 'pl' },
      { title: '漲跌幅', key: 'change' },
      { title: '操作', key: 'actions', sortable: false }
    ])

    // 模擬資料
    const positions = ref([
      { symbol: '2330', name: '台積電', quantity: 1000, cost: 580, current: 595, pl: 15000, change: 2.59 },
      { symbol: '2317', name: '鴻海', quantity: 2000, cost: 105, current: 108, pl: 6000, change: 2.86 },
      { symbol: '2454', name: '聯發科', quantity: 500, cost: 850, current: 830, pl: -10000, change: -2.35 },
      { symbol: '2308', name: '台達電', quantity: 800, cost: 320, current: 335, pl: 12000, change: 4.69 }
    ])

    const marketNews = ref([
      { id: 1, title: '台積電公布Q3財報優於預期', time: '2小時前', sentiment: 'positive' },
      { id: 2, title: '聯準會維持利率不變', time: '5小時前', sentiment: 'neutral' },
      { id: 3, title: '台股大盤突破萬八關卡', time: '1天前', sentiment: 'positive' }
    ])

    const expiringOptions = ref([
      { id: 1, symbol: '2330', type: 'call', strike: 600, expiry: '2025-11-20', daysLeft: 5 },
      { id: 2, symbol: '2317', type: 'put', strike: 100, expiry: '2025-11-27', daysLeft: 12 }
    ])

    // 方法
    const formatNumber = (num) => {
      return num.toLocaleString('zh-TW')
    }

    const getRiskColor = (score) => {
      if (score < 30) return 'success'
      if (score < 70) return 'warning'
      return 'error'
    }

    const viewDetails = (item) => {
      console.log('查看詳情:', item)
    }

    const closePosition = (item) => {
      console.log('平倉:', item)
    }

    const openNews = (news) => {
      console.log('開啟新聞:', news)
    }

    const initCharts = () => {
      // Portfolio Chart
      if (portfolioChart.value) {
        const ctx = portfolioChart.value.getContext('2d')
        portfolioChartInstance = new Chart(ctx, {
          type: 'line',
          data: {
            labels: ['1月', '2月', '3月', '4月', '5月', '6月'],
            datasets: [{
              label: '投資組合價值',
              data: [1000000, 1050000, 1100000, 1080000, 1150000, 1250000],
              borderColor: 'rgb(75, 192, 192)',
              backgroundColor: 'rgba(75, 192, 192, 0.2)',
              tension: 0.1
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false
          }
        })
      }

      // Allocation Chart
      if (allocationChart.value) {
        const ctx = allocationChart.value.getContext('2d')
        allocationChartInstance = new Chart(ctx, {
          type: 'doughnut',
          data: {
            labels: ['股票', '選擇權', '現金'],
            datasets: [{
              data: [60, 25, 15],
              backgroundColor: [
                'rgba(54, 162, 235, 0.8)',
                'rgba(255, 99, 132, 0.8)',
                'rgba(75, 192, 192, 0.8)'
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
      totalValue,
      totalValueChange,
      todayPL,
      todayPLPercent,
      openPositions,
      pendingOrders,
      riskScore,
      search,
      portfolioChart,
      allocationChart,
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
</style>