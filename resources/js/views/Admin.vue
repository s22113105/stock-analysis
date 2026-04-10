<template>
  <div>
    <v-row>
      <v-col cols="12">
        <h1 class="text-h4 mb-2">🔧 系統管理</h1>
        <p class="text-subtitle-1 text-grey">系統監控、Job 管理、快取清除</p>
      </v-col>
    </v-row>

    <v-row>
      <v-col cols="12" md="3">
        <v-card color="primary" dark>
          <v-card-text>
            <div class="text-caption">資料庫記錄數</div>
            <div class="text-h4">{{ formatNumber(overview.database?.stocks || 0) }}</div>
            <div class="text-caption">股票</div>
          </v-card-text>
        </v-card>
      </v-col>
      <v-col cols="12" md="3">
        <v-card color="success" dark>
          <v-card-text>
            <div class="text-caption">價格記錄</div>
            <div class="text-h4">{{ formatNumber(overview.database?.stock_prices || 0) }}</div>
            <div class="text-caption">筆</div>
          </v-card-text>
        </v-card>
      </v-col>
      <v-col cols="12" md="3">
        <v-card color="warning" dark>
          <v-card-text>
            <div class="text-caption">佇列任務</div>
            <div class="text-h4">{{ queueJobs.default?.pending || 0 }}</div>
            <div class="text-caption">等待中</div>
          </v-card-text>
        </v-card>
      </v-col>
      <v-col cols="12" md="3">
        <v-card color="error" dark>
          <v-card-text>
            <div class="text-caption">失敗任務</div>
            <div class="text-h4">{{ queueJobs.failed?.length || 0 }}</div>
            <div class="text-caption">需處理</div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <v-row>
      <v-col cols="12">
        <v-card>
          <v-tabs v-model="currentTab" bg-color="primary">
            <v-tab value="overview">系統總覽</v-tab>
            <v-tab value="jobs">Job 管理</v-tab>
            <v-tab value="cache">快取管理</v-tab>
            <v-tab value="logs">系統日誌</v-tab>
          </v-tabs>

          <v-card-text>
            <v-window v-model="currentTab">

              <!-- 系統總覽 -->
              <v-window-item value="overview">
                <v-row>
                  <v-col cols="12" md="6">
                    <v-card variant="outlined">
                      <v-card-title>系統資訊</v-card-title>
                      <v-card-text>
                        <v-list density="compact">
                          <v-list-item>
                            <v-list-item-title>PHP 版本</v-list-item-title>
                            <template v-slot:append><span>{{ overview.system?.php_version }}</span></template>
                          </v-list-item>
                          <v-list-item>
                            <v-list-item-title>Laravel 版本</v-list-item-title>
                            <template v-slot:append><span>{{ overview.system?.laravel_version }}</span></template>
                          </v-list-item>
                          <v-list-item>
                            <v-list-item-title>環境</v-list-item-title>
                            <template v-slot:append>
                              <v-chip :color="overview.system?.environment === 'production' ? 'error' : 'success'" size="small">
                                {{ overview.system?.environment }}
                              </v-chip>
                            </template>
                          </v-list-item>
                          <v-list-item>
                            <v-list-item-title>Debug 模式</v-list-item-title>
                            <template v-slot:append>
                              <v-chip :color="overview.system?.debug_mode ? 'warning' : 'success'" size="small">
                                {{ overview.system?.debug_mode ? '開啟' : '關閉' }}
                              </v-chip>
                            </template>
                          </v-list-item>
                        </v-list>
                      </v-card-text>
                    </v-card>
                  </v-col>

                  <v-col cols="12" md="6">
                    <v-card variant="outlined">
                      <v-card-title>Redis 狀態</v-card-title>
                      <v-card-text>
                        <v-list density="compact">
                          <v-list-item>
                            <v-list-item-title>連線狀態</v-list-item-title>
                            <template v-slot:append>
                              <v-chip :color="overview.redis?.connected ? 'success' : 'error'" size="small">
                                {{ overview.redis?.connected ? '已連線' : '未連線' }}
                              </v-chip>
                            </template>
                          </v-list-item>
                          <v-list-item>
                            <v-list-item-title>記憶體使用</v-list-item-title>
                            <template v-slot:append><span>{{ overview.redis?.memory_usage }}</span></template>
                          </v-list-item>
                          <v-list-item>
                            <v-list-item-title>Keys 數量</v-list-item-title>
                            <template v-slot:append><span>{{ formatNumber(overview.redis?.keys_count || 0) }}</span></template>
                          </v-list-item>
                        </v-list>
                      </v-card-text>
                    </v-card>
                  </v-col>

                  <v-col cols="12">
                    <v-card variant="outlined">
                      <v-card-title>資料庫統計</v-card-title>
                      <v-card-text>
                        <v-row>
                          <v-col cols="6" md="3">
                            <div class="text-caption text-grey">股票</div>
                            <div class="text-h6">{{ formatNumber(overview.database?.stocks || 0) }}</div>
                          </v-col>
                          <v-col cols="6" md="3">
                            <div class="text-caption text-grey">股票價格</div>
                            <div class="text-h6">{{ formatNumber(overview.database?.stock_prices || 0) }}</div>
                          </v-col>
                          <v-col cols="6" md="3">
                            <div class="text-caption text-grey">選擇權</div>
                            <div class="text-h6">{{ formatNumber(overview.database?.options || 0) }}</div>
                          </v-col>
                          <v-col cols="6" md="3">
                            <div class="text-caption text-grey">選擇權價格</div>
                            <div class="text-h6">{{ formatNumber(overview.database?.option_prices || 0) }}</div>
                          </v-col>
                          <v-col cols="6" md="3">
                            <div class="text-caption text-grey">預測</div>
                            <div class="text-h6">{{ formatNumber(overview.database?.predictions || 0) }}</div>
                          </v-col>
                          <v-col cols="6" md="3">
                            <div class="text-caption text-grey">回測結果</div>
                            <div class="text-h6">{{ formatNumber(overview.database?.backtest_results || 0) }}</div>
                          </v-col>
                          <v-col cols="6" md="3">
                            <div class="text-caption text-grey">資料庫大小</div>
                            <div class="text-h6">{{ overview.database?.database_size }}</div>
                          </v-col>
                        </v-row>
                      </v-card-text>
                    </v-card>
                  </v-col>
                </v-row>
              </v-window-item>

              <!-- Job 管理 -->
              <v-window-item value="jobs">
                <v-row>

                  <!-- 股票資料批次爬蟲 -->
                  <v-col cols="12">
                    <v-card variant="outlined">
                      <v-card-title>
                        <v-icon start>mdi-chart-line</v-icon>
                        股票資料批次爬蟲
                      </v-card-title>
                      <v-card-text>
                        <v-row>

                          <!-- 股票代碼（選填） -->
                          <v-col cols="12">
                            <v-textarea
                              v-model="stockCrawlerSymbols"
                              label="股票代碼（逗號分隔，選填）"
                              placeholder="例如：2330,2317,2454　留空則使用預設 15 檔權值股"
                              rows="2"
                              density="compact"
                              hint="預設 15 檔：2330,2317,2454,2412,2882,2303,2308,2886,2884,1301,1303,2002,3045,2881,2891"
                              persistent-hint
                            ></v-textarea>
                          </v-col>

                          <!-- 日期範圍 -->
                          <v-col cols="12" md="5">
                            <v-text-field
                              v-model="stockCrawlerStartDate"
                              label="開始日期"
                              type="date"
                              density="compact"
                              :max="stockCrawlerEndDate"
                            ></v-text-field>
                          </v-col>
                          <v-col cols="12" md="2" class="d-flex align-center justify-center">
                            <span class="text-grey text-h6">→</span>
                          </v-col>
                          <v-col cols="12" md="5">
                            <v-text-field
                              v-model="stockCrawlerEndDate"
                              label="結束日期"
                              type="date"
                              density="compact"
                              :min="stockCrawlerStartDate"
                            ></v-text-field>
                          </v-col>

                          <!-- 預覽 -->
                          <v-col cols="12">
                            <v-alert type="info" variant="tonal" density="compact">
                              抓取範圍：<strong>{{ stockCrawlerStartDate }}</strong> ～ <strong>{{ stockCrawlerEndDate }}</strong>，
                              共 <strong>{{ crawlerMonthCount }}</strong> 個月份 ×
                              <strong>{{ crawlerSymbolCount }}</strong> 檔股票，
                              預計觸發 <strong>{{ crawlerPreview }}</strong> 個任務
                            </v-alert>
                          </v-col>

                          <!-- 執行中提示 -->
                          <v-col cols="12" v-if="stockCrawlerLoading">
                            <v-alert type="warning" variant="tonal" density="compact">
                              <v-progress-circular indeterminate size="16" class="mr-2"></v-progress-circular>
                              爬蟲執行中，請稍候，這可能需要數分鐘...
                            </v-alert>
                          </v-col>

                          <!-- 執行結果 log -->
                          <v-col cols="12" v-if="crawlerLogs.length > 0">
                            <div class="text-caption text-grey mb-1">執行結果（前 30 筆）</div>
                            <v-sheet
                              class="pa-3"
                              color="grey-darken-4"
                              rounded
                              style="max-height: 200px; overflow-y: auto; font-family: monospace; font-size: 12px;"
                            >
                              <div v-for="(log, i) in crawlerLogs" :key="i" class="text-white">{{ log }}</div>
                            </v-sheet>
                          </v-col>

                        </v-row>
                      </v-card-text>
                      <v-card-actions>
                        <v-btn
                          color="primary"
                          :loading="stockCrawlerLoading"
                          :disabled="stockCrawlerLoading || !stockCrawlerStartDate || !stockCrawlerEndDate"
                          @click="triggerStockCrawler"
                          prepend-icon="mdi-play"
                        >
                          執行批次爬蟲
                        </v-btn>
                        <v-btn variant="text" :disabled="stockCrawlerLoading" @click="resetStockCrawler">
                          重設
                        </v-btn>
                      </v-card-actions>
                    </v-card>
                  </v-col>

                  <!-- 選擇權爬蟲 -->
                  <v-col cols="12" md="6">
                    <v-card variant="outlined">
                      <v-card-title>
                        <v-icon start>mdi-finance</v-icon>
                        選擇權資料爬蟲
                      </v-card-title>
                      <v-card-text>
                        <v-text-field
                          v-model="optionCrawlerDate"
                          label="日期"
                          type="date"
                          density="compact"
                        ></v-text-field>
                      </v-card-text>
                      <v-card-actions>
                        <v-btn color="primary" @click="triggerOptionCrawler" :loading="optionCrawlerLoading" block prepend-icon="mdi-play">
                          執行爬蟲
                        </v-btn>
                      </v-card-actions>
                    </v-card>
                  </v-col>

                  <!-- 失敗的 Jobs -->
                  <v-col cols="12">
                    <v-card variant="outlined">
                      <v-card-title>
                        失敗的任務
                        <v-chip class="ml-2" color="error" size="small">{{ queueJobs.failed?.length || 0 }}</v-chip>
                      </v-card-title>
                      <v-card-text>
                        <v-data-table
                          :headers="failedJobsHeaders"
                          :items="queueJobs.failed || []"
                          :items-per-page="10"
                          density="compact"
                        >
                          <template v-slot:item.exception="{ item }">
                            <v-tooltip>
                              <template v-slot:activator="{ props }">
                                <span v-bind="props" class="text-truncate" style="max-width: 300px; display: inline-block;">
                                  {{ item.exception }}
                                </span>
                              </template>
                              <span>{{ item.exception }}</span>
                            </v-tooltip>
                          </template>
                          <template v-slot:item.actions="{ item }">
                            <v-btn size="small" color="primary" @click="retryJob(item.id)">重試</v-btn>
                          </template>
                        </v-data-table>
                      </v-card-text>
                    </v-card>
                  </v-col>

                </v-row>
              </v-window-item>

              <!-- 快取管理 -->
              <v-window-item value="cache">
                <v-row>
                  <v-col cols="12" md="6" v-for="cacheType in cacheTypes" :key="cacheType.value">
                    <v-card variant="outlined">
                      <v-card-title>{{ cacheType.title }}</v-card-title>
                      <v-card-text>
                        <p class="text-caption text-grey">{{ cacheType.description }}</p>
                      </v-card-text>
                      <v-card-actions>
                        <v-btn color="warning" @click="clearCache(cacheType.value)" :loading="cacheLoading[cacheType.value]" block>
                          <v-icon start>mdi-delete</v-icon>
                          清除 {{ cacheType.title }}
                        </v-btn>
                      </v-card-actions>
                    </v-card>
                  </v-col>
                </v-row>
              </v-window-item>

              <!-- 系統日誌 -->
              <v-window-item value="logs">
                <v-card variant="outlined">
                  <v-card-title>
                    系統日誌
                    <v-spacer></v-spacer>
                    <v-select
                      v-model="logLevel"
                      :items="logLevels"
                      label="日誌等級"
                      density="compact"
                      style="max-width: 200px"
                      class="mr-2"
                    ></v-select>
                    <v-btn @click="loadLogs" :loading="logsLoading" color="primary" size="small">
                      <v-icon start>mdi-refresh</v-icon>
                      重新載入
                    </v-btn>
                  </v-card-title>
                  <v-card-text>
                    <v-sheet
                      class="pa-4"
                      color="grey-darken-4"
                      rounded
                      style="max-height: 600px; overflow-y: auto; font-family: monospace; font-size: 12px;"
                    >
                      <div v-for="(log, index) in logs" :key="index" class="text-white">{{ log }}</div>
                      <div v-if="logs.length === 0" class="text-grey text-center">暫無日誌記錄</div>
                    </v-sheet>
                  </v-card-text>
                </v-card>
              </v-window-item>

            </v-window>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="3000">
      {{ snackbar.message }}
    </v-snackbar>
  </div>
