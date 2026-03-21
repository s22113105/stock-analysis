<template>
  <div class="backtest-page">
    <v-row>
      <!-- 左側:策略設定 -->
      <v-col cols="12" md="4">
        <v-card elevation="2">
          <v-card-title>策略設定</v-card-title>
          <v-card-text>
            <!-- 股票選擇 -->
            <v-autocomplete
              v-model="selectedStock"
              :items="stockList"
              item-title="label"
              item-value="id"
              label="選擇股票"
              prepend-icon="mdi-chart-line"
              density="compact"
              class="mb-4"
              :loading="loadingStocks"
              @update:modelValue="onStockChange"
            ></v-autocomplete>

            <!-- 策略選擇 -->
            <v-select
              v-model="strategy"
              :items="strategies"
              item-title="display_name"
              item-value="name"
              label="回測策略"
              prepend-icon="mdi-strategy"
              density="compact"
              class="mb-4"
              @update:modelValue="onStrategyChange"
            ></v-select>

            <!-- 日期範圍 -->
            <v-text-field
              v-model="startDate"
              label="開始日期"
              type="date"
              density="compact"
              prepend-icon="mdi-calendar-start"
              class="mb-4"
            ></v-text-field>

            <v-text-field
              v-model="endDate"
              label="結束日期"
              type="date"
              density="compact"
              prepend-icon="mdi-calendar-end"
              class="mb-4"
            ></v-text-field>

            <!-- 初始資金 -->
            <v-text-field
              v-model.number="initialCapital"
              label="初始資金"
              type="number"
              prefix="NT$"
              density="compact"
              class="mb-4"
            ></v-text-field>

            <!-- 策略參數 (動態顯示) -->
            <v-divider class="my-4"></v-divider>
            <div class="text-subtitle-2 mb-3">策略參數</div>

            <!-- SMA 參數 -->
            <template v-if="strategy === 'sma_crossover'">
              <v-text-field
                v-model.number="parameters.short_period"
                label="短期均線"
                type="number"
                suffix="天"
                density="compact"
                class="mb-3"
              ></v-text-field>
              <v-text-field
                v-model.number="parameters.long_period"
                label="長期均線"
                type="number"
                suffix="天"
                density="compact"
                class="mb-3"
              ></v-text-field>
            </template>

            <!-- MACD 參數 -->
            <template v-if="strategy === 'macd'">
              <v-text-field
                v-model.number="parameters.fast_period"
                label="快線期間"
                type="number"
                density="compact"
                class="mb-3"
              ></v-text-field>
              <v-text-field
                v-model.number="parameters.slow_period"
                label="慢線期間"
                type="number"
                density="compact"
                class="mb-3"
              ></v-text-field>
              <v-text-field
                v-model.number="parameters.signal_period"
                label="訊號線期間"
                type="number"
                density="compact"
                class="mb-3"
              ></v-text-field>
            </template>

            <!-- RSI 參數 -->
            <template v-if="strategy === 'rsi'">
              <v-text-field
                v-model.number="parameters.period"
                label="RSI 期間"
                type="number"
                density="compact"
                class="mb-3"
              ></v-text-field>
              <v-text-field
                v-model.number="parameters.oversold"
                label="超賣線"
                type="number"
                density="compact"
                class="mb-3"
              ></v-text-field>
              <v-text-field
                v-model.number="parameters.overbought"
                label="超買線"
                type="number"
                density="compact"
                class="mb-3"
              ></v-text-field>
            </template>

            <!-- 布林通道參數 -->
            <template v-if="strategy === 'bollinger_bands'">
              <v-text-field
                v-model.number="parameters.period"
                label="期間"
                type="number"
                suffix="天"
                density="compact"
                class="mb-3"
              ></v-text-field>
              <v-text-field
                v-model.number="parameters.std_dev"
                label="標準差倍數"
                type="number"
                step="0.1"
                density="compact"
                class="mb-3"
              ></v-text-field>
            </template>

            <!-- 執行按鈕 -->
            <v-btn
              color="primary"
              size="large"
              block
              prepend-icon="mdi-play"
              @click="runBacktest"
              :loading="running"
              :disabled="!selectedStock"
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
              :disabled="!backtestResults"
            >
              儲存策略
            </v-btn>
          </v-card-text>
        </v-card>
      </v-col>

      <!-- 右側:回測結果 -->
      <v-col cols="12" md="8">
        <!-- 績效卡片 -->
        <v-row class="mb-4" v-if="backtestResults">
          <v-col cols="6" md="3">
            <v-card>
              <v-card-text>
                <div class="text-subtitle-2 text-grey">總報酬率</div>
                <div class="text-h5" :class="backtestResults.total_return >= 0 ? 'text-success' : 'text-error'">
                  {{ backtestResults.total_return >= 0 ? '+' : '' }}{{ backtestResults.total_return }}%
                </div>
              </v-card-text>
            </v-card>
          </v-col>
          <v-col cols="6" md="3">
            <v-card>
              <v-card-text>
                <div class="text-subtitle-2 text-grey">年化報酬率</div>
                <div class="text-h5" :class="backtestResults.annual_return >= 0 ? 'text-success' : 'text-error'">
                  {{ backtestResults.annual_return }}%
                </div>
              </v-card-text>
            </v-card>
          </v-col>
          <v-col cols="6" md="3">
            <v-card>
              <v-card-text>
                <div class="text-subtitle-2 text-grey">Sharpe Ratio</div>
                <div class="text-h5">{{ backtestResults.sharpe_ratio }}</div>
              </v-card-text>
            </v-card>
          </v-col>
          <v-col cols="6" md="3">
            <v-card>
              <v-card-text>
                <div class="text-subtitle-2 text-grey">最大回撤</div>
                <div class="text-h5 text-error">{{ backtestResults.max_drawdown }}%</div>
              </v-card-text>
            </v-card>
          </v-col>
        </v-row>

        <!-- 📊 圖表 1: 權益曲線圖 -->
        <v-card elevation="2" class="mb-4" v-if="backtestResults">
          <v-card-title class="d-flex justify-space-between align-center">
            <span>📈 權益曲線</span>
            <v-btn-toggle v-model="chartView" mandatory density="compact">
              <v-btn value="equity" size="small">權益</v-btn>
              <v-btn value="drawdown" size="small">回撤</v-btn>
              <v-btn value="price" size="small">價格+交易</v-btn>
            </v-btn-toggle>
          </v-card-title>
          <v-card-text>
            <div style="position: relative; height: 350px;">
              <canvas ref="equityCurve" v-show="chartView === 'equity'"></canvas>
              <canvas ref="drawdownChart" v-show="chartView === 'drawdown'"></canvas>
              <canvas ref="priceChart" v-show="chartView === 'price'"></canvas>
            </div>
          </v-card-text>
        </v-card>

        <!-- 交易統計 -->
        <v-card elevation="2" class="mb-4" v-if="backtestResults">
          <v-card-title>交易統計</v-card-title>
          <v-card-text>
            <v-row>
              <v-col cols="6" md="3">
                <div class="text-caption text-grey">總交易次數</div>
                <div class="text-h6">{{ backtestResults.total_trades }}</div>
              </v-col>
              <v-col cols="6" md="3">
                <div class="text-caption text-grey">獲利次數</div>
                <div class="text-h6 text-success">{{ backtestResults.winning_trades }}</div>
              </v-col>
              <v-col cols="6" md="3">
                <div class="text-caption text-grey">虧損次數</div>
                <div class="text-h6 text-error">{{ backtestResults.losing_trades }}</div>
              </v-col>
              <v-col cols="6" md="3">
                <div class="text-caption text-grey">勝率</div>
                <div class="text-h6">{{ backtestResults.win_rate }}%</div>
              </v-col>
              <v-col cols="6" md="3">
                <div class="text-caption text-grey">平均獲利</div>
                <div class="text-h6 text-success">NT${{ backtestResults.avg_win || 0 }}</div>
              </v-col>
              <v-col cols="6" md="3">
                <div class="text-caption text-grey">平均虧損</div>
                <div class="text-h6 text-error">NT${{ backtestResults.avg_loss || 0 }}</div>
              </v-col>
              <v-col cols="6" md="3">
                <div class="text-caption text-grey">獲利因子</div>
                <div class="text-h6">{{ backtestResults.profit_factor || 0 }}</div>
              </v-col>
              <v-col cols="6" md="3">
                <div class="text-caption text-grey">策略波動率</div>
                <div class="text-h6">{{ (backtestResults.volatility * 100).toFixed(2) }}%</div>
              </v-col>
            </v-row>
          </v-card-text>
        </v-card>

        <!-- 交易明細表格 -->
        <v-card elevation="2" v-if="backtestResults && tradeHistory.length > 0">
          <v-card-title class="d-flex justify-space-between align-center">
            <span>交易明細</span>
            <v-btn
              size="small"
              prepend-icon="mdi-download"
              @click="exportTrades"
            >
              匯出 CSV
            </v-btn>
          </v-card-title>
          <v-card-text>
            <v-data-table
              :headers="tradeHeaders"
              :items="tradeHistory"
              items-per-page="10"
              class="elevation-0"
            >
              <template v-slot:item.action="{ item }">
                <v-chip :color="item.action === 'BUY' ? 'success' : 'error'" size="small">
                  {{ item.action }}
                </v-chip>
              </template>

              <template v-slot:item.pnl="{ item }">
                <span v-if="item.pnl !== null" :class="item.pnl >= 0 ? 'text-success' : 'text-error'">
                  {{ item.pnl >= 0 ? '+' : '' }}NT${{ Math.abs(item.pnl).toLocaleString() }}
                </span>
                <span v-else class="text-grey">-</span>
              </template>

              <template v-slot:item.pnl_percent="{ item }">
                <span v-if="item.pnl_percent !== null" :class="item.pnl_percent >= 0 ? 'text-success' : 'text-error'">
                  {{ item.pnl_percent >= 0 ? '+' : '' }}{{ item.pnl_percent }}%
                </span>
                <span v-else class="text-grey">-</span>
              </template>
            </v-data-table>
          </v-card-text>
        </v-card>

        <!-- 無結果提示 -->
        <v-card elevation="2" v-if="!backtestResults && !running">
          <v-card-text class="text-center py-12">
            <v-icon size="64" color="grey-lighten-1">mdi-chart-line-variant</v-icon>
            <div class="text-h6 mt-4 text-grey">請選擇股票和策略後執行回測</div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- 載入提示 -->
    <v-overlay :model-value="running" class="align-center justify-center">
      <v-card class="pa-8 text-center">
        <v-progress-circular
          indeterminate
          size="64"
          color="primary"
          class="mb-4"
        ></v-progress-circular>
        <div class="text-h6">正在執行回測...</div>
        <div class="text-caption text-grey mt-2">這可能需要幾秒鐘</div>
      </v-card>
    </v-overlay>
  </div>
