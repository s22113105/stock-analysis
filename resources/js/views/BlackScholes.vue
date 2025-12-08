<template>
  <div class="black-scholes-page">
    <v-container fluid>
      <!-- 標題區 -->
      <v-row>
        <v-col cols="12">
          <h2 class="text-h4 font-weight-bold mb-4">
            <v-icon color="primary" class="mr-2">mdi-calculator-variant</v-icon>
            Black-Scholes 選擇權定價模型
          </h2>
        </v-col>
      </v-row>

      <v-row>
        <!-- 左側：輸入參數區 -->
        <v-col cols="12" md="4">
          <v-card elevation="3">
            <!-- 模式切換 -->
            <v-tabs v-model="mode" color="primary" grow>
              <v-tab value="price">
                <v-icon start>mdi-function</v-icon>計算價格
              </v-tab>
              <v-tab value="iv">
                <v-icon start>mdi-eye-outline</v-icon>反推 IV
              </v-tab>
            </v-tabs>

            <v-card-text class="pt-4">
              <v-form ref="form">
                <!-- 快速預設值按鈕 -->
                <div class="mb-4">
                  <div class="text-subtitle-2 text-grey mb-2">
                    <v-icon size="small" class="mr-1">mdi-lightning-bolt</v-icon>
                    快速預設（自動抓取即時數據）
                    <v-tooltip location="top">
                      <template v-slot:activator="{ props }">
                        <v-icon size="small" v-bind="props" class="ml-1">mdi-help-circle-outline</v-icon>
                      </template>
                      <span>點擊後自動從 API 取得最新台指期貨價格和市場 IV</span>
                    </v-tooltip>
                  </div>
                  <v-btn-group density="compact" divided>
                    <v-btn 
                      size="small" 
                      variant="outlined" 
                      @click="applyPreset('txo_atm')"
                      :loading="presetLoading === 'txo_atm'"
                      :disabled="presetLoading !== null"
                    >
                      <v-icon start size="small">mdi-minus-circle</v-icon>
                      TXO 價平
                    </v-btn>
                    <v-btn 
                      size="small" 
                      variant="outlined" 
                      @click="applyPreset('txo_otm_call')"
                      :loading="presetLoading === 'txo_otm_call'"
                      :disabled="presetLoading !== null"
                    >
                      <v-icon start size="small" color="error">mdi-arrow-up-bold</v-icon>
                      OTM Call
                    </v-btn>
                    <v-btn 
                      size="small" 
                      variant="outlined" 
                      @click="applyPreset('txo_otm_put')"
                      :loading="presetLoading === 'txo_otm_put'"
                      :disabled="presetLoading !== null"
                    >
                      <v-icon start size="small" color="success">mdi-arrow-down-bold</v-icon>
                      OTM Put
                    </v-btn>
                  </v-btn-group>

                  <!-- 數據來源提示 -->
                  <div v-if="marketDataInfo" class="text-caption mt-2" :class="marketDataInfo.isLive ? 'text-success' : 'text-warning'">
                    <v-icon size="x-small" class="mr-1">
                      {{ marketDataInfo.isLive ? 'mdi-access-point' : 'mdi-database' }}
                    </v-icon>
                    {{ marketDataInfo.message }}
                  </div>
                </div>

                <!-- Call/Put 切換 -->
                <v-btn-toggle
                  v-model="params.optionType"
                  color="primary"
                  mandatory
                  divided
                  class="mb-4 w-100"
                >
                  <v-btn value="call" width="50%">
                    <v-icon start color="error">mdi-arrow-up-bold</v-icon>
                    CALL 買權
                  </v-btn>
                  <v-btn value="put" width="50%">
                    <v-icon start color="success">mdi-arrow-down-bold</v-icon>
                    PUT 賣權
                  </v-btn>
                </v-btn-toggle>

                <!-- 標的現價 -->
                <v-text-field
                  v-model.number="params.spotPrice"
                  label="標的現價 (S)"
                  type="number"
                  step="1"
                  prefix="$"
                  variant="outlined"
                  density="comfortable"
                >
                  <template v-slot:append-inner>
                    <v-tooltip location="top">
                      <template v-slot:activator="{ props }">
                        <v-icon v-bind="props" size="small">mdi-help-circle-outline</v-icon>
                      </template>
                      <span>標的資產（如台指期貨）的目前市價</span>
                    </v-tooltip>
                  </template>
                </v-text-field>

                <!-- 履約價 -->
                <v-text-field
                  v-model.number="params.strikePrice"
                  label="履約價 (K)"
                  type="number"
                  step="100"
                  prefix="$"
                  variant="outlined"
                  density="comfortable"
                >
                  <template v-slot:append-inner>
                    <v-tooltip location="top">
                      <template v-slot:activator="{ props }">
                        <v-icon v-bind="props" size="small">mdi-help-circle-outline</v-icon>
                      </template>
                      <span>選擇權的履約價格（執行價）</span>
                    </v-tooltip>
                  </template>
                </v-text-field>

                <!-- 到期日選擇 -->
                <v-row dense>
                  <v-col cols="7">
                    <v-text-field
                      v-model="expiryDate"
                      label="到期日"
                      type="date"
                      variant="outlined"
                      density="comfortable"
                      @update:model-value="updateTimeToExpiry"
                    ></v-text-field>
                  </v-col>
                  <v-col cols="5">
                    <v-text-field
                      v-model.number="daysRemaining"
                      label="剩餘天數"
                      type="number"
                      suffix="天"
                      variant="outlined"
                      density="comfortable"
                      readonly
                      bg-color="grey-lighten-4"
                    ></v-text-field>
                  </v-col>
                </v-row>

                <div class="text-caption text-grey mb-3">
                  年化時間 (T) = {{ params.timeToExpiry.toFixed(4) }} 年
                </div>

                <!-- 無風險利率 -->
                <v-text-field
                  v-model.number="params.riskFreeRate"
                  label="無風險利率 (r)"
                  type="number"
                  step="0.1"
                  suffix="%"
                  variant="outlined"
                  density="comfortable"
                  hint="例如: 1.5%"
                  persistent-hint
                >
                  <template v-slot:append-inner>
                    <v-tooltip location="top">
                      <template v-slot:activator="{ props }">
                        <v-icon v-bind="props" size="small">mdi-help-circle-outline</v-icon>
                      </template>
                      <span>通常使用央行定存利率或國庫券利率</span>
                    </v-tooltip>
                  </template>
                </v-text-field>

                <!-- 波動率設定（計算價格模式）-->
                <div v-if="mode === 'price'">
                  <div class="text-subtitle-2 text-primary mb-1">
                    波動率設定 (σ)
                    <v-tooltip location="top">
                      <template v-slot:activator="{ props }">
                        <v-icon v-bind="props" size="small" class="ml-1">mdi-help-circle-outline</v-icon>
                      </template>
                      <span>年化波動率，可使用歷史波動率(HV)或隱含波動率(IV)</span>
                    </v-tooltip>
                  </div>
                  <v-slider
                    v-model="params.volatility"
                    min="1"
                    max="100"
                    step="0.5"
                    color="primary"
                    hide-details
                    thumb-label
                  ></v-slider>
                  <v-text-field
                    v-model.number="params.volatility"
                    label="波動率"
                    type="number"
                    suffix="%"
                    variant="outlined"
                    density="comfortable"
                  ></v-text-field>
                </div>

                <!-- 市場價格（反推 IV 模式）-->
                <div v-else>
                  <v-alert type="info" variant="tonal" density="compact" class="mb-3">
                    輸入當前市場價格，反推市場預期的隱含波動率。
                  </v-alert>
                  <v-text-field
                    v-model.number="params.marketPrice"
                    label="選擇權市場成交價"
                    type="number"
                    prefix="$"
                    variant="outlined"
                    density="comfortable"
                    color="secondary"
                  ></v-text-field>
                </div>

                <!-- 計算按鈕 -->
                <v-btn
                  color="primary"
                  size="large"
                  block
                  class="mt-4"
                  :loading="loading"
                  @click="calculate"
                >
                  <v-icon left>mdi-calculator</v-icon>
                  {{ mode === 'price' ? '計算理論價格 & Greeks' : '反推隱含波動率 (IV)' }}
                </v-btn>

                <!-- 匯出按鈕 -->
                <v-btn
                  v-if="result"
                  color="secondary"
                  variant="outlined"
                  size="small"
                  block
                  class="mt-2"
                  @click="exportResult"
                >
                  <v-icon left size="small">mdi-download</v-icon>
                  匯出計算結果 (CSV)
                </v-btn>
              </v-form>
            </v-card-text>
          </v-card>
        </v-col>

        <!-- 右側：計算結果區 -->
        <v-col cols="12" md="8">
          <!-- 主要結果卡片 -->
          <v-card elevation="2" class="mb-4" :color="resultCardColor">
            <v-card-text class="text-center py-6">
              <div v-if="!result">
                <v-icon size="64" color="grey-lighten-2">mdi-chart-line</v-icon>
                <div class="text-h6 text-grey mt-2">請輸入參數並點擊計算</div>
              </div>

              <div v-else>
                <div class="text-subtitle-1 mb-2 opacity-75">
                  {{ mode === 'price' ? '理論價格 (Theoretical Price)' : '隱含波動率 (Implied Volatility)' }}
                </div>
                <div class="text-h2 font-weight-bold">
                  <span v-if="mode === 'price'">${{ result.theoretical_price }}</span>
                  <span v-else>{{ result.implied_volatility_percentage }}</span>
                </div>

                <!-- Moneyness 標籤 -->
                <v-chip
                  v-if="result.moneyness"
                  class="mt-3"
                  :color="getMoneynessColor(result.moneyness)"
                  variant="flat"
                  size="large"
                >
                  <v-icon start size="small">{{ getMoneynessIcon(result.moneyness) }}</v-icon>
                  {{ result.moneyness }} - {{ getMoneynessLabel(result.moneyness) }}
                </v-chip>

                <!-- 內在價值/時間價值 -->
                <v-row v-if="mode === 'price'" class="mt-4" justify="center">
                  <v-col cols="auto">
                    <div class="text-caption text-grey">內在價值</div>
                    <div class="text-h6">${{ result.intrinsic_value || 0 }}</div>
                  </v-col>
                  <v-col cols="auto">
                    <div class="text-caption text-grey">時間價值</div>
                    <div class="text-h6">${{ result.time_value || (result.theoretical_price - (result.intrinsic_value || 0)).toFixed(2) }}</div>
                  </v-col>
                </v-row>
              </div>
            </v-card-text>
          </v-card>

          <!-- Greeks 卡片 -->
          <v-row v-if="mode === 'price' && result?.greeks">
            <v-col cols="6" sm="4" md="2.4" v-for="(greek, index) in greeksList" :key="greek.name">
              <v-card variant="outlined" class="greek-card">
                <v-card-text class="text-center pa-3">
                  <v-tooltip location="top">
                    <template v-slot:activator="{ props }">
                      <div v-bind="props" class="text-caption text-uppercase text-grey font-weight-bold greek-label">
                        {{ greek.name }}
                        <v-icon size="x-small" class="ml-1">mdi-help-circle-outline</v-icon>
                      </div>
                    </template>
                    <span>{{ greek.description }}</span>
                  </v-tooltip>
                  <div class="text-h6 mt-1" :class="getValueColor(greek.value)">
                    {{ formatGreek(greek.name, greek.value) }}
                  </div>
                </v-card-text>
              </v-card>
            </v-col>
          </v-row>

          <!-- 圖表切換 Tabs -->
          <v-card elevation="2" class="mt-4" v-if="result">
            <v-tabs v-model="chartTab" color="primary" grow>
              <v-tab value="sensitivity">
                <v-icon start size="small">mdi-chart-bell-curve-cumulative</v-icon>
                價格敏感度
              </v-tab>
              <v-tab value="payoff">
                <v-icon start size="small">mdi-chart-line-variant</v-icon>
                到期損益
              </v-tab>
              <v-tab value="timedecay">
                <v-icon start size="small">mdi-clock-outline</v-icon>
                時間衰減
              </v-tab>
            </v-tabs>

            <v-card-text>
              <v-window v-model="chartTab">
                <!-- 價格敏感度圖表 -->
                <v-window-item value="sensitivity">
                  <div style="height: 350px; position: relative;">
                    <canvas ref="sensitivityChart"></canvas>
                  </div>
                  <div class="text-caption text-center text-grey mt-2">
                    X軸：標的股價變動 | Y軸：選擇權理論價格
                    <br>
                    <span class="text-primary">使用 Black-Scholes 公式精確計算每個點</span>
                  </div>
                </v-window-item>

                <!-- 到期損益圖表 -->
                <v-window-item value="payoff">
                  <div style="height: 350px; position: relative;">
                    <canvas ref="payoffChart"></canvas>
                  </div>
                  <div class="text-caption text-center text-grey mt-2">
                    X軸：到期時標的價格 | Y軸：損益（假設以理論價格買入）
                    <br>
                    <span class="text-success">綠色區域為獲利</span> | 
                    <span class="text-error">紅色區域為虧損</span>
                  </div>
                </v-window-item>

                <!-- 時間衰減圖表 -->
                <v-window-item value="timedecay">
                  <div style="height: 350px; position: relative;">
                    <canvas ref="timeDecayChart"></canvas>
                  </div>
                  <div class="text-caption text-center text-grey mt-2">
                    X軸：剩餘天數 | Y軸：選擇權價值
                    <br>
                    <span class="text-warning">隨著時間流逝，時間價值加速衰減（Theta 效應）</span>
                  </div>
                </v-window-item>
              </v-window>
            </v-card-text>
          </v-card>

          <!-- 計算公式說明 (可收合) -->
          <v-expansion-panels class="mt-4">
            <v-expansion-panel>
              <v-expansion-panel-title>
                <v-icon start>mdi-function-variant</v-icon>
                Black-Scholes 公式說明
              </v-expansion-panel-title>
              <v-expansion-panel-text>
                <div class="formula-section">
                  <h4>Call 買權定價公式</h4>
                  <code class="formula">C = S × N(d₁) - K × e^(-rT) × N(d₂)</code>

                  <h4 class="mt-3">Put 賣權定價公式</h4>
                  <code class="formula">P = K × e^(-rT) × N(-d₂) - S × N(-d₁)</code>

                  <h4 class="mt-3">d₁ 與 d₂ 計算</h4>
                  <code class="formula">d₁ = [ln(S/K) + (r + σ²/2)T] / (σ√T)</code>
                  <code class="formula">d₂ = d₁ - σ√T</code>

                  <v-divider class="my-3"></v-divider>

                  <h4>Greeks 說明</h4>
                  <v-table density="compact">
                    <tbody>
                      <tr>
                        <td><strong>Delta (Δ)</strong></td>
                        <td>標的價格變動 $1 時，選擇權價格的變動量</td>
                      </tr>
                      <tr>
                        <td><strong>Gamma (Γ)</strong></td>
                        <td>Delta 的變動率，衡量 Delta 對標的價格的敏感度</td>
                      </tr>
                      <tr>
                        <td><strong>Theta (Θ)</strong></td>
                        <td>每日時間價值衰減量（通常為負值）</td>
                      </tr>
                      <tr>
                        <td><strong>Vega (ν)</strong></td>
                        <td>波動率變動 1% 時，選擇權價格的變動量</td>
                      </tr>
                      <tr>
                        <td><strong>Rho (ρ)</strong></td>
                        <td>利率變動 1% 時，選擇權價格的變動量</td>
                      </tr>
                    </tbody>
                  </v-table>
                </div>
              </v-expansion-panel-text>
            </v-expansion-panel>
          </v-expansion-panels>
        </v-col>
      </v-row>
    </v-container>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, nextTick, watch } from 'vue'
