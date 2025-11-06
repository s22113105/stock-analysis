<template>
  <v-container fluid>
    <!-- 頁面標題 -->
    <v-row>
      <v-col cols="12">
        <h1 class="text-h4 font-weight-bold mb-2">
          <v-icon left>mdi-calculator</v-icon>
          Black-Scholes 選擇權定價
        </h1>
        <p class="text-subtitle-1 text-grey">
          計算選擇權理論價格、Greeks 與隱含波動率
        </p>
      </v-col>
    </v-row>

    <v-divider class="my-4"></v-divider>

    <v-row>
      <!-- 左側：輸入參數 -->
      <v-col cols="12" md="5">
        <v-card elevation="2">
          <v-card-title class="bg-primary text-white">
            <v-icon left>mdi-tune</v-icon>
            計算參數
          </v-card-title>

          <v-card-text class="pt-6">
            <v-form ref="form" v-model="formValid">
              <!-- 標的價格 -->
              <v-text-field
                v-model.number="params.spotPrice"
                label="標的價格 (S)"
                type="number"
                :rules="[rules.required, rules.positive]"
                prepend-icon="mdi-currency-usd"
                variant="outlined"
                density="comfortable"
              ></v-text-field>

              <!-- 履約價格 -->
              <v-text-field
                v-model.number="params.strikePrice"
                label="履約價格 (K)"
                type="number"
                :rules="[rules.required, rules.positive]"
                prepend-icon="mdi-target"
                variant="outlined"
                density="comfortable"
              ></v-text-field>

              <!-- 到期時間 -->
              <v-text-field
                v-model.number="params.timeToExpiry"
                label="到期時間 (年)"
                type="number"
                :rules="[rules.required, rules.positive]"
                prepend-icon="mdi-clock-outline"
                variant="outlined"
                density="comfortable"
                hint="例如: 0.25 = 3個月, 0.5 = 6個月"
                persistent-hint
              ></v-text-field>

              <!-- 無風險利率 -->
              <v-text-field
                v-model.number="params.riskFreeRate"
                label="無風險利率 (r)"
                type="number"
                :rules="[rules.required, rules.rate]"
                prepend-icon="mdi-percent"
                variant="outlined"
                density="comfortable"
                hint="例如: 0.015 = 1.5%"
                persistent-hint
              ></v-text-field>

              <!-- 波動率 -->
              <v-text-field
                v-model.number="params.volatility"
                label="波動率 (σ)"
                type="number"
                :rules="[rules.required, rules.volatility]"
                prepend-icon="mdi-chart-bell-curve"
                variant="outlined"
                density="comfortable"
                hint="例如: 0.3 = 30%"
                persistent-hint
              ></v-text-field>

              <!-- 選擇權類型 -->
              <v-radio-group v-model="params.optionType" inline>
                <template v-slot:label>
                  <div class="font-weight-bold">選擇權類型</div>
                </template>
                <v-radio label="Call (買權)" value="call"></v-radio>
                <v-radio label="Put (賣權)" value="put"></v-radio>
              </v-radio-group>

              <!-- 計算按鈕 -->
              <v-btn
                @click="calculate"
                :loading="loading"
                :disabled="!formValid"
                color="primary"
                size="large"
                block
                class="mt-4"
              >
                <v-icon left>mdi-calculator-variant</v-icon>
                立即計算
              </v-btn>
            </v-form>
          </v-card-text>
        </v-card>

        <!-- 快速範例 -->
        <v-card elevation="2" class="mt-4">
          <v-card-title>
            <v-icon left>mdi-lightning-bolt</v-icon>
            快速範例
          </v-card-title>
          <v-card-text>
            <v-btn
              v-for="example in examples"
              :key="example.name"
              @click="loadExample(example)"
              variant="outlined"
              color="secondary"
              class="mr-2 mb-2"
              size="small"
            >
              {{ example.name }}
            </v-btn>
          </v-card-text>
        </v-card>
      </v-col>

      <!-- 右側：計算結果 -->
      <v-col cols="12" md="7">
        <!-- 理論價格 -->
        <v-card elevation="2" v-if="result">
          <v-card-title class="bg-success text-white">
            <v-icon left>mdi-chart-line</v-icon>
            計算結果
          </v-card-title>

          <v-card-text class="pt-6">
            <!-- 理論價格 -->
            <v-row>
              <v-col cols="12">
                <div class="text-center pa-6 bg-grey-lighten-4 rounded">
                  <div class="text-h3 font-weight-bold text-primary">
                    ${{ result.theoretical_price.toFixed(4) }}
                  </div>
                  <div class="text-subtitle-1 text-grey">
                    選擇權理論價格
                  </div>
                </div>
              </v-col>
            </v-row>

            <!-- 價值分析 -->
            <v-row class="mt-4">
              <v-col cols="4">
                <v-card variant="outlined">
                  <v-card-text class="text-center">
                    <div class="text-h5 font-weight-bold">
                      ${{ result.intrinsic_value.toFixed(4) }}
                    </div>
                    <div class="text-caption text-grey">內在價值</div>
                  </v-card-text>
                </v-card>
              </v-col>
              <v-col cols="4">
                <v-card variant="outlined">
                  <v-card-text class="text-center">
                    <div class="text-h5 font-weight-bold">
                      ${{ result.time_value.toFixed(4) }}
                    </div>
                    <div class="text-caption text-grey">時間價值</div>
                  </v-card-text>
                </v-card>
              </v-col>
              <v-col cols="4">
                <v-card variant="outlined">
                  <v-card-text class="text-center">
                    <v-chip :color="getMoneynessColor(result.moneyness)" size="large">
                      {{ result.moneyness }}
                    </v-chip>
                    <div class="text-caption text-grey mt-2">價性</div>
                  </v-card-text>
                </v-card>
              </v-col>
            </v-row>
          </v-card-text>
        </v-card>

        <!-- Greeks -->
        <v-card elevation="2" class="mt-4" v-if="result && result.greeks">
          <v-card-title>
            <v-icon left>mdi-alpha</v-icon>
            Greeks 風險指標
          </v-card-title>

          <v-card-text>
            <v-row>
              <!-- Delta -->
              <v-col cols="12" sm="6">
                <v-card variant="outlined" color="indigo-lighten-5">
                  <v-card-text>
                    <div class="d-flex justify-space-between align-center">
                      <div>
                        <div class="text-overline">Delta (Δ)</div>
                        <div class="text-h5 font-weight-bold">
                          {{ result.greeks.delta.toFixed(5) }}
                        </div>
                        <div class="text-caption text-grey">
                          標的價格敏感度
                        </div>
                      </div>
                      <v-icon size="48" color="indigo">mdi-delta</v-icon>
                    </div>
                  </v-card-text>
                </v-card>
              </v-col>

              <!-- Gamma -->
              <v-col cols="12" sm="6">
                <v-card variant="outlined" color="purple-lighten-5">
                  <v-card-text>
                    <div class="d-flex justify-space-between align-center">
                      <div>
                        <div class="text-overline">Gamma (Γ)</div>
                        <div class="text-h5 font-weight-bold">
                          {{ result.greeks.gamma.toFixed(5) }}
                        </div>
                        <div class="text-caption text-grey">
                          Delta 的變化率
                        </div>
                      </div>
                      <v-icon size="48" color="purple">mdi-gamma</v-icon>
                    </div>
                  </v-card-text>
                </v-card>
              </v-col>

              <!-- Theta -->
              <v-col cols="12" sm="6">
                <v-card variant="outlined" color="orange-lighten-5">
                  <v-card-text>
                    <div class="d-flex justify-space-between align-center">
                      <div>
                        <div class="text-overline">Theta (Θ)</div>
                        <div class="text-h5 font-weight-bold">
                          {{ result.greeks.theta.toFixed(5) }}
                        </div>
                        <div class="text-caption text-grey">
                          時間價值衰減 (每日)
                        </div>
                      </div>
                      <v-icon size="48" color="orange">mdi-clock-fast</v-icon>
                    </div>
                  </v-card-text>
                </v-card>
              </v-col>

              <!-- Vega -->
              <v-col cols="12" sm="6">
                <v-card variant="outlined" color="cyan-lighten-5">
                  <v-card-text>
                    <div class="d-flex justify-space-between align-center">
                      <div>
                        <div class="text-overline">Vega (ν)</div>
                        <div class="text-h5 font-weight-bold">
                          {{ result.greeks.vega.toFixed(5) }}
                        </div>
                        <div class="text-caption text-grey">
                          波動率敏感度
                        </div>
                      </div>
                      <v-icon size="48" color="cyan">mdi-chart-bell-curve</v-icon>
                    </div>
                  </v-card-text>
                </v-card>
              </v-col>

              <!-- Rho -->
              <v-col cols="12">
                <v-card variant="outlined" color="green-lighten-5">
                  <v-card-text>
                    <div class="d-flex justify-space-between align-center">
                      <div>
                        <div class="text-overline">Rho (ρ)</div>
                        <div class="text-h5 font-weight-bold">
                          {{ result.greeks.rho.toFixed(5) }}
                        </div>
                        <div class="text-caption text-grey">
                          利率敏感度
                        </div>
                      </div>
                      <v-icon size="48" color="green">mdi-percent</v-icon>
                    </div>
                  </v-card-text>
                </v-card>
              </v-col>
            </v-row>
          </v-card-text>
        </v-card>

        <!-- 錯誤提示 -->
        <v-alert
          v-if="error"
          type="error"
          variant="tonal"
          class="mt-4"
          closable
          @click:close="error = null"
        >
          {{ error }}
        </v-alert>
      </v-col>
    </v-row>
  </v-container>
