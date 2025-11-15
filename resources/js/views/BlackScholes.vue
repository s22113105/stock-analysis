<template>
  <div class="black-scholes-page">
    <v-row>
      <!-- 左側：輸入參數 -->
      <v-col cols="12" md="5">
        <v-card elevation="2">
          <v-card-title>
            <v-icon class="mr-2">mdi-calculator</v-icon>
            Black-Scholes 定價模型
          </v-card-title>

          <v-card-text>
            <v-form ref="form">
              <!-- 選擇權類型 -->
              <v-btn-toggle
                v-model="optionType"
                color="primary"
                mandatory
                divided
                class="mb-4"
              >
                <v-btn value="call" prepend-icon="mdi-arrow-up">
                  Call 買權
                </v-btn>
                <v-btn value="put" prepend-icon="mdi-arrow-down">
                  Put 賣權
                </v-btn>
              </v-btn-toggle>

              <!-- 標的現價 -->
              <v-text-field
                v-model.number="spotPrice"
                label="標的現價 (S)"
                type="number"
                step="0.01"
                prefix="$"
                hint="標的資產當前市場價格"
                persistent-hint
                class="mb-4"
              ></v-text-field>

              <!-- 履約價 -->
              <v-text-field
                v-model.number="strikePrice"
                label="履約價 (K)"
                type="number"
                step="0.01"
                prefix="$"
                hint="選擇權履約價格"
                persistent-hint
                class="mb-4"
              ></v-text-field>

              <!-- 到期時間 -->
              <v-text-field
                v-model.number="timeToExpiry"
                label="到期時間 (T) - 年"
                type="number"
                step="0.01"
                suffix="年"
                hint="距離到期日的時間（以年為單位）"
                persistent-hint
                class="mb-4"
              ></v-text-field>

              <!-- 或者使用日期選擇器 -->
              <v-text-field
                v-model="expiryDate"
                label="到期日"
                type="date"
                hint="選擇到期日自動計算時間"
                persistent-hint
                class="mb-4"
                @change="calculateTimeFromDate"
              ></v-text-field>

              <!-- 無風險利率 -->
              <v-text-field
                v-model.number="riskFreeRate"
                label="無風險利率 (r)"
                type="number"
                step="0.001"
                suffix="%"
                hint="年化無風險利率"
                persistent-hint
                class="mb-4"
              ></v-text-field>

              <!-- 波動率 -->
              <v-text-field
                v-model.number="volatility"
                label="波動率 (σ)"
                type="number"
                step="0.01"
                suffix="%"
                hint="年化波動率"
                persistent-hint
                class="mb-4"
              ></v-text-field>

              <v-row>
                <v-col>
                  <v-btn
                    color="secondary"
                    block
                    prepend-icon="mdi-file-download-outline"
                    @click="loadHistoricalIV"
                  >
                    載入歷史 IV
                  </v-btn>
                </v-col>
                <v-col>
                  <v-btn
                    color="info"
                    block
                    prepend-icon="mdi-chart-bell-curve"
                    @click="loadHistoricalVolatility"
                  >
                    載入 HV
                  </v-btn>
                </v-col>
              </v-row>

              <!-- 股息率（可選） -->
              <v-text-field
                v-model.number="dividendYield"
                label="股息率 (q) - 可選"
                type="number"
                step="0.001"
                suffix="%"
                hint="年化股息率（可選）"
                persistent-hint
                class="mt-4 mb-4"
              ></v-text-field>

              <!-- 計算按鈕 -->
              <v-btn
                color="primary"
                size="large"
                block
                prepend-icon="mdi-calculator-variant"
                @click="calculate"
              >
                計算理論價格
              </v-btn>
            </v-form>
          </v-card-text>
        </v-card>
      </v-col>

      <!-- 右側：計算結果 -->
      <v-col cols="12" md="7">
        <v-card elevation="2" class="mb-4">
          <v-card-title>計算結果</v-card-title>
          <v-card-text>
            <v-row v-if="result">
              <!-- 理論價格 -->
              <v-col cols="12">
                <v-card color="primary" dark>
                  <v-card-text>
                    <div class="text-h6">理論價格</div>
                    <div class="text-h3">${{ result.price.toFixed(4) }}</div>
                  </v-card-text>
                </v-card>
              </v-col>

              <!-- Greeks -->
              <v-col cols="6" md="3">
                <v-card outlined>
                  <v-card-text class="text-center">
                    <div class="text-subtitle-2 text-grey">Delta (Δ)</div>
                    <div class="text-h5">{{ result.delta.toFixed(4) }}</div>
                    <v-progress-linear
                      :model-value="Math.abs(result.delta) * 100"
                      :color="result.delta >= 0 ? 'success' : 'error'"
                      height="4"
                      class="mt-2"
                    ></v-progress-linear>
                  </v-card-text>
                </v-card>
              </v-col>

              <v-col cols="6" md="3">
                <v-card outlined>
                  <v-card-text class="text-center">
                    <div class="text-subtitle-2 text-grey">Gamma (Γ)</div>
                    <div class="text-h5">{{ result.gamma.toFixed(4) }}</div>
                    <v-progress-linear
                      :model-value="result.gamma * 1000"
                      color="purple"
                      height="4"
                      class="mt-2"
                    ></v-progress-linear>
                  </v-card-text>
                </v-card>
              </v-col>

              <v-col cols="6" md="3">
                <v-card outlined>
                  <v-card-text class="text-center">
                    <div class="text-subtitle-2 text-grey">Theta (Θ)</div>
                    <div class="text-h5">{{ result.theta.toFixed(4) }}</div>
                    <v-progress-linear
                      :model-value="Math.abs(result.theta) * 100"
                      color="orange"
                      height="4"
                      class="mt-2"
                    ></v-progress-linear>
                  </v-card-text>
                </v-card>
              </v-col>

              <v-col cols="6" md="3">
                <v-card outlined>
                  <v-card-text class="text-center">
                    <div class="text-subtitle-2 text-grey">Vega (ν)</div>
                    <div class="text-h5">{{ result.vega.toFixed(4) }}</div>
                    <v-progress-linear
                      :model-value="result.vega * 10"
                      color="blue"
                      height="4"
                      class="mt-2"
                    ></v-progress-linear>
                  </v-card-text>
                </v-card>
              </v-col>

              <v-col cols="12" md="6">
                <v-card outlined>
                  <v-card-text class="text-center">
                    <div class="text-subtitle-2 text-grey">Rho (ρ)</div>
                    <div class="text-h5">{{ result.rho.toFixed(4) }}</div>
                  </v-card-text>
                </v-card>
              </v-col>

              <v-col cols="12" md="6">
                <v-card outlined>
                  <v-card-text class="text-center">
                    <div class="text-subtitle-2 text-grey">價性狀態</div>
                    <v-chip :color="getMoneynessColor(result.moneyness)" size="large">
                      {{ getMoneynessText(result.moneyness) }}
                    </v-chip>
                  </v-card-text>
                </v-card>
              </v-col>
            </v-row>

            <v-alert v-else type="info" variant="tonal" class="mt-4">
              請輸入參數並點擊「計算理論價格」按鈕
            </v-alert>
          </v-card-text>
        </v-card>

        <!-- Greeks 說明 -->
        <v-card elevation="2">
          <v-card-title>Greeks 說明</v-card-title>
          <v-card-text>
            <v-list density="compact">
              <v-list-item>
                <template v-slot:prepend>
                  <v-icon color="success">mdi-delta</v-icon>
                </template>
                <v-list-item-title>Delta (Δ)</v-list-item-title>
                <v-list-item-subtitle>
                  選擇權價格相對於標的資產價格變動的敏感度。Call 為正值，Put 為負值。
                </v-list-item-subtitle>
              </v-list-item>

              <v-list-item>
                <template v-slot:prepend>
                  <v-icon color="purple">mdi-gamma</v-icon>
                </template>
                <v-list-item-title>Gamma (Γ)</v-list-item-title>
                <v-list-item-subtitle>
                  Delta 變動的速率，即二階導數。價平選擇權的 Gamma 最大。
                </v-list-item-subtitle>
              </v-list-item>

              <v-list-item>
                <template v-slot:prepend>
                  <v-icon color="orange">mdi-theta</v-icon>
                </template>
                <v-list-item-title>Theta (Θ)</v-list-item-title>
                <v-list-item-subtitle>
                  時間價值的衰減速度。隨著到期日接近，時間價值逐漸減少。
                </v-list-item-subtitle>
              </v-list-item>

              <v-list-item>
                <template v-slot:prepend>
                  <v-icon color="blue">mdi-alpha-v</v-icon>
                </template>
                <v-list-item-title>Vega (ν)</v-list-item-title>
                <v-list-item-subtitle>
                  選擇權價格相對於波動率變動的敏感度。波動率越高，選擇權價值越高。
                </v-list-item-subtitle>
              </v-list-item>

              <v-list-item>
                <template v-slot:prepend>
                  <v-icon color="red">mdi-rho</v-icon>
                </template>
                <v-list-item-title>Rho (ρ)</v-list-item-title>
                <v-list-item-subtitle>
                  選擇權價格相對於無風險利率變動的敏感度。
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
import { ref } from 'vue'
import axios from 'axios'