import axios from 'axios'
import Chart from 'chart.js/auto'

// ========================================
// 狀態定義
// ========================================
const mode = ref('price') // 'price' | 'iv'
const loading = ref(false)
const presetLoading = ref(null) // 記錄哪個預設按鈕正在載入
const result = ref(null)
const chartTab = ref('sensitivity')

// 市場數據資訊提示
const marketDataInfo = ref(null)

// 圖表實例
const sensitivityChart = ref(null)
const payoffChart = ref(null)
const timeDecayChart = ref(null)
const chartInstances = reactive({
  sensitivity: null,
  payoff: null,
  timeDecay: null
})

// 輸入參數
const params = reactive({
  optionType: 'call',
  spotPrice: 22000,
  strikePrice: 22000,
  timeToExpiry: 0.0822, // 預設約30天
  riskFreeRate: 1.5,    // %
  volatility: 18,       // %
  marketPrice: 150      // 用於 IV 計算
})

// 日期相關
const expiryDate = ref('')
const daysRemaining = ref(30)

// ========================================
// 計算屬性
// ========================================

// Greeks 列表（含說明）
const greeksList = computed(() => {
  if (!result.value?.greeks) return []
  
  return [
    {
      name: 'DELTA',
      value: result.value.greeks.delta,
      description: '標的價格變動 $1 時的選擇權價格變動。Call 介於 0~1，Put 介於 -1~0'
    },
    {
      name: 'GAMMA',
      value: result.value.greeks.gamma,
      description: 'Delta 的變動率。ATM 選擇權 Gamma 最大，適合 Gamma Scalping'
    },
    {
      name: 'THETA',
      value: result.value.greeks.theta,
      description: '每日時間價值衰減。買方不利（負值），賣方有利'
    },
    {
      name: 'VEGA',
      value: result.value.greeks.vega,
      description: '波動率上升 1% 時的價格變動。高波動率時選擇權更貴'
    },
    {
      name: 'RHO',
      value: result.value.greeks.rho,
      description: '利率上升 1% 時的價格變動。對短期選擇權影響較小'
    }
  ]
})

