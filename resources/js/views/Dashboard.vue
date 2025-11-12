<template>
  <div class="dashboard">
    <h1 class="text-h4 mb-6">Stock_Analysis 儀表板</h1>

    <!-- 統計卡片 -->
    <v-row>
      <v-col cols="12" sm="6" md="3">
        <v-card>
          <v-card-text>
            <div class="d-flex align-center">
              <div class="flex-grow-1">
                <div class="text-subtitle-2 text-grey">總資產價值</div>
                <div class="text-h5 font-weight-bold">
                  NT$ {{ formatNumber(totalValue) }}
                </div>
                <div class="text-caption" :class="totalValueChange >= 0 ? 'text-success' : 'text-error'">
                  <v-icon small>{{ totalValueChange >= 0 ? 'mdi-trending-up' : 'mdi-trending-down' }}</v-icon>
                  {{ totalValueChange >= 0 ? '+' : '' }}{{ totalValueChange }}%
                </div>
              </div>
              <div>
                <v-icon size="40" color="primary">mdi-wallet</v-icon>
              </div>
            </div>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" sm="6" md="3">
        <v-card>
          <v-card-text>
            <div class="d-flex align-center">
              <div class="flex-grow-1">
                <div class="text-subtitle-2 text-grey">今日損益</div>
                <div class="text-h5 font-weight-bold" :class="todayPL >= 0 ? 'text-success' : 'text-error'">
                  {{ todayPL >= 0 ? '+' : '' }}NT$ {{ formatNumber(Math.abs(todayPL)) }}
                </div>
                <div class="text-caption">
                  {{ todayPLPercent >= 0 ? '+' : '' }}{{ todayPLPercent }}%
                </div>
              </div>
              <div>
                <v-icon size="40" :color="todayPL >= 0 ? 'success' : 'error'">
                  {{ todayPL >= 0 ? 'mdi-arrow-up-bold' : 'mdi-arrow-down-bold' }}
                </v-icon>
              </div>
            </div>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" sm="6" md="3">
        <v-card>
          <v-card-text>
            <div class="d-flex align-center">
              <div class="flex-grow-1">
                <div class="text-subtitle-2 text-grey">持倉數量</div>
                <div class="text-h5 font-weight-bold">{{ openPositions }}</div>
                <div class="text-caption">{{ pendingOrders }} 待成交</div>
              </div>
              <div>
                <v-icon size="40" color="info">mdi-briefcase</v-icon>
              </div>
            </div>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" sm="6" md="3">
        <v-card>
          <v-card-text>
            <div class="d-flex align-center">
              <div class="flex-grow-1">
                <div class="text-subtitle-2 text-grey">風險指標</div>
                <div class="text-h5 font-weight-bold">{{ riskScore }}/100</div>
                <v-progress-linear
                  :model-value="riskScore"
                  :color="getRiskColor(riskScore)"
                  height="4"
                  class="mt-2"
                ></v-progress-linear>
              </div>
              <div>
                <v-icon size="40" :color="getRiskColor(riskScore)">mdi-shield-alert</v-icon>
              </div>
            </div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- 圖表區域 -->
    <v-row class="mt-4">
      <v-col cols="12" md="8">
        <v-card>
          <v-card-title>投資組合表現</v-card-title>
          <v-card-text>
            <canvas ref="portfolioChart" height="300"></canvas>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="4">
        <v-card>
          <v-card-title>資產配置</v-card-title>
          <v-card-text>
            <canvas ref="allocationChart" height="300"></canvas>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- 持倉列表 -->
    <v-row class="mt-4">
      <v-col cols="12">
        <v-card>
          <v-card-title>
            <span>當前持倉</span>
            <v-spacer></v-spacer>
            <v-text-field
              v-model="search"
              append-icon="mdi-magnify"
              label="搜尋"
              single-line
              hide-details
              density="compact"
              style="max-width: 300px"
            ></v-text-field>
          </v-card-title>

          <v-card-text>
            <v-data-table
              :headers="positionHeaders"
              :items="positions"
              :search="search"
              :items-per-page="5"
              class="elevation-1"
            >
              <template v-slot:item.change="{ item }">
                <v-chip
                  :color="item.change >= 0 ? 'success' : 'error'"
                  small
                >
                  {{ item.change >= 0 ? '+' : '' }}{{ item.change }}%
                </v-chip>
              </template>

              <template v-slot:item.actions="{ item }">
                <v-btn icon small @click="viewDetails(item)">
                  <v-icon small>mdi-eye</v-icon>
                </v-btn>
                <v-btn icon small color="error" @click="closePosition(item)">
                  <v-icon small>mdi-close</v-icon>
                </v-btn>
              </template>
            </v-data-table>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- 市場新聞 -->
    <v-row class="mt-4">
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
                <template v-slot:prepend>
                  <v-icon :color="news.type === 'positive' ? 'success' : news.type === 'negative' ? 'error' : 'grey'">
                    mdi-newspaper
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
        <v-card>
          <v-card-title>即將到期選擇權</v-card-title>
          <v-card-text>
            <v-list>
              <v-list-item
                v-for="option in expiringOptions"
                :key="option.id"
              >
                <template v-slot:prepend>
                  <v-chip :color="option.type === 'call' ? 'green' : 'red'" small>
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
      { text: '股票代碼', value: 'symbol' },
      { text: '名稱', value: 'name' },
      { text: '持倉數量', value: 'quantity' },
      { text: '成本價', value: 'cost' },
      { text: '現價', value: 'current' },
      { text: '損益', value: 'pl' },
      { text: '漲跌幅', value: 'change' },
      { text: '操作', value: 'actions', sortable: false }
    ])

    // 模擬資料
    const positions = ref([
      {
        symbol: '2330',
        name: '台積電',
        quantity: 1000,
        cost: 580,
        current: 595,
        pl: 15000,
        change: 2.59
      },
      {
        symbol: '2317',
        name: '鴻海',
        quantity: 2000,
        cost: 105,
        current: 103,
        pl: -4000,
        change: -1.90
      },
      {
        symbol: '2454',
        name: '聯發科',
        quantity: 500,
        cost: 850,
        current: 880,
        pl: 15000,
        change: 3.53
      }
    ])

    const marketNews = ref([
      {
        id: 1,
        title: 'Fed 維持利率不變，市場反應正面',
        time: '10 分鐘前',
        type: 'positive'
      },
      {
        id: 2,
        title: '台積電10月營收創新高',
        time: '1 小時前',
        type: 'positive'
      },
      {
        id: 3,
        title: '外資連續賣超台股',
        time: '2 小時前',
        type: 'negative'
      }
    ])

    const expiringOptions = ref([
      {
        id: 1,
        symbol: 'TXO',
        strike: 18000,
        type: 'call',
        expiry: '2025-11-15',
        daysLeft: 3
      },
      {
        id: 2,
        symbol: 'TXO',
        strike: 17500,
        type: 'put',
        expiry: '2025-11-15',
        daysLeft: 3
      }
    ])

    // 方法
    const formatNumber = (num) => {
      return new Intl.NumberFormat('zh-TW').format(num)
    }

    const getRiskColor = (score) => {
      if (score < 30) return 'success'
      if (score < 60) return 'warning'
      return 'error'
    }

    const viewDetails = (item) => {
      console.log('查看詳情:', item)
    }

    const closePosition = (item) => {
      if (confirm(`確定要平倉 ${item.name} 嗎？`)) {
        console.log('平倉:', item)
      }
    }

    const openNews = (news) => {
      console.log('開啟新聞:', news)
    }

    // 初始化圖表
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
