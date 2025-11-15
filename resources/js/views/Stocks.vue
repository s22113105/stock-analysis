<template>
  <div class="stocks-page">
    <v-row>
      <v-col cols="12">
        <v-card elevation="2">
          <v-card-title>
            <v-icon class="mr-2">mdi-chart-line</v-icon>
            股票報價
            <v-spacer></v-spacer>
            <v-btn color="primary" prepend-icon="mdi-refresh" @click="refreshData">
              更新資料
            </v-btn>
          </v-card-title>
          
          <v-card-text>
            <!-- 搜尋與篩選 -->
            <v-row class="mb-4">
              <v-col cols="12" md="4">
                <v-text-field
                  v-model="search"
                  append-inner-icon="mdi-magnify"
                  label="搜尋股票代碼或名稱"
                  single-line
                  hide-details
                  density="compact"
                  clearable
                ></v-text-field>
              </v-col>
              <v-col cols="12" md="3">
                <v-select
                  v-model="categoryFilter"
                  :items="categories"
                  label="產業類別"
                  density="compact"
                  hide-details
                  clearable
                ></v-select>
              </v-col>
              <v-col cols="12" md="3">
                <v-select
                  v-model="marketFilter"
                  :items="markets"
                  label="市場別"
                  density="compact"
                  hide-details
                  clearable
                ></v-select>
              </v-col>
              <v-col cols="12" md="2">
                <v-select
                  v-model="changeFilter"
                  :items="changeOptions"
                  label="漲跌篩選"
                  density="compact"
                  hide-details
                  clearable
                ></v-select>
              </v-col>
            </v-row>

            <!-- 股票清單表格 -->
            <v-data-table
              :headers="headers"
              :items="filteredStocks"
              :search="search"
              :items-per-page="15"
              item-value="symbol"
              class="elevation-1"
            >
              <template v-slot:item.symbol="{ item }">
                <v-btn variant="text" color="primary" @click="viewStockDetail(item)">
                  {{ item.symbol }}
                </v-btn>
              </template>

              <template v-slot:item.change="{ item }">
                <v-chip :color="getChangeColor(item.change)" size="small">
                  <v-icon start>{{ item.change >= 0 ? 'mdi-arrow-up' : 'mdi-arrow-down' }}</v-icon>
                  {{ item.change >= 0 ? '+' : '' }}{{ item.change }}%
                </v-chip>
              </template>

              <template v-slot:item.volume="{ item }">
                {{ formatVolume(item.volume) }}
              </template>

              <template v-slot:item.actions="{ item }">
                <v-btn icon="mdi-chart-line" size="small" variant="text" @click="viewChart(item)"></v-btn>
                <v-btn icon="mdi-star-outline" size="small" variant="text" @click="addToWatchlist(item)"></v-btn>
                <v-btn icon="mdi-calculator-variant" size="small" variant="text" @click="calculate(item)"></v-btn>
              </template>
            </v-data-table>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- 快速統計 -->
    <v-row class="mt-4">
      <v-col cols="12" md="3">
        <v-card color="success" dark>
          <v-card-text>
            <div class="text-h6">上漲家數</div>
            <div class="text-h4">{{ upCount }}</div>
          </v-card-text>
        </v-card>
      </v-col>
      <v-col cols="12" md="3">
        <v-card color="error" dark>
          <v-card-text>
            <div class="text-h6">下跌家數</div>
            <div class="text-h4">{{ downCount }}</div>
          </v-card-text>
        </v-card>
      </v-col>
      <v-col cols="12" md="3">
        <v-card color="grey" dark>
          <v-card-text>
            <div class="text-h6">平盤家數</div>
            <div class="text-h4">{{ flatCount }}</div>
          </v-card-text>
        </v-card>
      </v-col>
      <v-col cols="12" md="3">
        <v-card color="primary" dark>
          <v-card-text>
            <div class="text-h6">總成交量</div>
            <div class="text-h4">{{ formatVolume(totalVolume) }}</div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- 股票詳情對話框 -->
    <v-dialog v-model="detailDialog" max-width="800">
      <v-card v-if="selectedStock">
        <v-card-title>
          {{ selectedStock.symbol }} - {{ selectedStock.name }}
          <v-spacer></v-spacer>
          <v-btn icon="mdi-close" variant="text" @click="detailDialog = false"></v-btn>
        </v-card-title>
        <v-card-text>
          <v-row>
            <v-col cols="6">
              <div class="text-subtitle-2 text-grey">當前價格</div>
              <div class="text-h4">${{ selectedStock.price }}</div>
            </v-col>
            <v-col cols="6">
              <div class="text-subtitle-2 text-grey">漲跌幅</div>
              <div class="text-h4" :class="selectedStock.change >= 0 ? 'text-success' : 'text-error'">
                {{ selectedStock.change >= 0 ? '+' : '' }}{{ selectedStock.change }}%
              </div>
            </v-col>
          </v-row>
          <v-divider class="my-4"></v-divider>
          <v-row>
            <v-col cols="4">
              <div class="text-subtitle-2 text-grey">開盤價</div>
              <div class="text-body-1">${{ selectedStock.open }}</div>
            </v-col>
            <v-col cols="4">
              <div class="text-subtitle-2 text-grey">最高價</div>
              <div class="text-body-1">${{ selectedStock.high }}</div>
            </v-col>
            <v-col cols="4">
              <div class="text-subtitle-2 text-grey">最低價</div>
              <div class="text-body-1">${{ selectedStock.low }}</div>
            </v-col>
          </v-row>
          <v-row class="mt-2">
            <v-col cols="4">
              <div class="text-subtitle-2 text-grey">成交量</div>
              <div class="text-body-1">{{ formatVolume(selectedStock.volume) }}</div>
            </v-col>
            <v-col cols="4">
              <div class="text-subtitle-2 text-grey">市值</div>
              <div class="text-body-1">${{ formatVolume(selectedStock.marketCap) }}</div>
            </v-col>
            <v-col cols="4">
              <div class="text-subtitle-2 text-grey">本益比</div>
              <div class="text-body-1">{{ selectedStock.pe }}</div>
            </v-col>
          </v-row>
        </v-card-text>
        <v-card-actions>
          <v-spacer></v-spacer>
          <v-btn color="primary" @click="viewChart(selectedStock)">查看走勢圖</v-btn>
          <v-btn color="secondary" @click="calculate(selectedStock)">選擇權分析</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'