// 結果卡片背景色
const resultCardColor = computed(() => {
  if (!result.value) return 'white'
  return params.optionType === 'call' ? 'red-lighten-5' : 'green-lighten-5'
})

// ========================================
// 快速預設功能（核心改進）
// ========================================

/**
 * 套用預設值 - 自動從 API 抓取即時市場數據
 */
const applyPreset = async (preset) => {
  presetLoading.value = preset
  marketDataInfo.value = null

  try {
    // 1. 嘗試從 API 取得即時市場數據
    let currentPrice = null
    let marketIV = null
    let isLiveData = false

    try {
      // 呼叫 market-iv API 取得即時數據
      const response = await axios.get('/api/volatility/market-iv/1')
      
      if (response.data.success && response.data.data) {
        const data = response.data.data
        
        // 取得股價（嘗試多種來源）
        if (data.stock?.current_price) {
          currentPrice = data.stock.current_price
        } else if (data.stock_price) {
          currentPrice = data.stock_price
        }
        
        // 取得市場 IV
        if (data.real_iv) {
          marketIV = data.real_iv
        } else if (data.txo_iv) {
          marketIV = data.txo_iv
        }

        isLiveData = true
        
        marketDataInfo.value = {
          isLive: true,
          message: `即時數據 (${data.data_date || '今日'}) - IV: ${(marketIV * 100).toFixed(1)}%`
        }
      }
    } catch (apiError) {
      console.warn('無法取得即時市場數據，將使用預設值', apiError)
    }

    // 2. 如果 API 沒有返回價格，嘗試從儀表板取得
    if (!currentPrice) {
      try {
        const dashboardResponse = await axios.get('/api/dashboard/stats')
        if (dashboardResponse.data.success && dashboardResponse.data.data?.market_index) {
          currentPrice = dashboardResponse.data.data.market_index.taiex || 22000
        }
      } catch (e) {
        console.warn('無法從儀表板取得數據')
      }
    }

    // 3. 如果還是沒有數據，使用合理的預設值
    if (!currentPrice) {
      currentPrice = 22000 // 台指期貨的合理預設值
      marketDataInfo.value = {
        isLive: false,
        message: '使用預設值（無法連接即時數據）'
      }
    }

    if (!marketIV) {
      marketIV = 0.18 // 18% 是台指選擇權的常見 IV
    }

    // 4. 根據預設類型設定參數
    // 履約價取整到 100 點（台指選擇權的標準間距）
    const atmStrike = Math.round(currentPrice / 100) * 100

    switch (preset) {
      case 'txo_atm':
        // 價平：履約價 = 現價（取整）
        params.spotPrice = currentPrice
        params.strikePrice = atmStrike
        params.volatility = Math.round(marketIV * 100 * 10) / 10 // 保留一位小數
        params.optionType = 'call'
        break

      case 'txo_otm_call':
        // 價外 Call：履約價高於現價 500 點
        params.spotPrice = currentPrice
        params.strikePrice = atmStrike + 500
        // OTM Call 的 IV 通常比 ATM 稍高（波動率微笑效應）
        params.volatility = Math.round((marketIV + 0.02) * 100 * 10) / 10
        params.optionType = 'call'
        break

      case 'txo_otm_put':
        // 價外 Put：履約價低於現價 500 點
        params.spotPrice = currentPrice
        params.strikePrice = atmStrike - 500
        // OTM Put 的 IV 通常更高（波動率偏斜效應）
        params.volatility = Math.round((marketIV + 0.04) * 100 * 10) / 10
        params.optionType = 'put'
        break
    }

    // 5. 設定到期日為下個月結算日（第三個週三）
    setNextSettlementDate()

  } catch (error) {
    console.error('套用預設值失敗:', error)
    
    // 發生錯誤時使用硬編碼的預設值
    applyFallbackPreset(preset)
    
    marketDataInfo.value = {
      isLive: false,
      message: '使用備用預設值'
    }
  } finally {
    presetLoading.value = null
  }
}