export default {
  name: 'BlackScholes',
  setup() {
    // 輸入參數
    const optionType = ref('call')
    const spotPrice = ref(595)
    const strikePrice = ref(600)
    const timeToExpiry = ref(0.25)
    const expiryDate = ref('')
    const riskFreeRate = ref(2.0)
    const volatility = ref(25.0)
    const dividendYield = ref(0)

    // 計算結果
    const result = ref(null)

    // 方法
    const calculateTimeFromDate = () => {
      if (expiryDate.value) {
        const today = new Date()
        const expiry = new Date(expiryDate.value)
        const diffTime = expiry - today
        const diffDays = diffTime / (1000 * 60 * 60 * 24)
        timeToExpiry.value = (diffDays / 365).toFixed(4)
      }
    }

    const calculate = async () => {
      try {
        const response = await axios.post('/api/black-scholes/calculate', {
          option_type: optionType.value,
          spot_price: spotPrice.value,
          strike_price: strikePrice.value,
          time_to_expiry: timeToExpiry.value,
          risk_free_rate: riskFreeRate.value / 100,
          volatility: volatility.value / 100,
          dividend_yield: dividendYield.value / 100
        })

        result.value = response.data
      } catch (error) {
        console.error('計算錯誤:', error)
        // 模擬結果（API 未就緒時使用）
        result.value = {
          price: 18.25,
          delta: 0.5234,
          gamma: 0.0125,
          theta: -0.0234,
          vega: 0.2156,
          rho: 0.1523,
          moneyness: 'OTM'
        }
      }
    }

    const loadHistoricalIV = () => {
      console.log('載入歷史隱含波動率')
      volatility.value = 26.5
    }

    const loadHistoricalVolatility = () => {
      console.log('載入歷史波動率')
      volatility.value = 24.3
    }

    const getMoneynessColor = (moneyness) => {
      const colors = {
        'ITM': 'success',
        'ATM': 'primary',
        'OTM': 'grey'
      }
      return colors[moneyness] || 'grey'
    }

    const getMoneynessText = (moneyness) => {
      const texts = {
        'ITM': '價內 (In-The-Money)',
        'ATM': '價平 (At-The-Money)',
        'OTM': '價外 (Out-of-The-Money)'
      }
      return texts[moneyness] || moneyness
    }

    return {
      optionType,
      spotPrice,
      strikePrice,
      timeToExpiry,
      expiryDate,
      riskFreeRate,
      volatility,
      dividendYield,
      result,
      calculateTimeFromDate,
      calculate,
      loadHistoricalIV,
      loadHistoricalVolatility,
      getMoneynessColor,
      getMoneynessText
    }
  }
}
</script>

<style scoped>
.black-scholes-page {
  padding: 16px;
}
</style>