<template>
  <div class="options-page">
    <v-row class="mb-4 align-center">
      <v-col>
        <h1 class="text-h4 font-weight-bold">選擇權分析</h1>
        <p class="text-subtitle-1 text-grey mt-1">TXO 臺指選擇權市場分析</p>
      </v-col>
      <v-col cols="auto">
        <v-btn color="primary" size="large" prepend-icon="mdi-refresh" @click="refreshAllData" :loading="loading" elevation="2">
          更新資料
        </v-btn>
      </v-col>
    </v-row>

    <v-row class="mb-4">
      <v-col cols="12">
        <v-card elevation="2" rounded="lg" class="h-100">
          <v-card-title class="d-flex align-center bg-grey-lighten-4 py-3">
            <v-icon color="primary" class="mr-2">mdi-heart-pulse</v-icon>
            <span class="text-h6">市場情緒總覽</span>
            <span v-if="sentiment?.date" class="text-caption text-grey ml-3">({{ sentiment.date }})</span>
            <v-spacer></v-spacer>
            <v-chip v-if="sentiment?.sentiment" :color="sentiment.sentiment.color" label class="font-weight-bold">
              {{ sentiment.sentiment.description }}
            </v-chip>
          </v-card-title>

          <v-card-text class="pa-4">
            <v-row v-if="sentiment" class="match-height">
              <v-col cols="12" sm="6" md="3" class="d-flex">
                <v-card variant="outlined" class="flex-grow-1 text-center py-6 rounded-lg info-card">
                  <div class="text-subtitle-2 text-grey mb-2">Put/Call Ratio</div>
                  <div class="text-h4 font-weight-bold">{{ sentiment.put_call_volume_ratio }}</div>
                </v-card>
              </v-col>
              <v-col cols="12" sm="6" md="3" class="d-flex">
                <v-card variant="outlined" class="flex-grow-1 text-center py-6 rounded-lg info-card">
                  <div class="text-subtitle-2 text-grey mb-2">平均隱含波動率 (IV)</div>
                  <div class="text-h4 font-weight-bold">
                    {{ (sentiment.avg_iv !== null) ? (sentiment.avg_iv * 100).toFixed(2) + '%' : '0.00%' }}
                  </div>
                </v-card>
              </v-col>
              <v-col cols="12" sm="6" md="3" class="d-flex">
                <v-card variant="outlined" class="flex-grow-1 text-center py-6 rounded-lg info-card">
                  <div class="text-subtitle-2 text-grey mb-2">今日總成交量</div>
                  <div class="text-h4 font-weight-bold text-primary">{{ formatNumber(sentiment.total_volume) }}</div>
                  <div class="text-caption text-grey mt-1">口</div>
                </v-card>
              </v-col>
              <v-col cols="12" sm="6" md="3" class="d-flex">
                <v-card variant="outlined" class="flex-grow-1 text-center py-6 rounded-lg info-card">
                  <div class="text-subtitle-2 text-grey mb-2">總未平倉量</div>
                  <div class="text-h4 font-weight-bold text-info">{{ formatNumber(sentiment.total_oi) }}</div>
                  <div class="text-caption text-grey mt-1">口</div>
                </v-card>
              </v-col>
            </v-row>

            <v-row v-else>
              <v-col class="text-center py-10">
                <v-progress-circular indeterminate color="primary" size="64"></v-progress-circular>
                <p class="mt-4 text-h6 text-grey">正在分析數據...</p>
              </v-col>
            </v-row>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <v-row>
      <v-col cols="12" md="6" class="d-flex">
        <v-card elevation="2" rounded="lg" class="flex-grow-1">
          <v-card-title class="py-3 px-4 border-bottom">TXO 平均收盤價走勢</v-card-title>
          <v-card-text class="pa-4">
            <div class="chart-container">
              <canvas ref="trendChart"></canvas>
            </div>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="6" class="d-flex">
        <v-card elevation="2" rounded="lg" class="flex-grow-1">
          <v-card-title class="py-3 px-4 border-bottom">Call vs Put 成交量</v-card-title>
          <v-card-text class="pa-4">
            <div class="chart-container">
              <canvas ref="volumeChart"></canvas>
            </div>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="6" class="d-flex">
        <v-card elevation="2" rounded="lg" class="flex-grow-1">
          <v-card-title class="py-3 px-4 border-bottom">隱含波動率 (IV) 趨勢</v-card-title>
          <v-card-text class="pa-4">
            <div class="chart-container">
              <canvas ref="ivChart"></canvas>
            </div>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="6" class="d-flex">
        <v-card elevation="2" rounded="lg" class="flex-grow-1">
          <v-card-title class="py-3 px-4 border-bottom">未平倉量 (OI) 分佈</v-card-title>
          <v-card-text class="pa-4">
            <div class="chart-container">
              <canvas ref="oiChart"></canvas>
            </div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>
  </div>