/**
 * 備用預設值（當 API 完全無法連接時使用）
 */
const applyFallbackPreset = (preset) => {
  switch (preset) {
    case 'txo_atm':
      params.spotPrice = 22000
      params.strikePrice = 22000
      params.volatility = 18
      params.optionType = 'call'
      break
    case 'txo_otm_call':
      params.spotPrice = 22000
      params.strikePrice = 22500
      params.volatility = 20
      params.optionType = 'call'
      break
    case 'txo_otm_put':
      params.spotPrice = 22000
      params.strikePrice = 21500
      params.volatility = 22
      params.optionType = 'put'
      break
  }
  
  // 設定 30 天後到期
  const futureDate = new Date()
  futureDate.setDate(futureDate.getDate() + 30)
  expiryDate.value = futureDate.toISOString().split('T')[0]
  updateTimeToExpiry()
}

/**
 * 計算下個月結算日（每月第三個週三）
 */
const setNextSettlementDate = () => {
  const today = new Date()
  let year = today.getFullYear()
  let month = today.getMonth()

  // 計算本月第三個週三
  let thirdWednesday = getThirdWednesday(year, month)

  // 如果今天已經過了本月結算日，則計算下個月
  if (today >= thirdWednesday) {
    month++
    if (month > 11) {
      month = 0
      year++
    }
    thirdWednesday = getThirdWednesday(year, month)
  }

  // 格式化日期
  expiryDate.value = thirdWednesday.toISOString().split('T')[0]
  updateTimeToExpiry()
}