export default {
  name: 'Stocks',
  setup() {
    const router = useRouter()
    
    // 狀態
    const search = ref('')
    const categoryFilter = ref(null)
    const marketFilter = ref(null)
    const changeFilter = ref(null)
    const detailDialog = ref(false)
    const selectedStock = ref(null)

    // 選項
    const categories = ['半導體', '金融', '電子', '傳產', '生技醫療']
    const markets = ['上市', '上櫃', '興櫃']
    const changeOptions = ['上漲', '下跌', '平盤', '漲停', '跌停']

    // 表格標題
    const headers = ref([
      { title: '股票代碼', key: 'symbol' },
      { title: '股票名稱', key: 'name' },
      { title: '產業', key: 'category' },
      { title: '當前價格', key: 'price' },
      { title: '漲跌幅', key: 'change' },
      { title: '成交量', key: 'volume' },
      { title: '市值', key: 'marketCap' },
      { title: '操作', key: 'actions', sortable: false }
    ])

    // 模擬股票資料
    const stocks = ref([
      { symbol: '2330', name: '台積電', category: '半導體', price: 595, change: 2.59, volume: 25000000, marketCap: 15400000000000, open: 590, high: 598, low: 588, pe: 18.5 },
      { symbol: '2317', name: '鴻海', category: '電子', price: 108, change: 2.86, volume: 45000000, marketCap: 1500000000000, open: 105, high: 109, low: 104, pe: 12.3 },
      { symbol: '2454', name: '聯發科', category: '半導體', price: 830, change: -2.35, volume: 8000000, marketCap: 1320000000000, open: 850, high: 855, low: 825, pe: 15.8 },
      { symbol: '2308', name: '台達電', category: '電子', price: 335, change: 4.69, volume: 12000000, marketCap: 870000000000, open: 320, high: 338, low: 318, pe: 22.1 },
      { symbol: '2881', name: '富邦金', category: '金融', price: 78.5, change: -1.25, volume: 18000000, marketCap: 1090000000000, open: 79.5, high: 80, low: 77.8, pe: 10.5 }
    ])

    // 計算屬性
    const filteredStocks = computed(() => {
      let filtered = stocks.value

      if (categoryFilter.value) {
        filtered = filtered.filter(s => s.category === categoryFilter.value)
      }

      if (changeFilter.value) {
        if (changeFilter.value === '上漲') {
          filtered = filtered.filter(s => s.change > 0)
        } else if (changeFilter.value === '下跌') {
          filtered = filtered.filter(s => s.change < 0)
        } else if (changeFilter.value === '平盤') {
          filtered = filtered.filter(s => s.change === 0)
        }
      }

      return filtered
    })

    const upCount = computed(() => stocks.value.filter(s => s.change > 0).length)
    const downCount = computed(() => stocks.value.filter(s => s.change < 0).length)
    const flatCount = computed(() => stocks.value.filter(s => s.change === 0).length)
    const totalVolume = computed(() => stocks.value.reduce((sum, s) => sum + s.volume, 0))

    // 方法
    const formatVolume = (volume) => {
      if (volume >= 100000000) {
        return (volume / 100000000).toFixed(2) + '億'
      } else if (volume >= 10000) {
        return (volume / 10000).toFixed(0) + '萬'
      }
      return volume.toLocaleString()
    }

    const getChangeColor = (change) => {
      if (change > 0) return 'success'
      if (change < 0) return 'error'
      return 'grey'
    }

    const viewStockDetail = (stock) => {
      selectedStock.value = stock
      detailDialog.value = true
    }

    const viewChart = (stock) => {
      console.log('查看走勢圖:', stock)
      // 可以導航到圖表頁面或開啟圖表對話框
    }

    const addToWatchlist = (stock) => {
      console.log('加入自選股:', stock)
    }

    const calculate = (stock) => {
      router.push({ name: 'BlackScholes', query: { symbol: stock.symbol } })
    }

    const refreshData = () => {
      console.log('更新資料')
      // 呼叫 API 更新股票資料
    }

    return {
      search,
      categoryFilter,
      marketFilter,
      changeFilter,
      categories,
      markets,
      changeOptions,
      headers,
      filteredStocks,
      upCount,
      downCount,
      flatCount,
      totalVolume,
      detailDialog,
      selectedStock,
      formatVolume,
      getChangeColor,
      viewStockDetail,
      viewChart,
      addToWatchlist,
      calculate,
      refreshData
    }
  }
}
</script>

<style scoped>
.stocks-page {
  padding: 16px;
}
</style>