</template>

<script>
import { ref, onMounted, nextTick, shallowRef } from 'vue'
import axios from 'axios'
import Chart from 'chart.js/auto'

export default {
  name: 'Options',
  setup() {
    const loading = ref(false)
    const sentiment = ref(null)
    const trendChart = ref(null); const volumeChart = ref(null); const ivChart = ref(null); const oiChart = ref(null);
    const charts = { trend: shallowRef(null), volume: shallowRef(null), iv: shallowRef(null), oi: shallowRef(null) }

    const fetchData = async (endpoint, callback) => {
        try {
            const res = await axios.get(`/api/options/txo/${endpoint}`)
            const result = res.data.data || res.data
            if (result) {
                await nextTick()
                if (callback) callback(result)
            }
            return result
        } catch (e) { console.error(`API Error (${endpoint}):`, e) }
    }

    const drawTrend = (data) => {
        if (!trendChart.value) return
        const arrayData = Array.isArray(data) ? data : [data]
        if (charts.trend.value) charts.trend.value.destroy()

        charts.trend.value = new Chart(trendChart.value, {
            type: 'line',
            data: {
                labels: arrayData.map(d => d.date),
                datasets: [{
                    label: '收盤價',
                    data: arrayData.map(d => d.close),
                    borderColor: '#009688',
                    tension: 0.1,
                    pointRadius: 8,
                    pointHoverRadius: 10,
                    fill: true,
                    backgroundColor: 'rgba(0, 150, 136, 0.1)'
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
        })
    }

    const drawVolume = (data) => {
        if (!volumeChart.value || !data.call) return
        if (charts.volume.value) charts.volume.value.destroy()
        charts.volume.value = new Chart(volumeChart.value, {
            type: 'bar',
            data: {
                labels: ['Call', 'Put'],
                datasets: [{
                    label: '成交量',
                    data: [data.call.volume, data.put.volume],
                    backgroundColor: ['#EF5350', '#26A69A']
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
        })
    }

    const drawIv = (data) => {
        if (!ivChart.value) return
        const arrayData = Array.isArray(data) ? data : [data]
        if (charts.iv.value) charts.iv.value.destroy()
        charts.iv.value = new Chart(ivChart.value, {
            type: 'line',
            data: {
                labels: arrayData.map(d => d.date),
                datasets: [
                    { label: 'Call IV', data: arrayData.map(d => d.call_iv), borderColor: '#EF5350', pointRadius: 8 },
                    { label: 'Put IV', data: arrayData.map(d => d.put_iv), borderColor: '#26A69A', pointRadius: 8 }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false }
        })
    }

    const drawOi = (data) => {
        if (!oiChart.value) return
        const arrayData = Array.isArray(data) ? data : [data]
        if (charts.oi.value) charts.oi.value.destroy()
        charts.oi.value = new Chart(oiChart.value, {
            type: 'bar',
            data: {
                labels: arrayData.map(d => d.strike_price),
                datasets: [
                    { label: 'Call OI', data: arrayData.map(d => d.call_oi), backgroundColor: 'rgba(239, 83, 80, 0.6)' },
                    { label: 'Put OI', data: arrayData.map(d => -d.put_oi), backgroundColor: 'rgba(38, 166, 154, 0.6)' }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, scales: { x: { stacked: true }, y: { stacked: true } } }
        })
    }

    const refreshAllData = async () => {
        loading.value = true
        try {
            await fetchData('sentiment', (d) => { sentiment.value = d })
            await Promise.all([
                fetchData('trend', drawTrend),
                fetchData('volume-analysis', drawVolume),
                fetchData('iv-analysis', drawIv),
                fetchData('oi-distribution', drawOi)
            ])
        } finally {
            loading.value = false
        }
    }

    const formatNumber = (num) => num ? num.toLocaleString() : '0'
    onMounted(refreshAllData)

    return { loading, sentiment, formatNumber, refreshAllData, trendChart, volumeChart, ivChart, oiChart }
  }
}
</script>

<style scoped>
.options-page { padding: 16px; }
.chart-container { position: relative; height: 350px; width: 100%; }
.info-card { transition: transform 0.2s; border: 1px solid rgba(0,0,0,0.1); }
.info-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
.border-bottom { border-bottom: 1px solid rgba(0,0,0,0.05); }
</style>