/**
 * 取得指定月份的第三個週三
 */
const getThirdWednesday = (year, month) => {
  // 從該月第一天開始
  const firstDay = new Date(year, month, 1)
  
  // 找到第一個週三
  let dayOfWeek = firstDay.getDay()
  let daysUntilWednesday = (3 - dayOfWeek + 7) % 7 // 3 = 週三
  
  // 第一個週三的日期
  let firstWednesday = 1 + daysUntilWednesday
  
  // 第三個週三 = 第一個週三 + 14 天
  let thirdWednesdayDate = firstWednesday + 14
  
  return new Date(year, month, thirdWednesdayDate)
}

// ========================================
// 其他方法
// ========================================

// 更新到期時間
const updateTimeToExpiry = () => {
  if (!expiryDate.value) return
  
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  const target = new Date(expiryDate.value)
  const diffTime = target - today
  const days = Math.ceil(diffTime / (1000 * 60 * 60 * 24))
  
  daysRemaining.value = days > 0 ? days : 0
  params.timeToExpiry = days > 0 ? days / 365 : 0.001
}

// Moneyness 相關
const getMoneynessColor = (val) => {
  if (val === 'ITM') return 'success'
  if (val === 'ATM') return 'warning'
  return 'error' // OTM
}

const getMoneynessIcon = (val) => {
  if (val === 'ITM') return 'mdi-check-circle'
  if (val === 'ATM') return 'mdi-minus-circle'
  return 'mdi-close-circle'
}