</template>

<script>
import { ref, onMounted, onUnmounted, watch } from 'vue'
import { Chart, registerables } from 'chart.js'
import axios from 'axios'

Chart.register(...registerables)

export default {
  name: 'Backtest',
  setup() {
    // 狀態
    const running = ref(false)
    const loadingStocks = ref(false)
    const selectedStock = ref(null)
    const strategy = ref('sma_crossover')
    const startDate = ref('2024-01-01')
    const endDate = ref('2025-11-15')
    const initialCapital = ref(1000000)
    const chartView = ref('equity')

    // 策略參數 (預設值)
    const parameters = ref({
      short_period: 20,
      long_period: 50,
      fast_period: 12,
      slow_period: 26,
      signal_period: 9,
      period: 14,
      oversold: 30,
      overbought: 70,
      std_dev: 2.0
    })

    // 資料
    const stockList = ref([])
    const strategies = ref([])
    const backtestResults = ref(null)
    const tradeHistory = ref([])

    // 圖表引用
    const equityCurve = ref(null)
    const drawdownChart = ref(null)
    const priceChart = ref(null)

    let equityChartInstance = null
    let drawdownChartInstance = null
    let priceChartInstance = null

    // 交易明細表頭
    const tradeHeaders = ref([
      { title: '日期', key: 'date', width: '120px' },
      { title: '動作', key: 'action', width: '80px' },
      { title: '價格', key: 'price', width: '100px' },
      { title: '數量', key: 'quantity', width: '80px' },
      { title: '損益', key: 'pnl', width: '120px' },
      { title: '報酬率', key: 'pnl_percent', width: '100px' }
    ])

    // 載入股票列表
    const loadStocks = async () => {
      loadingStocks.value = true
      try {
        const response = await axios.get('/stocks', {
          params: { per_page: 100, active_only: true }
        })
        stockList.value = response.data.data.data.map(stock => ({
          id: stock.id,
          label: `${stock.symbol} - ${stock.name}`,
          symbol: stock.symbol,
          name: stock.name
        }))
      } catch (error) {
        console.error('載入股票列表失敗:', error)
        // API 失敗時保持空陣列，讓使用者看到錯誤提示而非假資料
        stockList.value = []
        stockLoadError.value = '無法載入股票清單，請確認伺服器狀態後重新整理'
      } finally {
        loadingStocks.value = false
      }
    }

    // 載入策略列表
    const loadStrategies = async () => {
      try {
        const response = await axios.get('/backtest/strategies')
        strategies.value = response.data.data
      } catch (error) {
        console.error('載入策略列表失敗:', error)
        // API 失敗時保持空陣列，strategy select 會呈現空白
        strategies.value = []
        strategyLoadError.value = '無法載入策略清單，請確認伺服器狀態後重新整理'
      }
    }

    // 執行回測
    const runBacktest = async () => {
      if (!selectedStock.value) {
        alert('請選擇股票')
        return
      }

      running.value = true
      try {
        const response = await axios.post('/backtest/run', {
          stock_id: selectedStock.value,
          strategy_name: strategy.value,
          start_date: startDate.value,
          end_date: endDate.value,
          initial_capital: initialCapital.value,
          parameters: getStrategyParameters()
        })

        if (response.data.success) {
          backtestResults.value = response.data.data.results
          tradeHistory.value = parseTradeHistory(response.data.data.results.trade_history)

          // 初始化圖表
          setTimeout(() => {
            initEquityChart()
            initDrawdownChart()
            initPriceChart()
          }, 100)
        } else {
          alert('回測失敗: ' + response.data.message)
        }
      } catch (error) {
        console.error('回測失敗:', error)
        alert('回測失敗: ' + (error.response?.data?.message || error.message))
      } finally {
        running.value = false
      }
    }

    // 取得當前策略的參數
    const getStrategyParameters = () => {
      const params = {}

      switch(strategy.value) {
        case 'sma_crossover':
          params.short_period = parameters.value.short_period
          params.long_period = parameters.value.long_period
          break
        case 'macd':
          params.fast_period = parameters.value.fast_period
          params.slow_period = parameters.value.slow_period
          params.signal_period = parameters.value.signal_period
          break
        case 'rsi':
          params.period = parameters.value.period
          params.oversold = parameters.value.oversold
          params.overbought = parameters.value.overbought
          break
        case 'bollinger_bands':
          params.period = parameters.value.period
          params.std_dev = parameters.value.std_dev
          break
      }

      return params
    }

    // 解析交易歷史
    const parseTradeHistory = (tradeHistoryJson) => {
      if (!tradeHistoryJson) return []

      try {
        const trades = typeof tradeHistoryJson === 'string'
          ? JSON.parse(tradeHistoryJson)
          : tradeHistoryJson

        return trades.map((trade, index) => ({
          id: index + 1,
          date: trade.date,
          action: trade.action,
          price: parseFloat(trade.price).toFixed(2),
          quantity: trade.quantity,
          pnl: trade.pnl !== undefined ? parseFloat(trade.pnl) : null,
          pnl_percent: trade.pnl_percent !== undefined ? parseFloat(trade.pnl_percent).toFixed(2) : null
        }))
      } catch (error) {
        console.error('解析交易歷史失敗:', error)
        return []
      }
    }

    // 📊 初始化權益曲線圖
    const initEquityChart = () => {
      if (!equityCurve.value || !backtestResults.value) return

      const equity_curve = typeof backtestResults.value.equity_curve === 'string'
        ? JSON.parse(backtestResults.value.equity_curve)
        : backtestResults.value.equity_curve

      const ctx = equityCurve.value.getContext('2d')

      if (equityChartInstance) {
        equityChartInstance.destroy()
      }

      // 計算 Buy & Hold 基準線
      const initialPrice = equity_curve[0]?.equity || initialCapital.value
      const finalPrice = equity_curve[equity_curve.length - 1]?.equity || initialCapital.value
      const buyHoldReturn = (finalPrice - initialPrice) / initialPrice
      const buyHoldData = equity_curve.map((point, index) => {
        return initialCapital.value * (1 + buyHoldReturn * index / equity_curve.length)
      })

      equityChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
          labels: equity_curve.map(point => point.date),
          datasets: [
            {
              label: '策略權益',
              data: equity_curve.map(point => point.equity),
              borderColor: 'rgb(75, 192, 192)',
              backgroundColor: 'rgba(75, 192, 192, 0.1)',
              fill: true,
              tension: 0.1,
              borderWidth: 2
            },
            {
              label: 'Buy & Hold',
              data: buyHoldData,
              borderColor: 'rgb(201, 203, 207)',
              backgroundColor: 'rgba(201, 203, 207, 0.1)',
              fill: false,
              tension: 0.1,
              borderDash: [5, 5],
              borderWidth: 1
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: {
            mode: 'index',
            intersect: false
          },
          plugins: {
            legend: {
              position: 'top'
            },
            tooltip: {
              callbacks: {
                label: function(context) {
                  return context.dataset.label + ': NT$' + context.parsed.y.toLocaleString()
                }
              }
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
                text: '權益 (NT$)'
              },
              ticks: {
                callback: function(value) {
                  return 'NT$' + value.toLocaleString()
                }
              }
            }
          }
        }
      })
    }

    // 📊 初始化回撤曲線圖
    const initDrawdownChart = () => {
      if (!drawdownChart.value || !backtestResults.value) return

      const equity_curve = typeof backtestResults.value.equity_curve === 'string'
        ? JSON.parse(backtestResults.value.equity_curve)
        : backtestResults.value.equity_curve

      // 計算回撤
      const drawdowns = []
      let peak = equity_curve[0]?.equity || initialCapital.value

      equity_curve.forEach(point => {
        if (point.equity > peak) {
          peak = point.equity
        }
        const drawdown = ((point.equity - peak) / peak) * 100
        drawdowns.push(drawdown)
      })

      const ctx = drawdownChart.value.getContext('2d')

      if (drawdownChartInstance) {
        drawdownChartInstance.destroy()
      }

      drawdownChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
          labels: equity_curve.map(point => point.date),
          datasets: [
            {
              label: '回撤 (%)',
              data: drawdowns,
              borderColor: 'rgb(255, 99, 132)',
              backgroundColor: 'rgba(255, 99, 132, 0.2)',
              fill: true,
              tension: 0.1,
              borderWidth: 2
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: {
            mode: 'index',
            intersect: false
          },
          plugins: {
            legend: {
              position: 'top'
            },
            tooltip: {
              callbacks: {
                label: function(context) {
                  return '回撤: ' + context.parsed.y.toFixed(2) + '%'
                }
              }
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
                text: '回撤 (%)'
              },
              max: 0,
              ticks: {
                callback: function(value) {
                  return value.toFixed(1) + '%'
                }
              }
            }
          }
        }
      })
    }

    // 📊 初始化價格+交易點位圖
    const initPriceChart = () => {
      if (!priceChart.value || !backtestResults.value || !tradeHistory.value.length) return

      // 從交易歷史提取價格數據
      const prices = tradeHistory.value.map(trade => ({
        date: trade.date,
        price: parseFloat(trade.price)
      }))

      // 分離買入和賣出點
      const buyPoints = []
      const sellPoints = []

      tradeHistory.value.forEach((trade, index) => {
        if (trade.action === 'BUY') {
          buyPoints.push({ x: index, y: parseFloat(trade.price) })
        } else if (trade.action === 'SELL') {
          sellPoints.push({ x: index, y: parseFloat(trade.price) })
        }
      })

      const ctx = priceChart.value.getContext('2d')

      if (priceChartInstance) {
        priceChartInstance.destroy()
      }

      priceChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
          labels: prices.map(p => p.date),
          datasets: [
            {
              label: '股價',
              data: prices.map(p => p.price),
              borderColor: 'rgb(54, 162, 235)',
              backgroundColor: 'rgba(54, 162, 235, 0.1)',
              fill: true,
              tension: 0.2,
              borderWidth: 2,
              pointRadius: 0
            },
            {
              label: '買入',
              data: buyPoints,
              backgroundColor: 'rgb(75, 192, 192)',
              borderColor: 'rgb(75, 192, 192)',
              pointStyle: 'triangle',
              pointRadius: 8,
              pointHoverRadius: 10,
              showLine: false
            },
            {
              label: '賣出',
              data: sellPoints,
              backgroundColor: 'rgb(255, 99, 132)',
              borderColor: 'rgb(255, 99, 132)',
              pointStyle: 'triangle',
              pointRotation: 180,
              pointRadius: 8,
              pointHoverRadius: 10,
              showLine: false
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: {
            mode: 'index',
            intersect: false
          },
          plugins: {
            legend: {
              position: 'top'
            },
            tooltip: {
              callbacks: {
                label: function(context) {
                  if (context.dataset.label === '股價') {
                    return '價格: NT$' + context.parsed.y.toFixed(2)
                  }
                  return context.dataset.label + ': NT$' + context.parsed.y.toFixed(2)
                }
              }
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
                text: '價格 (NT$)'
              },
              ticks: {
                callback: function(value) {
                  return 'NT$' + value.toFixed(0)
                }
              }
            }
          }
        }
      })
    }

    // 儲存策略
    const saveStrategy = () => {
      if (!backtestResults.value) {
        alert('請先執行回測')
        return
      }

      console.log('儲存策略:', {
        stock: selectedStock.value,
        strategy: strategy.value,
        parameters: getStrategyParameters(),
        results: backtestResults.value
      })

      alert('策略已儲存(功能開發中)')
    }

    // 匯出交易明細
    const exportTrades = () => {
      if (!tradeHistory.value.length) return

      // 轉換為 CSV
      const headers = ['日期', '動作', '價格', '數量', '損益', '報酬率']
      const rows = tradeHistory.value.map(trade => [
        trade.date,
        trade.action,
        trade.price,
        trade.quantity,
        trade.pnl || '',
        trade.pnl_percent ? trade.pnl_percent + '%' : ''
      ])

      const csvContent = [
        headers.join(','),
        ...rows.map(row => row.join(','))
      ].join('\n')

      // 下載檔案
      const blob = new Blob(['\ufeff' + csvContent], { type: 'text/csv;charset=utf-8;' })
      const link = document.createElement('a')
      link.href = URL.createObjectURL(blob)
      link.download = `backtest_${strategy.value}_${new Date().toISOString().split('T')[0]}.csv`
      link.click()
    }

    // 事件處理
    const onStockChange = () => {
      // 清除之前的結果
      backtestResults.value = null
      tradeHistory.value = []
    }

    const onStrategyChange = () => {
      // 根據策略設定預設參數
      // (已在 parameters 定義預設值)
    }

    // 監聽圖表視圖切換
    watch(chartView, (newView) => {
      // 確保當前視圖的圖表已初始化
      if (newView === 'equity' && equityCurve.value && !equityChartInstance) {
        initEquityChart()
      } else if (newView === 'drawdown' && drawdownChart.value && !drawdownChartInstance) {
        initDrawdownChart()
      } else if (newView === 'price' && priceChart.value && !priceChartInstance) {
        initPriceChart()
      }
    })

    // 生命週期
    onMounted(() => {
      loadStocks()
      loadStrategies()
    })

    onUnmounted(() => {
      if (equityChartInstance) equityChartInstance.destroy()
      if (drawdownChartInstance) drawdownChartInstance.destroy()
      if (priceChartInstance) priceChartInstance.destroy()
    })

    return {
      running,
      loadingStocks,
      selectedStock,
      strategy,
      startDate,
      endDate,
      initialCapital,
      chartView,
      parameters,
      stockList,
      strategies,
      backtestResults,
      tradeHistory,
      equityCurve,
      drawdownChart,
      priceChart,
      tradeHeaders,
      runBacktest,
      saveStrategy,
      exportTrades,
      onStockChange,
      onStrategyChange
    }
  }
}
</script>

<style scoped>
.backtest-page {
  padding: 16px;
}
</style>
