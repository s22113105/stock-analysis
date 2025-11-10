<template>
  <div class="model-parameters">
    <!-- LSTM 參數 -->
    <v-row v-if="modelType === 'lstm'">
      <v-col cols="12" md="6">
        <v-text-field
          v-model.number="localParams.epochs"
          label="訓練輪數 (Epochs)"
          type="number"
          min="10"
          max="1000"
          outlined
          dense
          hint="建議值：100-500"
          persistent-hint
        />
      </v-col>

      <v-col cols="12" md="6">
        <v-select
          v-model.number="localParams.units"
          :items="[32, 64, 128, 256, 512]"
          label="LSTM 單元數"
          outlined
          dense
          hint="較大的值可能提高準確度但增加訓練時間"
          persistent-hint
        />
      </v-col>

      <v-col cols="12" md="6">
        <v-slider
          v-model.number="localParams.dropout"
          label="Dropout 比率"
          min="0"
          max="0.5"
          step="0.1"
          thumb-label
          hint="防止過擬合"
          persistent-hint
        />
      </v-col>

      <v-col cols="12" md="6">
        <v-text-field
          v-model.number="localParams.lookback"
          label="回顧期間（天）"
          type="number"
          min="30"
          max="120"
          outlined
          dense
          hint="使用過去多少天的資料進行預測"
          persistent-hint
        />
      </v-col>
    </v-row>

    <!-- ARIMA 參數 -->
    <v-row v-else-if="modelType === 'arima'">
      <v-col cols="12">
        <v-switch
          v-model="localParams.auto_select"
          label="自動選擇最佳參數"
          color="primary"
        />
      </v-col>

      <template v-if="!localParams.auto_select">
        <v-col cols="12" md="4">
          <v-text-field
            v-model.number="localParams.p"
            label="AR 階數 (p)"
            type="number"
            min="0"
            max="5"
            outlined
            dense
            hint="自回歸項數"
            persistent-hint
          />
        </v-col>

        <v-col cols="12" md="4">
          <v-text-field
            v-model.number="localParams.d"
            label="差分階數 (d)"
            type="number"
            min="0"
            max="2"
            outlined
            dense
            hint="使序列平穩的差分次數"
            persistent-hint
          />
        </v-col>

        <v-col cols="12" md="4">
          <v-text-field
            v-model.number="localParams.q"
            label="MA 階數 (q)"
            type="number"
            min="0"
            max="5"
            outlined
            dense
            hint="移動平均項數"
            persistent-hint
          />
        </v-col>
      </template>
    </v-row>

    <!-- GARCH 參數 -->
    <v-row v-else-if="modelType === 'garch'">
      <v-col cols="12" md="4">
        <v-text-field
          v-model.number="localParams.p"
          label="GARCH 階數 (p)"
          type="number"
          min="1"
          max="3"
          outlined
          dense
          hint="GARCH 項數"
          persistent-hint
        />
      </v-col>

      <v-col cols="12" md="4">
        <v-text-field
          v-model.number="localParams.q"
          label="ARCH 階數 (q)"
          type="number"
          min="1"
          max="3"
          outlined
          dense
          hint="ARCH 項數"
          persistent-hint
        />
      </v-col>

      <v-col cols="12" md="4">
        <v-select
          v-model="localParams.dist"
          :items="distributionOptions"
          label="誤差分配"
          outlined
          dense
          hint="誤差項的分配假設"
          persistent-hint
        />
      </v-col>
    </v-row>

    <!-- Monte Carlo 參數 -->
    <v-row v-else-if="modelType === 'monte_carlo'">
      <v-col cols="12" md="6">
        <v-slider
          v-model.number="localParams.simulations"
          label="模擬次數"
          min="100"
          max="10000"
          step="100"
          thumb-label
          hint="更多模擬次數提供更穩定的結果"
          persistent-hint
        />
      </v-col>

      <v-col cols="12" md="6">
        <v-select
          v-model="localParams.distribution"
          :items="[
            { text: '常態分配', value: 'normal' },
            { text: '對數常態分配', value: 'lognormal' },
            { text: 't 分配', value: 't' }
          ]"
          label="報酬率分配"
          outlined
          dense
          hint="報酬率的分配假設"
          persistent-hint
        />
      </v-col>

      <v-col cols="12" md="6">
        <v-switch
          v-model="localParams.use_historical_volatility"
          label="使用歷史波動率"
          color="primary"
        />
      </v-col>

      <v-col cols="12" md="6" v-if="!localParams.use_historical_volatility">
        <v-slider
          v-model.number="localParams.volatility"
          label="年化波動率 (%)"
          min="10"
          max="100"
          step="5"
          thumb-label
          persistent-hint
        />
      </v-col>
    </v-row>

    <!-- 共用參數 -->
    <v-row>
      <v-col cols="12">
        <v-divider class="my-4" />
      </v-col>

      <v-col cols="12" md="6">
        <v-slider
          v-model.number="localParams.confidence_level"
          label="信賴水準 (%)"
          min="90"
          max="99"
          step="1"
          thumb-label
          hint="預測區間的信賴水準"
          persistent-hint
        />
      </v-col>

      <v-col cols="12" md="6">
        <v-switch
          v-model="localParams.include_validation"
          label="包含驗證資料"
          color="primary"
          hint="使用部分歷史資料進行模型驗證"
        />
      </v-col>
    </v-row>

    <!-- 按鈕區 -->
    <v-row>
      <v-col cols="12" class="text-right">
        <v-btn
          text
          @click="resetParameters"
        >
          重設為預設值
        </v-btn>

        <v-btn
          color="primary"
          @click="applyParameters"
          :disabled="!isValid"
        >
          套用參數
        </v-btn>
      </v-col>
    </v-row>
  </div>
