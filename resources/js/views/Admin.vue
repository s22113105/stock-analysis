<template>
  <div>
    <v-row>
      <v-col cols="12">
        <h1 class="text-h4 mb-2">🔧 系統管理</h1>
        <p class="text-subtitle-1 text-grey">系統監控、Job 管理、快取清除</p>
      </v-col>
    </v-row>

    <!-- 系統總覽卡片 -->
    <v-row>
      <v-col cols="12" md="3">
        <v-card color="primary" dark>
          <v-card-text>
            <div class="text-caption">股票數量</div>
            <div class="text-h4">{{ formatNumber(overview.database?.stocks || 0) }}</div>
            <div class="text-caption">筆</div>
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

    <!-- 主要內容標籤頁 -->
    <v-row>
      <v-col cols="12">
        <v-card>
          <v-tabs v-model="currentTab" bg-color="primary">
            <v-tab value="overview">系統總覽</v-tab>
            <v-tab value="jobs">JOB 管理</v-tab>
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
                          <v-list-item title="PHP 版本"     :subtitle="overview.system?.php_version"></v-list-item>
                          <v-list-item title="Laravel 版本" :subtitle="overview.system?.laravel_version"></v-list-item>
                          <v-list-item title="環境"         :subtitle="overview.system?.environment"></v-list-item>
                          <v-list-item title="時區"         :subtitle="overview.system?.timezone"></v-list-item>
                        </v-list>
                      </v-card-text>
                    </v-card>
                  </v-col>
                  <v-col cols="12" md="6">
                    <v-card variant="outlined">
                      <v-card-title>資料庫統計</v-card-title>
                      <v-card-text>
                        <v-row dense>
                          <v-col cols="6" md="3">
                            <div class="text-caption text-grey">股票</div>
                            <div class="text-h6">{{ formatNumber(overview.database?.stocks || 0) }}</div>
                          </v-col>
                          <v-col cols="6" md="3">
                            <div class="text-caption text-grey">股價記錄</div>
                            <div class="text-h6">{{ formatNumber(overview.database?.stock_prices || 0) }}</div>
                          </v-col>
                          <v-col cols="6" md="3">
                            <div class="text-caption text-grey">選擇權合約</div>
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
                  <!-- 股票資料爬蟲 -->
                  <v-col cols="12" md="6">
                    <v-card variant="outlined">
                      <v-card-title>📊 股票資料爬蟲</v-card-title>
                      <v-card-text>
                        <v-text-field
                          v-model="stockCrawlerSymbols"
                          label="股票代碼（逗號分隔，空白=預設15檔）"
                          placeholder="例如: 2330,2317,2454"
                          density="compact"
                          class="mb-2"
                        ></v-text-field>
                        <v-text-field
                          v-model="stockCrawlerStartDate"
                          label="開始日期"
                          type="date"
                          density="compact"
                          class="mb-2"
                        ></v-text-field>
                        <v-text-field
                          v-model="stockCrawlerEndDate"
                          label="結束日期"
                          type="date"
                          density="compact"
                        ></v-text-field>
                      </v-card-text>
                      <v-card-actions>
                        <v-btn
                          color="primary"
                          @click="triggerStockCrawler"
                          :loading="stockCrawlerLoading"
                          block
                        >
                          <v-icon start>mdi-play</v-icon>
                          執行爬蟲
                        </v-btn>
                      </v-card-actions>
                    </v-card>
                  </v-col>

                  <!-- 選擇權資料爬蟲 -->
                  <v-col cols="12" md="6">
                    <v-card variant="outlined">
                      <v-card-title>📈 選擇權資料爬蟲</v-card-title>
                      <v-card-text>
                        <v-text-field
                          v-model="optionCrawlerDate"
                          label="日期"
                          type="date"
                          density="compact"
                        ></v-text-field>
                        <p class="text-caption text-grey mt-2">
                          注意：選擇權爬蟲為同步執行，執行期間頁面會等待回應，請耐心等候。
                        </p>
                      </v-card-text>
                      <v-card-actions>
                        <v-btn
                          color="primary"
                          @click="triggerOptionCrawler"
                          :loading="optionCrawlerLoading"
                          block
                        >
                          <v-icon start>mdi-play</v-icon>
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
                        <v-btn
                          color="warning"
                          @click="clearCache(cacheType.value)"
                          :loading="cacheLoading[cacheType.value]"
                          block
                        >
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

    <!-- Snackbar -->
    <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="5000">
      {{ snackbar.message }}
    </v-snackbar>
  </div>
</template>

<script>
import { ref, onMounted } from 'vue'
import axios from 'axios'

