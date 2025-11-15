<template>
  <div class="options-page">
    <v-row>
      <v-col cols="12">
        <v-card elevation="2">
          <v-card-title>
            <v-icon class="mr-2">mdi-format-list-bulleted</v-icon>
            選擇權鏈
            <v-spacer></v-spacer>
            <v-btn color="primary" prepend-icon="mdi-refresh" @click="refreshData">
              更新資料
            </v-btn>
          </v-card-title>

          <v-card-text>
            <!-- 篩選條件 -->
            <v-row class="mb-4">
              <v-col cols="12" md="3">
                <v-text-field
                  v-model="underlyingSymbol"
                  label="標的股票代碼"
                  density="compact"
                  hide-details
                  append-inner-icon="mdi-magnify"
                  @click:append-inner="searchOptions"
                ></v-text-field>
              </v-col>
              <v-col cols="12" md="3">
                <v-select
                  v-model="selectedExpiry"
                  :items="expiryDates"
                  label="到期日"
                  density="compact"
                  hide-details
                ></v-select>
              </v-col>
              <v-col cols="12" md="2">
                <v-select
                  v-model="optionType"
                  :items="['全部', 'Call', 'Put']"
                  label="類型"
                  density="compact"
                  hide-details
                ></v-select>
              </v-col>
              <v-col cols="12" md="2">
                <v-select
                  v-model="moneyness"
                  :items="['全部', '價內', '價平', '價外']"
                  label="價性"
                  density="compact"
                  hide-details
                ></v-select>
              </v-col>
              <v-col cols="12" md="2">
                <v-btn color="secondary" block @click="compareOptions">
                  比較分析
                </v-btn>
              </v-col>
            </v-row>

            <!-- 標的股票資訊卡 -->
            <v-card v-if="underlyingStock" class="mb-4" outlined>
              <v-card-text>
                <v-row align="center">
                  <v-col cols="auto">
                    <div class="text-h5">{{ underlyingStock.symbol }} - {{ underlyingStock.name }}</div>
                  </v-col>
                  <v-col cols="auto">
                    <div class="text-h4">${{ underlyingStock.price }}</div>
                  </v-col>
                  <v-col cols="auto">
                    <v-chip :color="underlyingStock.change >= 0 ? 'success' : 'error'">
                      {{ underlyingStock.change >= 0 ? '+' : '' }}{{ underlyingStock.change }}%
                    </v-chip>
                  </v-col>
                  <v-spacer></v-spacer>
                  <v-col cols="auto">
                    <v-chip class="mr-2">
                      <v-icon start>mdi-chart-bell-curve</v-icon>
                      HV: {{ underlyingStock.hv }}%
                    </v-chip>
                    <v-chip>
                      <v-icon start>mdi-calendar</v-icon>
                      {{ underlyingStock.tradingDays }} 天
                    </v-chip>
                  </v-col>
                </v-row>
              </v-card-text>
            </v-card>

            <!-- 選擇權鏈表格 -->
            <div class="option-chain-container">
              <v-row>
                <!-- Call 選擇權 -->
                <v-col cols="12" md="6">
                  <div class="text-h6 text-center mb-2 text-success">Call 買權</div>
                  <v-data-table
                    :headers="callHeaders"
                    :items="callOptions"
                    :items-per-page="15"
                    item-value="strike"
                    density="compact"
                    class="elevation-1"
                  >
                    <template v-slot:item.strike="{ item }">
                      <span :class="getStrikeClass(item.strike, 'call')">
                        {{ item.strike }}
                      </span>
                    </template>

                    <template v-slot:item.iv="{ item }">
                      <v-chip size="small" :color="getIVColor(item.iv)">
                        {{ item.iv }}%
                      </v-chip>
                    </template>

                    <template v-slot:item.change="{ item }">
                      <span :class="item.change >= 0 ? 'text-success' : 'text-error'">
                        {{ item.change >= 0 ? '+' : '' }}{{ item.change }}%
                      </span>
                    </template>

                    <template v-slot:item.actions="{ item }">
                      <v-btn icon="mdi-calculator" size="small" variant="text" @click="calculateBS(item, 'call')"></v-btn>
                      <v-btn icon="mdi-chart-line" size="small" variant="text" @click="viewGreeks(item, 'call')"></v-btn>
                    </template>
                  </v-data-table>
                </v-col>

                <!-- Put 選擇權 -->
                <v-col cols="12" md="6">
                  <div class="text-h6 text-center mb-2 text-error">Put 賣權</div>
                  <v-data-table
                    :headers="putHeaders"
                    :items="putOptions"
                    :items-per-page="15"
                    item-value="strike"
                    density="compact"
                    class="elevation-1"
                  >
                    <template v-slot:item.strike="{ item }">
                      <span :class="getStrikeClass(item.strike, 'put')">
                        {{ item.strike }}
                      </span>
                    </template>

                    <template v-slot:item.iv="{ item }">
                      <v-chip size="small" :color="getIVColor(item.iv)">
                        {{ item.iv }}%
                      </v-chip>
                    </template>

                    <template v-slot:item.change="{ item }">
                      <span :class="item.change >= 0 ? 'text-success' : 'text-error'">
                        {{ item.change >= 0 ? '+' : '' }}{{ item.change }}%
                      </span>
                    </template>

                    <template v-slot:item.actions="{ item }">
                      <v-btn icon="mdi-calculator" size="small" variant="text" @click="calculateBS(item, 'put')"></v-btn>
                      <v-btn icon="mdi-chart-line" size="small" variant="text" @click="viewGreeks(item, 'put')"></v-btn>
                    </template>
                  </v-data-table>
                </v-col>
              </v-row>
            </div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- 選擇權統計資訊 -->
    <v-row class="mt-4">
      <v-col cols="12" md="3">
        <v-card>
          <v-card-text>
            <div class="text-subtitle-2 text-grey">Call OI 總量</div>
            <div class="text-h5">{{ formatNumber(callOI) }}</div>
          </v-card-text>
        </v-card>
      </v-col>
      <v-col cols="12" md="3">
        <v-card>
          <v-card-text>
            <div class="text-subtitle-2 text-grey">Put OI 總量</div>
            <div class="text-h5">{{ formatNumber(putOI) }}</div>
          </v-card-text>
        </v-card>
      </v-col>
      <v-col cols="12" md="3">
        <v-card>
          <v-card-text>
            <div class="text-subtitle-2 text-grey">Put/Call Ratio</div>
            <div class="text-h5">{{ (putOI / callOI).toFixed(2) }}</div>
          </v-card-text>
        </v-card>
      </v-col>
      <v-col cols="12" md="3">
        <v-card>
          <v-card-text>
            <div class="text-subtitle-2 text-grey">Max Pain</div>
            <div class="text-h5">${{ maxPain }}</div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>
  </div>