const getMoneynessLabel = (val) => {
  if (val === 'ITM') return '價內'
  if (val === 'ATM') return '價平'
  return '價外'
}

// 數值顏色
const getValueColor = (val) => {
  if (val > 0) return 'text-success'
  if (val < 0) return 'text-error'
  return ''
}

// 格式化 Greeks 顯示（改進精度）
const formatGreek = (name, value) => {
  if (name === 'GAMMA') {
    // Gamma 顯示 6 位小數或科學記號
    if (Math.abs(value) < 0.0001) {
      return value.toExponential(2)
    }
    return value.toFixed(6)
  }
  if (name === 'DELTA') {
    return value.toFixed(4)
  }
  return Number(value).toFixed(4)
}

// ========================================
// 核心計算 API
// ========================================
const calculate = async () => {
  loading.value = true
  result.value = null

  try {
    let url = ''
    let payload = {
      spot_price: params.spotPrice,
      strike_price: params.strikePrice,
      time_to_expiry: params.timeToExpiry,
      risk_free_rate: params.riskFreeRate / 100, // 轉小數
      option_type: params.optionType,
    }

    if (mode.value === 'price') {
      url = 'black-scholes/calculate'
      payload.volatility = params.volatility / 100
    } else {
      url = 'black-scholes/implied-volatility'
      payload.market_price = params.marketPrice
    }

    const response = await axios.post(url, payload)

    if (response.data.success) {
      result.value = response.data.data

      // 如果是價格模式，更新所有圖表
      if (mode.value === 'price') {
        await nextTick()
        updateAllCharts()
      }
    }
  } catch (error) {
    console.error('計算錯誤:', error)
    alert('計算失敗，請檢查參數或網路連線')
  } finally {
    loading.value = false
  }
}

// ========================================
// 圖表繪製
// ========================================

// 更新所有圖表
const updateAllCharts = () => {
  updateSensitivityChart()
  updatePayoffChart()
  updateTimeDecayChart()
}