</template>

<script>
import { ref, watch, computed } from 'vue'

export default {
  name: 'ModelParameters',

  props: {
    modelType: {
      type: String,
      required: true
    },
    modelValue: {
      type: Object,
      default: () => ({})
    }
  },

  emits: ['update:modelValue'],

  setup(props, { emit }) {
    // 本地參數副本
    const localParams = ref({...getDefaultParams(props.modelType)})

    // 分配選項
    const distributionOptions = [
      { text: '常態分配', value: 'normal' },
      { text: 't 分配', value: 't' },
      { text: '偏斜 t 分配', value: 'skewt' }
    ]

    // 取得預設參數
    function getDefaultParams(type) {
      const defaults = {
        lstm: {
          epochs: 100,
          units: 128,
          dropout: 0.2,
          lookback: 60,
          confidence_level: 95,
          include_validation: true
        },
        arima: {
          auto_select: true,
          p: 1,
          d: 1,
          q: 1,
          confidence_level: 95,
          include_validation: true
        },
        garch: {
          p: 1,
          q: 1,
          dist: 'normal',
          confidence_level: 95,
          include_validation: true
        },
        monte_carlo: {
          simulations: 1000,
          distribution: 'normal',
          use_historical_volatility: true,
          volatility: 30,
          confidence_level: 95,
          include_validation: false
        }
      }

      return defaults[type] || {}
    }

    // 驗證參數
    const isValid = computed(() => {
      if (props.modelType === 'lstm') {
        return localParams.value.epochs >= 10 &&
               localParams.value.units > 0 &&
               localParams.value.lookback >= 30
      }

      if (props.modelType === 'arima' && !localParams.value.auto_select) {
        return localParams.value.p >= 0 &&
               localParams.value.d >= 0 &&
               localParams.value.q >= 0
      }

      if (props.modelType === 'garch') {
        return localParams.value.p >= 1 &&
               localParams.value.q >= 1
      }

      if (props.modelType === 'monte_carlo') {
        return localParams.value.simulations >= 100
      }

      return true
    })

    // 重設參數
    const resetParameters = () => {
      localParams.value = {...getDefaultParams(props.modelType)}
      emit('update:modelValue', localParams.value)
    }

    // 套用參數
    const applyParameters = () => {
      emit('update:modelValue', {...localParams.value})
    }

    // 監聽模型類型變化
    watch(() => props.modelType, (newType) => {
      localParams.value = {...getDefaultParams(newType)}
    })

    // 監聽本地參數變化（自動套用）
    watch(localParams, (newParams) => {
      emit('update:modelValue', {...newParams})
    }, { deep: true })

    return {
      localParams,
      distributionOptions,
      isValid,
      resetParameters,
      applyParameters
    }
  }
}
</script>

<style scoped>
.model-parameters {
  padding: 16px 0;
}
</style>