</template>

<script>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'

export default {
  name: 'Options',
  setup() {
    const router = useRouter()

    // 狀態
    const underlyingSymbol = ref('2330')
    const selectedExpiry = ref('2025-12-17')
    const optionType = ref('全部')
    const moneyness = ref('全部')

    const expiryDates = ['2025-11-20', '2025-12-17', '2026-01-21', '2026-02-18']

    // 標的股票資訊
    const underlyingStock = ref({
      symbol: '2330',
      name: '台積電',
      price: 595,
      change: 2.59,
      hv: 25.3,
      tradingDays: 252
    })

    // 表格標題
    const callHeaders = ref([
      { title: '履約價', key: 'strike' },
      { title: '最新價', key: 'price' },
      { title: '漲跌幅', key: 'change' },
      { title: '成交量', key: 'volume' },
      { title: '未平倉', key: 'openInterest' },
      { title: 'IV', key: 'iv' },
      { title: '操作', key: 'actions', sortable: false }
    ])

    const putHeaders = ref([...callHeaders.value])

    // 模擬選擇權資料
    const callOptions = ref([
      { strike: 580, price: 25.5, change: 5.2, volume: 2500, openInterest: 15000, iv: 22.5 },
      { strike: 590, price: 18.2, change: 3.8, volume: 3200, openInterest: 18500, iv: 24.1 },
      { strike: 595, price: 15.8, change: 2.9, volume: 4500, openInterest: 22000, iv: 25.3 },
      { strike: 600, price: 12.5, change: 1.5, volume: 5800, openInterest: 28000, iv: 26.8 },
      { strike: 610, price: 8.3, change: -1.2, volume: 3100, openInterest: 16500, iv: 28.2 }
    ])

    const putOptions = ref([
      { strike: 580, price: 6.2, change: -2.5, volume: 1800, openInterest: 12000, iv: 23.1 },
      { strike: 590, price: 9.8, change: -1.8, volume: 2500, openInterest: 15500, iv: 24.5 },
      { strike: 595, price: 12.5, change: -0.8, volume: 3200, openInterest: 19000, iv: 25.3 },
      { strike: 600, price: 16.2, change: 0.5, volume: 4100, openInterest: 24500, iv: 27.0 },
      { strike: 610, price: 22.5, change: 2.3, volume: 2900, openInterest: 14000, iv: 29.5 }
    ])

    // 計算屬性
    const callOI = computed(() => callOptions.value.reduce((sum, opt) => sum + opt.openInterest, 0))
    const putOI = computed(() => putOptions.value.reduce((sum, opt) => sum + opt.openInterest, 0))
    const maxPain = ref(595)

    // 方法
    const formatNumber = (num) => {
      return num.toLocaleString('zh-TW')
    }

    const getStrikeClass = (strike, type) => {
      const spotPrice = underlyingStock.value.price
      if (type === 'call') {
        if (strike < spotPrice) return 'font-weight-bold text-success' // ITM
        if (strike === spotPrice) return 'font-weight-bold text-primary' // ATM
        return 'text-grey' // OTM
      } else {
        if (strike > spotPrice) return 'font-weight-bold text-error' // ITM
        if (strike === spotPrice) return 'font-weight-bold text-primary' // ATM
        return 'text-grey' // OTM
      }
    }

    const getIVColor = (iv) => {
      if (iv < 20) return 'success'
      if (iv < 30) return 'warning'
      return 'error'
    }

    const searchOptions = () => {
      console.log('搜尋選擇權:', underlyingSymbol.value)
      // 呼叫 API 搜尋選擇權
    }

    const calculateBS = (option, type) => {
      router.push({
        name: 'BlackScholes',
        query: {
          symbol: underlyingSymbol.value,
          strike: option.strike,
          type: type
        }
      })
    }

    const viewGreeks = (option, type) => {
      console.log('查看 Greeks:', option, type)
    }

    const compareOptions = () => {
      console.log('比較選擇權')
    }

    const refreshData = () => {
      console.log('更新選擇權資料')
    }

    return {
      underlyingSymbol,
      selectedExpiry,
      optionType,
      moneyness,
      expiryDates,
      underlyingStock,
      callHeaders,
      putHeaders,
      callOptions,
      putOptions,
      callOI,
      putOI,
      maxPain,
      formatNumber,
      getStrikeClass,
      getIVColor,
      searchOptions,
      calculateBS,
      viewGreeks,
      compareOptions,
      refreshData
    }
  }
}
</script>

<style scoped>
.options-page {
  padding: 16px;
}

.option-chain-container {
  width: 100%;
}
</style>