// 價格敏感度圖表
const updateSensitivityChart = () => {
  if (!sensitivityChart.value || !result.value) return

  if (chartInstances.sensitivity) {
    chartInstances.sensitivity.destroy()
  }

  const currentSpot = params.spotPrice
  const labels = []
  const dataPoints = []
  const volatility = params.volatility / 100
  const riskFreeRate = params.riskFreeRate / 100

  // 產生股價範圍 (±15%)
  for (let i = -15; i <= 15; i += 1.5) {
    const percentChange = i / 100
    const spot = Math.round(currentSpot * (1 + percentChange))
    labels.push(spot)

    const price = calculateBSPrice(
      spot,
      params.strikePrice,
      params.timeToExpiry,
      riskFreeRate,
      volatility,
      params.optionType
    )
    dataPoints.push(price)
  }

  const ctx = sensitivityChart.value.getContext('2d')
  const lineColor = params.optionType === 'call' ? '#D32F2F' : '#388E3C'

  chartInstances.sensitivity = new Chart(ctx, {
    type: 'line',
    data: {
      labels: labels,
      datasets: [{
        label: '選擇權理論價格',
        data: dataPoints,
        borderColor: lineColor,
        backgroundColor: lineColor + '20',
        borderWidth: 3,
        fill: true,
        tension: 0.4,
        pointRadius: 0,
        pointHoverRadius: 6
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: {
        intersect: false,
        mode: 'index'
      },
      plugins: {
        legend: {
          display: true,
          position: 'top'
        },
        tooltip: {
          callbacks: {
            label: (context) => `價格: $${context.parsed.y.toFixed(2)}`
          }
        }
      },
      scales: {
        x: {
          title: {
            display: true,
            text: '標的價格'
          }
        },
        y: {
          title: {
            display: true,
            text: '選擇權價格'
          },
          beginAtZero: true
        }
      }
    }
  })
}

// 到期損益圖表
const updatePayoffChart = () => {
  if (!payoffChart.value || !result.value) return

  if (chartInstances.payoff) {
    chartInstances.payoff.destroy()
  }

  const premium = result.value.theoretical_price
  const strike = params.strikePrice
  const currentSpot = params.spotPrice
  
  const labels = []
  const payoffs = []
  const colors = []

  for (let i = -20; i <= 20; i += 2) {
    const spot = Math.round(currentSpot * (1 + i / 100))
    labels.push(spot)

    let payoff
    if (params.optionType === 'call') {
      payoff = Math.max(0, spot - strike) - premium
    } else {
      payoff = Math.max(0, strike - spot) - premium
    }
    
    payoffs.push(payoff)
    colors.push(payoff >= 0 ? 'rgba(76, 175, 80, 0.7)' : 'rgba(244, 67, 54, 0.7)')
  }

  const ctx = payoffChart.value.getContext('2d')

  chartInstances.payoff = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [{
        label: '到期損益',
        data: payoffs,
        backgroundColor: colors,
        borderColor: colors.map(c => c.replace('0.7', '1')),
        borderWidth: 1
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          display: true,
          position: 'top'
        },
        tooltip: {
          callbacks: {
            label: (context) => {
              const val = context.parsed.y
              return `損益: ${val >= 0 ? '+' : ''}$${val.toFixed(2)}`
            }
          }
        }
      },
      scales: {
        x: {
          title: {
            display: true,
            text: '到期時標的價格'
          }
        },
        y: {
          title: {
            display: true,
            text: '損益'
          }
        }
      }
    }
  })
}

// 時間衰減圖表
const updateTimeDecayChart = () => {
  if (!timeDecayChart.value || !result.value) return

  if (chartInstances.timeDecay) {
    chartInstances.timeDecay.destroy()
  }

  const volatility = params.volatility / 100
  const riskFreeRate = params.riskFreeRate / 100
  const totalDays = daysRemaining.value

  const labels = []
  const prices = []

  for (let day = totalDays; day >= 1; day -= Math.max(1, Math.floor(totalDays / 15))) {
    labels.push(day)
    
    const timeToExpiry = day / 365
    const price = calculateBSPrice(
      params.spotPrice,
      params.strikePrice,
      timeToExpiry,
      riskFreeRate,
      volatility,
      params.optionType
    )
    prices.push(price)
  }

  if (labels[labels.length - 1] !== 1) {
    labels.push(1)
    const price = calculateBSPrice(
      params.spotPrice,
      params.strikePrice,
      1 / 365,
      riskFreeRate,
      volatility,
      params.optionType
    )
    prices.push(price)
  }

  labels.reverse()
  prices.reverse()

  const ctx = timeDecayChart.value.getContext('2d')

  chartInstances.timeDecay = new Chart(ctx, {
    type: 'line',
    data: {
      labels: labels,
      datasets: [{
        label: '選擇權價值',
        data: prices,
        borderColor: '#FF9800',
        backgroundColor: 'rgba(255, 152, 0, 0.2)',
        borderWidth: 3,
        fill: true,
        tension: 0.4,
        pointRadius: 3,
        pointBackgroundColor: '#FF9800'
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          display: true,
          position: 'top'
        },
        tooltip: {
          callbacks: {
            title: (items) => `剩餘 ${items[0].label} 天`,
            label: (context) => `價值: $${context.parsed.y.toFixed(2)}`
          }
        }
      },
      scales: {
        x: {
          title: {
            display: true,
            text: '剩餘天數'
          },
          reverse: true
        },
        y: {
          title: {
            display: true,
            text: '選擇權價值'
          },
          beginAtZero: true
        }
      }
    }
  })
}

