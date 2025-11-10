<template>
  <v-container fluid>
    <v-row>
      <v-col cols="12">
        <h1 class="text-h4 mb-4">股價預測分析系統</h1>
      </v-col>
    </v-row>

    <!-- 股票選擇器 -->
    <v-row>
      <v-col cols="12">
        <v-card>
          <v-card-title>選擇股票</v-card-title>
          <v-card-text>
            <v-autocomplete
              v-model="selectedStock"
              :items="stockList"
              :loading="loadingStocks"
              :search-input.sync="stockSearch"
              item-text="display_name"
              item-value="id"
              label="搜尋股票代碼或名稱"
              placeholder="輸入股票代碼或名稱..."
              prepend-icon="mdi-magnify"
              return-object
              clearable
              @change="onStockSelected"
            >
              <template v-slot:item="{ item }">
                <v-list-item-content>
                  <v-list-item-title>
                    {{ item.symbol }} - {{ item.name }}
                  </v-list-item-title>
                  <v-list-item-subtitle>
                    最新價格: ${{ item.latest_price }} |
                    漲跌: <span :class="getPriceChangeClass(item.change_percent)">
                      {{ item.change_percent > 0 ? '+' : '' }}{{ item.change_percent }}%
                    </span>
                  </v-list-item-subtitle>
                </v-list-item-content>
              </template>
            </v-autocomplete>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- 股票資訊卡片 -->
    <v-row v-if="selectedStock">
      <v-col cols="12" md="3">
        <v-card>
          <v-card-text>
            <div class="text-overline">股票代碼</div>
            <div class="text-h5">{{ selectedStock.symbol }}</div>
            <div class="text-subtitle-1">{{ selectedStock.name }}</div>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="3">
        <v-card>
          <v-card-text>
            <div class="text-overline">最新價格</div>
            <div class="text-h5">${{ selectedStock.latest_price }}</div>
            <div class="text-subtitle-1" :class="getPriceChangeClass(selectedStock.change_percent)">
              {{ selectedStock.change_percent > 0 ? '▲' : '▼' }}
              {{ Math.abs(selectedStock.change_percent) }}%
            </div>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="3">
        <v-card>
          <v-card-text>
            <div class="text-overline">成交量</div>
            <div class="text-h5">{{ formatVolume(selectedStock.volume) }}</div>
            <div class="text-subtitle-1">張</div>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="3">
        <v-card>
          <v-card-text>
            <div class="text-overline">更新時間</div>
            <div class="text-h5">{{ formatTime(selectedStock.updated_at) }}</div>
            <div class="text-subtitle-1">{{ formatDate(selectedStock.updated_at) }}</div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- 預測圖表區域 -->
    <v-row v-if="selectedStock">
      <v-col cols="12">
        <PredictionChart
          :stock-id="selectedStock.id"
          :stock-info="{
            symbol: selectedStock.symbol,
            name: selectedStock.name,
            currentPrice: selectedStock.latest_price
          }"
          ref="predictionChart"
        />
      </v-col>
    </v-row>

    <!-- 歷史預測記錄 -->
    <v-row v-if="selectedStock">
      <v-col cols="12">
        <v-card>
          <v-card-title>
            歷史預測記錄
            <v-spacer />
            <v-btn
              color="primary"
              small
              @click="loadPredictionHistory"
            >
              <v-icon left>mdi-refresh</v-icon>
              重新載入
            </v-btn>
          </v-card-title>

          <v-card-text>
            <v-data-table
              :headers="historyHeaders"
              :items="predictionHistory"
              :loading="loadingHistory"
              :items-per-page="10"
              class="elevation-1"
            >
              <template v-slot:item.model_type="{ item }">
                <v-chip :color="getModelColor(item.model_type)" small>
                  {{ item.model_type.toUpperCase() }}
                </v-chip>
              </template>

              <template v-slot:item.predicted_price="{ item }">
                ${{ item.predicted_price.toFixed(2) }}
              </template>

              <template v-slot:item.confidence_interval="{ item }">
                ${{ item.confidence_lower.toFixed(2) }} - ${{ item.confidence_upper.toFixed(2) }}
              </template>

              <template v-slot:item.accuracy="{ item }">
                <v-chip
                  :color="getAccuracyColor(item.accuracy)"
                  small
                  v-if="item.accuracy"
                >
                  {{ item.accuracy.toFixed(1) }}%
                </v-chip>
                <span v-else>---</span>
              </template>

              <template v-slot:item.actions="{ item }">
                <v-btn
                  icon
                  small
                  @click="viewPredictionDetail(item)"
                >
                  <v-icon small>mdi-eye</v-icon>
                </v-btn>

                <v-btn
                  icon
                  small
                  color="error"
                  @click="deletePrediction(item.id)"
                >
                  <v-icon small>mdi-delete</v-icon>
                </v-btn>
              </template>
            </v-data-table>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- 預測詳情對話框 -->
    <v-dialog v-model="detailDialog" max-width="800">
      <v-card v-if="selectedPrediction">
        <v-card-title>
          預測詳情
          <v-spacer />
          <v-btn icon @click="detailDialog = false">
            <v-icon>mdi-close</v-icon>
          </v-btn>
        </v-card-title>

        <v-card-text>
          <v-simple-table>
            <tbody>
              <tr>
                <td class="font-weight-bold">模型類型</td>
                <td>{{ selectedPrediction.model_type.toUpperCase() }}</td>
              </tr>
              <tr>
                <td class="font-weight-bold">預測日期</td>
                <td>{{ selectedPrediction.prediction_date }}</td>
              </tr>
              <tr>
                <td class="font-weight-bold">目標日期</td>
                <td>{{ selectedPrediction.target_date }}</td>
              </tr>
              <tr>
                <td class="font-weight-bold">預測價格</td>
                <td>${{ selectedPrediction.predicted_price.toFixed(2) }}</td>
              </tr>
              <tr>
                <td class="font-weight-bold">信賴區間</td>
                <td>
                  ${{ selectedPrediction.confidence_lower.toFixed(2) }} -
                  ${{ selectedPrediction.confidence_upper.toFixed(2) }}
                </td>
              </tr>
              <tr>
                <td class="font-weight-bold">信賴水準</td>
                <td>{{ (selectedPrediction.confidence_level * 100).toFixed(0) }}%</td>
              </tr>
              <tr v-if="selectedPrediction.parameters">
                <td class="font-weight-bold">模型參數</td>
                <td>
                  <pre>{{ JSON.stringify(selectedPrediction.parameters, null, 2) }}</pre>
                </td>
              </tr>
            </tbody>
          </v-simple-table>
        </v-card-text>
      </v-card>
    </v-dialog>
  </v-container>
