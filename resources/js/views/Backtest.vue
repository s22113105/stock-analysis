<template>
  <div class="backtest-page">
    <v-row>
      <!-- 左側：策略設定 -->
      <v-col cols="12" md="4">
        <v-card elevation="2">
          <v-card-title>
            <v-icon class="mr-2">mdi-history</v-icon>
            策略回測設定
          </v-card-title>

          <v-card-text>
            <!-- 基本設定 -->
            <v-select
              v-model="strategy"
              :items="strategies"
              label="交易策略"
              density="compact"
              class="mb-4"
            ></v-select>

            <v-text-field
              v-model="symbol"
              label="標的代碼"
              density="compact"
              class="mb-4"
            ></v-text-field>

            <v-row>
              <v-col cols="6">
                <v-text-field
                  v-model="startDate"
                  label="開始日期"
                  type="date"
                  density="compact"
                ></v-text-field>
              </v-col>
              <v-col cols="6">
                <v-text-field
                  v-model="endDate"
                  label="結束日期"
                  type="date"
                  density="compact"
                ></v-text-field>
              </v-col>
            </v-row>

            <v-text-field
              v-model.number="initialCapital"
              label="初始資金"
              type="number"
              prefix="$"
              density="compact"
              class="mb-4"
            ></v-text-field>

            <v-text-field
              v-model.number="commission"
              label="手續費率"
              type="number"
              suffix="%"
              density="compact"
              class="mb-4"
            ></v-text-field>

            <v-divider class="my-4"></v-divider>

            <!-- 策略參數 -->
            <div class="text-h6 mb-3">策略參數</div>

            <v-text-field
              v-model.number="stopLoss"
              label="停損點"
              type="number"
              suffix="%"
              density="compact"
              class="mb-4"
            ></v-text-field>

            <v-text-field
              v-model.number="takeProfit"
              label="停利點"
              type="number"
              suffix="%"
              density="compact"
              class="mb-4"
            ></v-text-field>

            <v-text-field
              v-model.number="positionSize"
              label="部位大小"
              type="number"
              suffix="%"
              density="compact"
              class="mb-4"
            ></v-text-field>

            <v-btn
              color="primary"
              size="large"
              block
              prepend-icon="mdi-play"
              @click="runBacktest"
            >
              執行回測
            </v-btn>

            <v-btn
              color="secondary"
              size="large"
              block
              prepend-icon="mdi-content-save"
              class="mt-2"
              @click="saveStrategy"
            >
              儲存策略
            </v-btn>
          </v-card-text>
        </v-card>
      </v-col>

      <!-- 右側：回測結果 -->
      <v-col cols="12" md="8">
        <!-- 績效卡片 -->
        <v-row class="mb-4">
          <v-col cols="6" md="3">
            <v-card>
              <v-card-text>
                <div class="text-subtitle-2 text-grey">總報酬率</div>
                <div class="text-h5" :class="results.totalReturn >= 0 ? 'text-success' : 'text-error'">
                  {{ results.totalReturn >= 0 ? '+' : '' }}{{ results.totalReturn }}%
                </div>
              </v-card-text>
            </v-card>
          </v-col>
          <v-col cols="6" md="3">
            <v-card>
              <v-card-text>
                <div class="text-subtitle-2 text-grey">年化報酬率</div>
                <div class="text-h5" :class="results.annualReturn >= 0 ? 'text-success' : 'text-error'">
                  {{ results.annualReturn }}%
                </div>
              </v-card-text>
            </v-card>
          </v-col>
          <v-col cols="6" md="3">
            <v-card>
              <v-card-text>
                <div class="text-subtitle-2 text-grey">Sharpe Ratio</div>
                <div class="text-h5">{{ results.sharpeRatio }}</div>
              </v-card-text>
            </v-card>
          </v-col>
          <v-col cols="6" md="3">
            <v-card>
              <v-card-text>
                <div class="text-subtitle-2 text-grey">最大回撤</div>
                <div class="text-h5 text-error">{{ results.maxDrawdown }}%</div>
              </v-card-text>
            </v-card>
          </v-col>
        </v-row>

        <!-- 權益曲線圖 -->
        <v-card elevation="2" class="mb-4">
          <v-card-title>權益曲線</v-card-title>
          <v-card-text>
            <canvas ref="equityCurve" height="300"></canvas>
          </v-card-text>
        </v-card>

        <!-- 交易統計 -->
        <v-card elevation="2" class="mb-4">
          <v-card-title>交易統計</v-card-title>
          <v-card-text>
            <v-row>
              <v-col cols="6" md="3">
                <div class="text-subtitle-2 text-grey">總交易次數</div>
                <div class="text-h6">{{ results.totalTrades }}</div>
              </v-col>
              <v-col cols="6" md="3">
                <div class="text-subtitle-2 text-grey">獲利次數</div>
                <div class="text-h6 text-success">{{ results.winningTrades }}</div>
              </v-col>
              <v-col cols="6" md="3">
                <div class="text-subtitle-2 text-grey">虧損次數</div>
                <div class="text-h6 text-error">{{ results.losingTrades }}</div>
              </v-col>
              <v-col cols="6" md="3">
                <div class="text-subtitle-2 text-grey">勝率</div>
                <div class="text-h6">{{ results.winRate }}%</div>
              </v-col>
            </v-row>
            <v-row class="mt-4">
              <v-col cols="6" md="3">
                <div class="text-subtitle-2 text-grey">平均獲利</div>
                <div class="text-h6 text-success">${{ results.avgWin }}</div>
              </v-col>
              <v-col cols="6" md="3">
                <div class="text-subtitle-2 text-grey">平均虧損</div>
                <div class="text-h6 text-error">${{ results.avgLoss }}</div>
              </v-col>
              <v-col cols="6" md="3">
                <div class="text-subtitle-2 text-grey">獲利因子</div>
                <div class="text-h6">{{ results.profitFactor }}</div>
              </v-col>
              <v-col cols="6" md="3">
                <div class="text-subtitle-2 text-grey">平均持倉天數</div>
                <div class="text-h6">{{ results.avgHoldingDays }} 天</div>
              </v-col>
            </v-row>
          </v-card-text>
        </v-card>

        <!-- 交易明細 -->
        <v-card elevation="2">
          <v-card-title>
            交易明細
            <v-spacer></v-spacer>
            <v-btn icon="mdi-download" size="small" variant="text" @click="exportTrades"></v-btn>
          </v-card-title>
          <v-card-text>
            <v-data-table
              :headers="tradeHeaders"
              :items="trades"
              :items-per-page="10"
              item-value="id"
              density="compact"
            >
              <template v-slot:item.type="{ item }">
                <v-chip :color="item.type === 'Buy' ? 'success' : 'error'" size="small">
                  {{ item.type }}
                </v-chip>
              </template>

              <template v-slot:item.pnl="{ item }">
                <span :class="item.pnl >= 0 ? 'text-success' : 'text-error'">
                  {{ item.pnl >= 0 ? '+' : '' }}${{ Math.abs(item.pnl).toFixed(2) }}
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
  </div>