// ========================================
// Black-Scholes 前端計算（用於圖表）
// ========================================
const calculateBSPrice = (S, K, T, r, sigma, type) => {
  const d1 = (Math.log(S / K) + (r + 0.5 * sigma * sigma) * T) / (sigma * Math.sqrt(T))
  const d2 = d1 - sigma * Math.sqrt(T)

  if (type === 'call') {
    return S * normalCDF(d1) - K * Math.exp(-r * T) * normalCDF(d2)
  } else {
    return K * Math.exp(-r * T) * normalCDF(-d2) - S * normalCDF(-d1)
  }
}

const normalCDF = (x) => {
  const a1 = 0.254829592
  const a2 = -0.284496736
  const a3 = 1.421413741
  const a4 = -1.453152027
  const a5 = 1.061405429
  const p = 0.3275911

  const sign = x < 0 ? -1 : 1
  const absX = Math.abs(x) / Math.sqrt(2)

  const t = 1.0 / (1.0 + p * absX)
  const y = 1.0 - ((((a5 * t + a4) * t + a3) * t + a2) * t + a1) * t * Math.exp(-absX * absX)

  return 0.5 * (1.0 + sign * y)
}

// ========================================
// 匯出功能
// ========================================
const exportResult = () => {
  if (!result.value) return

  const data = [
    ['Black-Scholes 計算結果'],
    [''],
    ['參數設定'],
    ['選擇權類型', params.optionType === 'call' ? 'Call 買權' : 'Put 賣權'],
    ['標的現價 (S)', params.spotPrice],
    ['履約價 (K)', params.strikePrice],
    ['剩餘天數', daysRemaining.value],
    ['年化時間 (T)', params.timeToExpiry.toFixed(4)],
    ['無風險利率 (r)', params.riskFreeRate + '%'],
    ['波動率 (σ)', params.volatility + '%'],
    [''],
    ['計算結果'],
    ['理論價格', '$' + result.value.theoretical_price],
    ['價性 (Moneyness)', result.value.moneyness],
    [''],
    ['Greeks'],
    ['Delta', result.value.greeks?.delta],
    ['Gamma', result.value.greeks?.gamma],
    ['Theta', result.value.greeks?.theta],
    ['Vega', result.value.greeks?.vega],
    ['Rho', result.value.greeks?.rho]
  ]

  const csvContent = data.map(row => row.join(',')).join('\n')
  const blob = new Blob(['\uFEFF' + csvContent], { type: 'text/csv;charset=utf-8;' })
  const link = document.createElement('a')
  const url = URL.createObjectURL(blob)
  
  link.setAttribute('href', url)
  link.setAttribute('download', `black_scholes_${new Date().toISOString().slice(0, 10)}.csv`)
  link.style.visibility = 'hidden'
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
}

// ========================================
// 監聽器
// ========================================
watch(chartTab, async (newTab) => {
  if (!result.value) return
  
  await nextTick()
  
  switch (newTab) {
    case 'sensitivity':
      updateSensitivityChart()
      break
    case 'payoff':
      updatePayoffChart()
      break
    case 'timedecay':
      updateTimeDecayChart()
      break
  }
})

// ========================================
// 生命週期
// ========================================
onMounted(() => {
  // 設定預設到期日為下個月結算日
  setNextSettlementDate()
})
</script>

<style scoped>
.black-scholes-page {
  min-height: 100vh;
  background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
  padding-bottom: 40px;
}

.greek-card {
  transition: transform 0.2s, box-shadow 0.2s;
}

.greek-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.greek-label {
  cursor: help;
}

.formula-section h4 {
  color: #1976D2;
  font-size: 0.9rem;
  margin-bottom: 8px;
}

.formula {
  display: block;
  background: #f5f5f5;
  padding: 8px 12px;
  border-radius: 4px;
  font-family: 'Consolas', 'Monaco', monospace;
  font-size: 0.9rem;
  margin-bottom: 8px;
  border-left: 3px solid #1976D2;
}

.w-100 {
  width: 100%;
}
</style>