</template>

<script setup>
import { ref, reactive } from 'vue'
import axios from 'axios'

// 表單狀態
const form = ref(null)
const formValid = ref(false)
const loading = ref(false)
const result = ref(null)
const error = ref(null)

// 參數
const params = reactive({
  spotPrice: 100,
  strikePrice: 105,
  timeToExpiry: 0.25,
  riskFreeRate: 0.015,
  volatility: 0.3,
  optionType: 'call'
})

// 驗證規則
const rules = {
  required: value => !!value || '必填欄位',
  positive: value => value > 0 || '必須大於 0',
  rate: value => (value >= 0 && value <= 1) || '必須在 0 到 1 之間',
  volatility: value => (value > 0 && value <= 10) || '必須在 0 到 10 之間'
}

// 範例資料
const examples = [
  {
    name: '價平 Call',
    spotPrice: 100,
    strikePrice: 100,
    timeToExpiry: 0.25,
    riskFreeRate: 0.015,
    volatility: 0.3,
    optionType: 'call'
  },
  {
    name: '價內 Put',
    spotPrice: 100,
    strikePrice: 110,
    timeToExpiry: 0.5,
    riskFreeRate: 0.015,
    volatility: 0.25,
    optionType: 'put'
  },
  {
    name: '短期 Call',
    spotPrice: 18000,
    strikePrice: 18200,
    timeToExpiry: 0.02, // 約一週
    riskFreeRate: 0.015,
    volatility: 0.2,
    optionType: 'call'
  }
]

// 載入範例
const loadExample = (example) => {
  Object.assign(params, example)
}

// 計算
const calculate = async () => {
  if (!formValid.value) {
    return
  }

  loading.value = true
  error.value = null
  result.value = null

  try {
    const response = await axios.post('/api/black-scholes/calculate', {
      spot_price: params.spotPrice,
      strike_price: params.strikePrice,
      time_to_expiry: params.timeToExpiry,
      risk_free_rate: params.riskFreeRate,
      volatility: params.volatility,
      option_type: params.optionType
    })

    if (response.data.success) {
      result.value = response.data.data
    } else {
      error.value = response.data.message || '計算失敗'
    }
  } catch (err) {
    error.value = err.response?.data?.message || '網路錯誤，請稍後再試'
    console.error('計算錯誤:', err)
  } finally {
    loading.value = false
  }
}

// 取得價性顏色
const getMoneynessColor = (moneyness) => {
  switch (moneyness) {
    case 'ITM':
      return 'success'
    case 'ATM':
      return 'warning'
    case 'OTM':
      return 'error'
    default:
      return 'grey'
  }
}
</script>

<style scoped>
.bg-grey-lighten-4 {
  background-color: #f5f5f5;
}
</style>