</template>

<script>
import { ref, onMounted, onUnmounted } from 'vue'
import Chart from 'chart.js/auto'

export default {
  name: 'Backtest',
  setup() {
    // 策略設定
    const strategy = ref('MA Cross')
    const symbol = ref('2330')
    const startDate = ref('2024-01-01')
    const endDate = ref('2025-11-15')
    const initialCapital = ref(1000000)
    const commission = ref(0.1425)
    const stopLoss = ref(5)
    const takeProfit = ref(10)
    const positionSize = ref(100)

    const strategies = [
      'MA Cross',
      'RSI Reversal',
      'Bollinger Bands',
      'MACD',
      'Mean Reversion',
      'Momentum',
      'Pairs Trading',
      'Options Strategy'
    ]

    // 回測結果
    const results = ref({
      totalReturn: 25.5,
      annualReturn: 18.2,
      sharpeRatio: 1.45,
      maxDrawdown: -12.3,
      totalTrades: 45,
      winningTrades: 28,
      losingTrades: 17,
      winRate: 62.2,
      avgWin: 15234,
      avgLoss: 8567,
      profitFactor: 1.78,
      avgHoldingDays: 12
    })

    // 圖表
    const equityCurve = ref(null)
    let chartInstance = null

    // 交易明細表格
    const tradeHeaders = ref([
      { title: '日期', key: 'date' },
      { title: '類型', key: 'type' },
      { title: '價格', key: 'price' },
      { title: '數量', key: 'quantity' },
      { title: '損益', key: 'pnl' },
      { title: '報酬率', key: 'pnlPercent' }
    ])

    const trades = ref([
      { id: 1, date: '2025-01-15', type: 'Buy', price: 580, quantity: 100, pnl: 1500, pnlPercent: 2.59 },
      { id: 2, date: '2025-01-20', type: 'Sell', price: 595, quantity: 100, pnl: 1500, pnlPercent: 2.59 },
      { id: 3, date: '2025-02-01', type: 'Buy', price: 590, quantity: 150, pnl: -750, pnlPercent: -1.27 },
      { id: 4, date: '2025-02-10', type: 'Sell', price: 585, quantity: 150, pnl: -750, pnlPercent: -0.85 }
    ])

    // 方法
    const runBacktest = () => {
      console.log('執行回測')
    }

    const saveStrategy = () => {
      console.log('儲存策略')
    }

    const exportTrades = () => {
      console.log('匯出交易明細')
    }

    const initChart = () => {
      if (equityCurve.value) {
        const ctx = equityCurve.value.getContext('2d')
        chartInstance = new Chart(ctx, {
          type: 'line',
          data: {
            labels: Array.from({ length: 100 }, (_, i) => `Day ${i + 1}`),
            datasets: [
              {
                label: '策略權益',
                data: Array.from({ length: 100 }, (_, i) => 1000000 + (i * 2500) + (Math.random() * 10000 - 5000)),
                borderColor: 'rgb(75, 192, 192)',
                backgroundColor: 'rgba(75, 192, 192, 0.1)',
                fill: true,
                tension: 0.1
              },
              {
                label: 'Buy & Hold',
                data: Array.from({ length: 100 }, (_, i) => 1000000 + (i * 1800)),
                borderColor: 'rgb(201, 203, 207)',
                backgroundColor: 'rgba(201, 203, 207, 0.1)',
                fill: true,
                tension: 0.1,
                borderDash: [5, 5]
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
      }
    }

    onMounted(() => {
      initChart()
    })

    onUnmounted(() => {
      if (chartInstance) chartInstance.destroy()
    })

    return {
      strategy,
      symbol,
      startDate,
      endDate,
      initialCapital,
      commission,
      stopLoss,
      takeProfit,
      positionSize,
      strategies,
      results,
      equityCurve,
      tradeHeaders,
      trades,
      runBacktest,
      saveStrategy,
      exportTrades
    }
  }
}
</script>

<style scoped>
.backtest-page {
  padding: 16px;
}
</style>