</template>

<script>
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'

export default {
  name: 'Admin',
  setup() {
    const currentTab = ref('overview')
    const overview = ref({})
    const queueJobs = ref({})
    const logs = ref([])
    const logLevel = ref(null)
    const logsLoading = ref(false)

    // 預設日期
    const defaultStart = () => {
      const d = new Date()
      d.setMonth(d.getMonth() - 6)
      return d.toISOString().split('T')[0]
    }
    const defaultEnd = () => new Date().toISOString().split('T')[0]

    // 股票爬蟲
    const stockCrawlerSymbols   = ref('')
    const stockCrawlerStartDate = ref(defaultStart())
    const stockCrawlerEndDate   = ref(defaultEnd())
    const stockCrawlerLoading   = ref(false)
    const crawlerLogs           = ref([])

    // 計算月份數
    const crawlerMonthCount = computed(() => {
      if (!stockCrawlerStartDate.value || !stockCrawlerEndDate.value) return 0
      const start = new Date(stockCrawlerStartDate.value)
      const end   = new Date(stockCrawlerEndDate.value)
      if (start > end) return 0
      const months = new Set()
      const cur = new Date(start.getFullYear(), start.getMonth(), 1)
      const endYM = end.getFullYear() * 12 + end.getMonth()
      while (cur.getFullYear() * 12 + cur.getMonth() <= endYM) {
        months.add(`${cur.getFullYear()}-${String(cur.getMonth() + 1).padStart(2, '0')}`)
        cur.setMonth(cur.getMonth() + 1)
      }
      return months.size
    })

    // 計算股票數
    const crawlerSymbolCount = computed(() => {
      if (!stockCrawlerSymbols.value.trim()) return 15
      return stockCrawlerSymbols.value.split(',').filter(s => s.trim()).length
    })

    // 預覽任務數
    const crawlerPreview = computed(() => crawlerMonthCount.value * crawlerSymbolCount.value)

    // 觸發批次爬蟲
    const triggerStockCrawler = async () => {
      stockCrawlerLoading.value = true
      crawlerLogs.value = []
      try {
        const response = await axios.post('/admin/jobs/trigger-stock-crawler', {
          symbols:    stockCrawlerSymbols.value.trim() || undefined,
          start_date: stockCrawlerStartDate.value,
          end_date:   stockCrawlerEndDate.value,
        })
        showSnackbar(response.data.message, 'success')
        crawlerLogs.value = response.data.data?.logs || []
        loadQueueJobs()
        loadOverview()
      } catch (error) {
        console.error('批次爬蟲失敗:', error)
        showSnackbar('觸發失敗', 'error')
      } finally {
        stockCrawlerLoading.value = false
      }
    }

    const resetStockCrawler = () => {
      stockCrawlerSymbols.value   = ''
      stockCrawlerStartDate.value = defaultStart()
      stockCrawlerEndDate.value   = defaultEnd()
      crawlerLogs.value = []
    }

    // 選擇權爬蟲
    const optionCrawlerDate    = ref(defaultEnd())
    const optionCrawlerLoading = ref(false)

    // 快取管理
    const cacheLoading = ref({})
    const cacheTypes = ref([
      { value: 'all',    title: '全部快取', description: '清除所有快取' },
      { value: 'config', title: '設定快取', description: '清除設定檔快取' },
      { value: 'route',  title: '路由快取', description: '清除路由快取' },
      { value: 'view',   title: '視圖快取', description: '清除視圖快取' },
      { value: 'cache',  title: '應用快取', description: '清除應用程式快取' },
    ])

    const logLevels = ref([
      { value: null,      title: '全部' },
      { value: 'error',   title: '錯誤' },
      { value: 'warning', title: '警告' },
      { value: 'info',    title: '資訊' },
      { value: 'debug',   title: '除錯' },
    ])

    const failedJobsHeaders = ref([
      { title: 'ID',       key: 'id',        width: '80px' },
      { title: 'Queue',    key: 'queue' },
      { title: '錯誤訊息', key: 'exception' },
      { title: '失敗時間', key: 'failed_at' },
      { title: '操作',     key: 'actions', sortable: false, width: '100px' },
    ])

    const snackbar = ref({ show: false, message: '', color: 'success' })

    const loadOverview = async () => {
      try {
        const r = await axios.get('/admin/overview')
        overview.value = r.data.data
      } catch (e) {
        showSnackbar('載入系統總覽失敗', 'error')
      }
    }

    const loadQueueJobs = async () => {
      try {
        const r = await axios.get('/admin/jobs/queue')
        queueJobs.value = r.data.data
      } catch (e) {
        console.error('載入 Queue Jobs 失敗:', e)
      }
    }

    const triggerOptionCrawler = async () => {
      optionCrawlerLoading.value = true
      try {
        const r = await axios.post('/admin/jobs/trigger-option-crawler', { date: optionCrawlerDate.value })
        showSnackbar(r.data.message, 'success')
        loadQueueJobs()
      } catch (e) {
        showSnackbar('觸發選擇權爬蟲失敗', 'error')
      } finally {
        optionCrawlerLoading.value = false
      }
    }

    const retryJob = async (id) => {
      try {
        const r = await axios.post(`/admin/jobs/retry/${id}`)
        showSnackbar(r.data.message, 'success')
        loadQueueJobs()
      } catch (e) {
        showSnackbar('重試 Job 失敗', 'error')
      }
    }

    const clearCache = async (type) => {
      cacheLoading.value[type] = true
      try {
        const r = await axios.post('/admin/cache/clear', { type })
        showSnackbar(r.data.message, 'success')
      } catch (e) {
        showSnackbar('清除快取失敗', 'error')
      } finally {
        cacheLoading.value[type] = false
      }
    }

    const loadLogs = async () => {
      logsLoading.value = true
      try {
        const r = await axios.get('/admin/logs', { params: { lines: 200, level: logLevel.value } })
        logs.value = r.data.data.logs
      } catch (e) {
        showSnackbar('載入日誌失敗', 'error')
      } finally {
        logsLoading.value = false
      }
    }

    const showSnackbar = (message, color = 'success') => {
      snackbar.value = { show: true, message, color }
    }

    const formatNumber = (num) => new Intl.NumberFormat('zh-TW').format(num)

    onMounted(() => {
      loadOverview()
      loadQueueJobs()
      loadLogs()
      setInterval(() => { loadOverview(); loadQueueJobs() }, 30000)
    })

    return {
      currentTab, overview, queueJobs, logs, logLevel, logsLoading,
      stockCrawlerSymbols, stockCrawlerStartDate, stockCrawlerEndDate,
      stockCrawlerLoading, crawlerLogs,
      crawlerMonthCount, crawlerSymbolCount, crawlerPreview,
      triggerStockCrawler, resetStockCrawler,
      optionCrawlerDate, optionCrawlerLoading, triggerOptionCrawler,
      cacheLoading, cacheTypes, logLevels, failedJobsHeaders, snackbar,
      loadOverview, loadQueueJobs, retryJob, clearCache, loadLogs, formatNumber
    }
  }
}
</script>

<style scoped>
.text-truncate {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
</style>
