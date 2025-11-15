<template>
  <div class="reports-page">
    <v-row>
      <v-col cols="12">
        <v-card elevation="2">
          <v-card-title>
            <v-icon class="mr-2">mdi-file-chart</v-icon>
            報表分析
            <v-spacer></v-spacer>
            <v-btn color="primary" prepend-icon="mdi-file-export" @click="exportReport">
              匯出報表
            </v-btn>
          </v-card-title>

          <v-card-text>
            <!-- 報表類型選擇 -->
            <v-row class="mb-4">
              <v-col cols="12" md="3">
                <v-select
                  v-model="reportType"
                  :items="reportTypes"
                  label="報表類型"
                  density="compact"
                  hide-details
                ></v-select>
              </v-col>
              <v-col cols="12" md="3">
                <v-select
                  v-model="period"
                  :items="periods"
                  label="時間區間"
                  density="compact"
                  hide-details
                ></v-select>
              </v-col>
              <v-col cols="12" md="2">
                <v-text-field
                  v-model="startDate"
                  label="開始日期"
                  type="date"
                  density="compact"
                  hide-details
                ></v-text-field>
              </v-col>
              <v-col cols="12" md="2">
                <v-text-field
                  v-model="endDate"
                  label="結束日期"
                  type="date"
                  density="compact"
                  hide-details
                ></v-text-field>
              </v-col>
              <v-col cols="12" md="2">
                <v-btn color="secondary" block @click="generateReport">
                  產生報表
                </v-btn>
              </v-col>
            </v-row>

            <!-- 報表摘要 -->
            <v-row class="mb-4">
              <v-col cols="12" md="3">
                <v-card color="primary" dark>
                  <v-card-text>
                    <div class="text-subtitle-2">總投資金額</div>
                    <div class="text-h5">${{ formatNumber(summary.totalInvestment) }}</div>
                  </v-card-text>
                </v-card>
              </v-col>
              <v-col cols="12" md="3">
                <v-card :color="summary.totalReturn >= 0 ? 'success' : 'error'" dark>
                  <v-card-text>
                    <div class="text-subtitle-2">總損益</div>
                    <div class="text-h5">
                      {{ summary.totalReturn >= 0 ? '+' : '' }}${{ formatNumber(Math.abs(summary.totalReturn)) }}
                    </div>
                  </v-card-text>
                </v-card>
              </v-col>
              <v-col cols="12" md="3">
                <v-card color="info" dark>
                  <v-card-text>
                    <div class="text-subtitle-2">報酬率</div>
                    <div class="text-h5">{{ summary.returnRate }}%</div>
                  </v-card-text>
                </v-card>
              </v-col>
              <v-col cols="12" md="3">
                <v-card color="warning" dark>
                  <v-card-text>
                    <div class="text-subtitle-2">交易次數</div>
                    <div class="text-h5">{{ summary.tradeCount }}</div>
                  </v-card-text>
                </v-card>
              </v-col>
            </v-row>

            <!-- 績效圖表 -->
            <v-row>
              <v-col cols="12" md="6">
                <v-card outlined>
                  <v-card-title>月度績效</v-card-title>
                  <v-card-text>
                    <canvas ref="monthlyPerformance" height="300"></canvas>
                  </v-card-text>
                </v-card>
              </v-col>

              <v-col cols="12" md="6">
                <v-card outlined>
                  <v-card-title>資產配置</v-card-title>
                  <v-card-text>
                    <canvas ref="assetAllocation" height="300"></canvas>
                  </v-card-text>
                </v-card>
              </v-col>
            </v-row>

            <!-- 詳細數據表格 -->
            <v-row class="mt-4">
              <v-col cols="12">
                <v-card outlined>
                  <v-card-title>
                    詳細交易記錄
                    <v-spacer></v-spacer>
                    <v-text-field
                      v-model="search"
                      append-inner-icon="mdi-magnify"
                      label="搜尋"
                      single-line
                      hide-details
                      density="compact"
                      style="max-width: 300px;"
                    ></v-text-field>
                  </v-card-title>
                  <v-card-text>
                    <v-data-table
                      :headers="tradeHeaders"
                      :items="tradeRecords"
                      :search="search"
                      :items-per-page="15"
                      item-value="id"
                    >
                      <template v-slot:item.type="{ item }">
                        <v-chip :color="item.type === '買入' ? 'success' : 'error'" size="small">
                          {{ item.type }}
                        </v-chip>
                      </template>

                      <template v-slot:item.pnl="{ item }">
                        <span :class="item.pnl >= 0 ? 'text-success' : 'text-error'">
                          {{ item.pnl >= 0 ? '+' : '' }}${{ formatNumber(Math.abs(item.pnl)) }}
                        </span>
                      </template>

                      <template v-slot:item.pnlPercent="{ item }">
                        <span :class="item.pnlPercent >= 0 ? 'text-success' : 'text-error'">
                          {{ item.pnlPercent >= 0 ? '+' : '' }}{{ item.pnlPercent }}%
                        </span>
                      </template>
                    </v-data-table>
                  </v-card-text>
                </v-card>
              </v-col>
            </v-row>

            <!-- 統計分析 -->
            <v-row class="mt-4">
              <v-col cols="12" md="6">
                <v-card outlined>
                  <v-card-title>交易統計</v-card-title>
                  <v-card-text>
                    <v-list density="compact">
                      <v-list-item>
                        <v-list-item-title>總交易次數</v-list-item-title>
                        <template v-slot:append>
                          <strong>{{ stats.totalTrades }}</strong>
                        </template>
                      </v-list-item>
                      <v-list-item>
                        <v-list-item-title>獲利交易</v-list-item-title>
                        <template v-slot:append>
                          <strong class="text-success">{{ stats.winningTrades }}</strong>
                        </template>
                      </v-list-item>
                      <v-list-item>
                        <v-list-item-title>虧損交易</v-list-item-title>
                        <template v-slot:append>
                          <strong class="text-error">{{ stats.losingTrades }}</strong>
                        </template>
                      </v-list-item>
                      <v-list-item>
                        <v-list-item-title>勝率</v-list-item-title>
                        <template v-slot:append>
                          <strong>{{ stats.winRate }}%</strong>
                        </template>
                      </v-list-item>
                      <v-list-item>
                        <v-list-item-title>平均獲利</v-list-item-title>
                        <template v-slot:append>
                          <strong class="text-success">${{ formatNumber(stats.avgWin) }}</strong>
                        </template>
                      </v-list-item>
                      <v-list-item>
                        <v-list-item-title>平均虧損</v-list-item-title>
                        <template v-slot:append>
                          <strong class="text-error">${{ formatNumber(stats.avgLoss) }}</strong>
                        </template>
                      </v-list-item>
                      <v-list-item>
                        <v-list-item-title>獲利因子</v-list-item-title>
                        <template v-slot:append>
                          <strong>{{ stats.profitFactor }}</strong>
                        </template>
                      </v-list-item>
                    </v-list>
                  </v-card-text>
                </v-card>
              </v-col>

              <v-col cols="12" md="6">
                <v-card outlined>
                  <v-card-title>持倉分析</v-card-title>
                  <v-card-text>
                    <v-list density="compact">
                      <v-list-item>
                        <v-list-item-title>當前持倉</v-list-item-title>
                        <template v-slot:append>
                          <strong>{{ positions.current }}</strong>
                        </template>
                      </v-list-item>
                      <v-list-item>
                        <v-list-item-title>平均持倉成本</v-list-item-title>
                        <template v-slot:append>
                          <strong>${{ formatNumber(positions.avgCost) }}</strong>
                        </template>
                      </v-list-item>
                      <v-list-item>
                        <v-list-item-title>當前市值</v-list-item-title>
                        <template v-slot:append>
                          <strong>${{ formatNumber(positions.currentValue) }}</strong>
                        </template>
                      </v-list-item>
                      <v-list-item>
                        <v-list-item-title>未實現損益</v-list-item-title>
                        <template v-slot:append>
                          <strong :class="positions.unrealizedPL >= 0 ? 'text-success' : 'text-error'">
                            {{ positions.unrealizedPL >= 0 ? '+' : '' }}${{ formatNumber(Math.abs(positions.unrealizedPL)) }}
                          </strong>
                        </template>
                      </v-list-item>
                      <v-list-item>
                        <v-list-item-title>已實現損益</v-list-item-title>
                        <template v-slot:append>
                          <strong :class="positions.realizedPL >= 0 ? 'text-success' : 'text-error'">
                            {{ positions.realizedPL >= 0 ? '+' : '' }}${{ formatNumber(Math.abs(positions.realizedPL)) }}
                          </strong>
                        </template>
                      </v-list-item>
                    </v-list>
                  </v-card-text>
                </v-card>
              </v-col>
            </v-row>
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
  name: 'Reports',
  setup() {
    // 狀態
    const reportType = ref('綜合報表')
    const period = ref('本月')
    const startDate = ref('2025-01-01')
    const endDate = ref('2025-11-15')
    const search = ref('')

    const reportTypes = ['綜合報表', '股票交易', '選擇權交易', '損益分析', '風險報告']
    const periods = ['今日', '本週', '本月', '本季', '本年', '自訂']

    // 報表摘要
    const summary = ref({
      totalInvestment: 1000000,
      totalReturn: 255000,
      returnRate: 25.5,
      tradeCount: 45
    })

    // 統計數據
    const stats = ref({
      totalTrades: 45,
      winningTrades: 28,
      losingTrades: 17,
      winRate: 62.2,
      avgWin: 15234,
      avgLoss: 8567,
      profitFactor: 1.78
    })

    const positions = ref({
      current: 12,
      avgCost: 850000,
      currentValue: 1105000,
      unrealizedPL: 255000,
      realizedPL: 125000
    })

    // 圖表
    const monthlyPerformance = ref(null)
    const assetAllocation = ref(null)
    let chartInstances = []

    // 表格標題
    const tradeHeaders = ref([
      { title: '日期', key: 'date' },
      { title: '股票代碼', key: 'symbol' },
      { title: '股票名稱', key: 'name' },
      { title: '類型', key: 'type' },
      { title: '數量', key: 'quantity' },
      { title: '價格', key: 'price' },
      { title: '金額', key: 'amount' },
      { title: '損益', key: 'pnl' },
      { title: '報酬率', key: 'pnlPercent' }
    ])

    const tradeRecords = ref([
      { id: 1, date: '2025-01-15', symbol: '2330', name: '台積電', type: '買入', quantity: 1000, price: 580, amount: 580000, pnl: 15000, pnlPercent: 2.59 },
      { id: 2, date: '2025-01-20', symbol: '2330', name: '台積電', type: '賣出', quantity: 1000, price: 595, amount: 595000, pnl: 15000, pnlPercent: 2.59 },
      { id: 3, date: '2025-02-01', symbol: '2317', name: '鴻海', type: '買入', quantity: 2000, price: 105, amount: 210000, pnl: 6000, pnlPercent: 2.86 },
      { id: 4, date: '2025-02-15', symbol: '2317', name: '鴻海', type: '賣出', quantity: 2000, price: 108, amount: 216000, pnl: 6000, pnlPercent: 2.86 }
    ])

    // 方法
    const formatNumber = (num) => {
      return num.toLocaleString('zh-TW')
    }

    const generateReport = () => {
      console.log('產生報表')
    }

    const exportReport = () => {
      console.log('匯出報表')
    }

    const initCharts = () => {
      // Monthly Performance Chart
      if (monthlyPerformance.value) {
        const ctx = monthlyPerformance.value.getContext('2d')
        const chart = new Chart(ctx, {
          type: 'bar',
          data: {
            labels: ['1月', '2月', '3月', '4月', '5月', '6月', '7月', '8月', '9月', '10月'],
            datasets: [{
              label: '月度損益',
              data: [25000, 35000, -12000, 45000, 28000, 52000, 18000, -8000, 42000, 35000],
              backgroundColor: function(context) {
                const value = context.parsed.y
                return value >= 0 ? 'rgba(75, 192, 192, 0.6)' : 'rgba(255, 99, 132, 0.6)'
              },
              borderColor: function(context) {
                const value = context.parsed.y
                return value >= 0 ? 'rgb(75, 192, 192)' : 'rgb(255, 99, 132)'
              },
              borderWidth: 1
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false
          }
        })
        chartInstances.push(chart)
      }

      // Asset Allocation Chart
      if (assetAllocation.value) {
        const ctx = assetAllocation.value.getContext('2d')
        const chart = new Chart(ctx, {
          type: 'doughnut',
          data: {
            labels: ['股票', '選擇權', '現金', '其他'],
            datasets: [{
              data: [60, 25, 10, 5],
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
      reportType,
      period,
      startDate,
      endDate,
      search,
      reportTypes,
      periods,
      summary,
      stats,
      positions,
      monthlyPerformance,
      assetAllocation,
      tradeHeaders,
      tradeRecords,
      formatNumber,
      generateReport,
      exportReport
    }
  }
}
</script>

<style scoped>
.reports-page {
  padding: 16px;
}
</style>