</template>

<script>
import { ref, onMounted } from 'vue'
import axios from 'axios'
import PredictionChart from '@/components/PredictionChart.vue'

export default {
  name: 'PredictionAnalysis',

  components: {
    PredictionChart
  },

  setup() {
    // 資料狀態
    const selectedStock = ref(null)
    const stockList = ref([])
    const stockSearch = ref('')
    const loadingStocks = ref(false)
    const predictionHistory = ref([])
    const loadingHistory = ref(false)
    const detailDialog = ref(false)
    const selectedPrediction = ref(null)

    // 圖表參考
    const predictionChart = ref(null)

    // 表格標題
    const historyHeaders = [
      { text: '模型', value: 'model_type', width: '120' },
      { text: '預測日期', value: 'prediction_date' },
      { text: '目標日期', value: 'target_date' },
      { text: '預測價格', value: 'predicted_price' },
      { text: '信賴區間', value: 'confidence_interval' },
      { text: '準確度', value: 'accuracy' },
      { text: '操作', value: 'actions', sortable: false }
    ]

    // 載入股票清單
    const loadStocks = async () => {
      loadingStocks.value = true

      try {
        const response = await axios.get('/api/stocks', {
          params: {
            search: stockSearch.value,
            limit: 20
          }
        })

        stockList.value = response.data.data.map(stock => ({
          ...stock,
          display_name: `${stock.symbol} - ${stock.name}`
        }))
      } catch (error) {
        console.error('載入股票失敗:', error)
      } finally {
        loadingStocks.value = false
      }
    }

    // 選擇股票
    const onStockSelected = (stock) => {
      if (stock) {
        loadPredictionHistory()
      }
    }

    // 載入預測歷史
    const loadPredictionHistory = async () => {
      if (!selectedStock.value) return

      loadingHistory.value = true

      try {
        const response = await axios.get('/api/predictions', {
          params: {
            stock_id: selectedStock.value.id,
            per_page: 50
          }
        })

        predictionHistory.value = response.data.data.data
      } catch (error) {
        console.error('載入預測歷史失敗:', error)
      } finally {
        loadingHistory.value = false
      }
    }

    // 查看預測詳情
    const viewPredictionDetail = (prediction) => {
      selectedPrediction.value = prediction
      detailDialog.value = true
    }

    // 刪除預測
    const deletePrediction = async (id) => {
      if (!confirm('確定要刪除這筆預測記錄嗎？')) return

      try {
        await axios.delete(`/api/predictions/${id}`)
        loadPredictionHistory()
      } catch (error) {
        console.error('刪除預測失敗:', error)
        alert('刪除失敗: ' + error.message)
      }
    }

    // 格式化成交量
    const formatVolume = (volume) => {
      if (volume > 100000000) {
        return (volume / 100000000).toFixed(2) + '億'
      }
      if (volume > 10000) {
        return (volume / 10000).toFixed(0) + '萬'
      }
      return volume.toLocaleString()
    }

    // 格式化日期
    const formatDate = (dateString) => {
      if (!dateString) return '---'
      const date = new Date(dateString)
      return date.toLocaleDateString('zh-TW')
    }

    // 格式化時間
    const formatTime = (dateString) => {
      if (!dateString) return '---'
      const date = new Date(dateString)
      return date.toLocaleTimeString('zh-TW', { hour: '2-digit', minute: '2-digit' })
    }

    // 取得價格變化樣式
    const getPriceChangeClass = (changePercent) => {
      if (changePercent > 0) return 'text-success'
      if (changePercent < 0) return 'text-error'
      return 'text-grey'
    }

    // 取得模型顏色
    const getModelColor = (model) => {
      const colors = {
        'lstm': 'success',
        'arima': 'info',
        'garch': 'warning',
        'monte_carlo': 'primary'
      }
      return colors[model] || 'grey'
    }

    // 取得準確度顏色
    const getAccuracyColor = (accuracy) => {
      if (accuracy >= 90) return 'success'
      if (accuracy >= 75) return 'info'
      if (accuracy >= 60) return 'warning'
      return 'error'
    }

    // 元件掛載
    onMounted(() => {
      loadStocks()
    })

    return {
      selectedStock,
      stockList,
      stockSearch,
      loadingStocks,
      predictionHistory,
      loadingHistory,
      detailDialog,
      selectedPrediction,
      predictionChart,
      historyHeaders,
      loadStocks,
      onStockSelected,
      loadPredictionHistory,
      viewPredictionDetail,
      deletePrediction,
      formatVolume,
      formatDate,
      formatTime,
      getPriceChangeClass,
      getModelColor,
      getAccuracyColor
    }
  }
}
</script>

<style scoped>
.text-success {
  color: #4caf50;
}

.text-error {
  color: #f44336;
}

.text-grey {
  color: #9e9e9e;
}

pre {
  font-size: 12px;
  background-color: #f5f5f5;
  padding: 8px;
  border-radius: 4px;
}
</style>