export default {
  name: 'Admin',
  setup() {
    const currentTab = ref('overview')
    const overview   = ref({})
    const queueJobs  = ref({})
    const logs       = ref([])
    const logLevel   = ref(null)
    const logsLoading = ref(false)

    // ✅ 股票爬蟲表單：對齊後端 API (start_date / end_date / symbols)
    const stockCrawlerSymbols   = ref('')
    const stockCrawlerStartDate = ref(new Date().toISOString().split('T')[0])
    const stockCrawlerEndDate   = ref(new Date().toISOString().split('T')[0])
    const stockCrawlerLoading   = ref(false)

    // 選擇權爬蟲表單
    const optionCrawlerDate    = ref(new Date().toISOString().split('T')[0])
    const optionCrawlerLoading = ref(false)

    // 快取管理
    const cacheLoading = ref({})
    const cacheTypes = ref([
      { value: 'all',    title: '全部快取',   description: '清除所有快取' },
      { value: 'config', title: '設定快取',   description: '清除設定檔快取' },
      { value: 'route',  title: '路由快取',   description: '清除路由快取' },
      { value: 'view',   title: '視圖快取',   description: '清除視圖快取' },
      { value: 'cache',  title: '應用快取',   description: '清除應用程式快取' },
    ])

    const logLevels = ref([
      { value: null,      title: '全部' },
      { value: 'error',   title: '錯誤' },
      { value: 'warning', title: '警告' },
      { value: 'info',    title: '資訊' },
      { value: 'debug',   title: '除錯' },
    ])

    const failedJobsHeaders = ref([
      { title: 'ID',       key: 'id',         width: '80px' },
      { title: 'Queue',    key: 'queue' },
      { title: '錯誤訊息', key: 'exception' },
      { title: '失敗時間', key: 'failed_at' },
      { title: '操作',     key: 'actions', sortable: false, width: '100px' },
    ])

    const snackbar = ref({ show: false, message: '', color: 'success' })

    // ==========================================
    // API 方法
    // ==========================================
    const loadOverview = async () => {
      try {
        const response = await axios.get('/api/admin/overview')
        overview.value = response.data.data
      } catch (error) {
        console.error('載入系統總覽失敗:', error)
        showSnackbar('載入系統總覽失敗', 'error')
      }
    }

    const loadQueueJobs = async () => {
      try {
        const response = await axios.get('/api/admin/jobs/queue')
        queueJobs.value = response.data.data
      } catch (error) {
        console.error('載入 Queue Jobs 失敗:', error)
      }
    }

    // ✅ 修正：送出 start_date / end_date / symbols，對齊後端 triggerStockCrawler
    const triggerStockCrawler = async () => {
      stockCrawlerLoading.value = true
      try {
        const response = await axios.post('/api/admin/jobs/trigger-stock-crawler', {
          symbols:    stockCrawlerSymbols.value || undefined,
          start_date: stockCrawlerStartDate.value,
          end_date:   stockCrawlerEndDate.value,
        })
        showSnackbar(response.data.message, 'success')
        loadQueueJobs()
      } catch (error) {
        console.error('觸發股票爬蟲失敗:', error)
        const msg = error.response?.data?.message || '觸發股票爬蟲失敗'
        showSnackbar(msg, 'error')
      } finally {
        stockCrawlerLoading.value = false
      }
    }

    // 選擇權爬蟲（後端已加 --sync，執行時間較長，前端顯示 loading）
    const triggerOptionCrawler = async () => {
      optionCrawlerLoading.value = true
      try {
        const response = await axios.post('/api/admin/jobs/trigger-option-crawler', {
          date: optionCrawlerDate.value,
        })
        showSnackbar(response.data.message, 'success')
        loadQueueJobs()
      } catch (error) {
        console.error('觸發選擇權爬蟲失敗:', error)
        const msg = error.response?.data?.message || '觸發選擇權爬蟲失敗'
        showSnackbar(msg, 'error')
      } finally {
        optionCrawlerLoading.value = false
      }
    }

    const retryJob = async (id) => {
      try {
        const response = await axios.post(`/api/admin/jobs/retry/${id}`)
        showSnackbar(response.data.message, 'success')
        loadQueueJobs()
      } catch (error) {
        console.error('重試 Job 失敗:', error)
        showSnackbar('重試 Job 失敗', 'error')
      }
    }

    const clearCache = async (type) => {
      cacheLoading.value[type] = true
      try {
        const response = await axios.post('/api/admin/cache/clear', { type })
        showSnackbar(response.data.message, 'success')
      } catch (error) {
        console.error('清除快取失敗:', error)
        showSnackbar('清除快取失敗', 'error')
      } finally {
        cacheLoading.value[type] = false
      }
    }

    const loadLogs = async () => {
      logsLoading.value = true
      try {
        const response = await axios.get('/api/admin/logs', {
          params: { lines: 200, level: logLevel.value },
        })
        logs.value = response.data.data.logs
      } catch (error) {
        console.error('載入日誌失敗:', error)
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

      // 每 30 秒自動更新一次
      setInterval(() => {
        loadOverview()
        loadQueueJobs()
      }, 30000)
    })

    return {
      currentTab,
      overview,
      queueJobs,
      logs,
      logLevel,
      logsLoading,
      stockCrawlerSymbols,
      stockCrawlerStartDate,
      stockCrawlerEndDate,
      stockCrawlerLoading,
      optionCrawlerDate,
      optionCrawlerLoading,
      cacheLoading,
      cacheTypes,
      logLevels,
      failedJobsHeaders,
      snackbar,
      loadOverview,
      loadQueueJobs,
      triggerStockCrawler,
      triggerOptionCrawler,
      retryJob,
      clearCache,
      loadLogs,
      formatNumber,
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
