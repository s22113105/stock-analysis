<template>
  <div>
    <v-row>
      <!-- 頁面標題 -->
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

    <!-- 主要內容標籤頁 -->
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
              <!-- 系統總覽標籤 -->
              <v-window-item value="overview">
                <v-row>
                  <!-- 系統資訊 -->
                  <v-col cols="12" md="6">
                    <v-card variant="outlined">
                      <v-card-title>系統資訊</v-card-title>
                      <v-card-text>
                        <v-list density="compact">
                          <v-list-item>
                            <v-list-item-title>PHP 版本</v-list-item-title>
                            <template v-slot:append>
                              <span>{{ overview.system?.php_version }}</span>
                            </template>
                          </v-list-item>
                          <v-list-item>
                            <v-list-item-title>Laravel 版本</v-list-item-title>
                            <template v-slot:append>
                              <span>{{ overview.system?.laravel_version }}</span>
                            </template>
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

                  <!-- Redis 狀態 -->
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
                            <template v-slot:append>
                              <span>{{ overview.redis?.memory_usage }}</span>
                            </template>
                          </v-list-item>
                          <v-list-item>
                            <v-list-item-title>Keys 數量</v-list-item-title>
                            <template v-slot:append>
                              <span>{{ formatNumber(overview.redis?.keys_count || 0) }}</span>
                            </template>
                          </v-list-item>
                        </v-list>
                      </v-card-text>
                    </v-card>
                  </v-col>

                  <!-- 資料庫統計 -->
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

              <!-- Job 管理標籤 -->
              <v-window-item value="jobs">
                <v-row>
                  <!-- 手動觸發爬蟲 -->
                  <v-col cols="12" md="6">
                    <v-card variant="outlined">
                      <v-card-title>📊 股票資料爬蟲</v-card-title>
                      <v-card-text>
                        <v-text-field
                          v-model="stockCrawlerSymbol"
                          label="股票代碼 (選填)"
                          placeholder="例如: 2330"
                          density="compact"
                          class="mb-2"
                        ></v-text-field>
                        <v-text-field
                          v-model="stockCrawlerDate"
                          label="日期"
                          type="date"
                          density="compact"
                          class="mb-2"
                        ></v-text-field>
                        <v-checkbox
                          v-model="stockCrawlerSync"
                          label="同步模式 (立即執行)"
                          density="compact"
                        ></v-checkbox>
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
                            <v-btn
                              size="small"
                              color="primary"
                              @click="retryJob(item.id)"
                            >
                              重試
                            </v-btn>
                          </template>
                        </v-data-table>
                      </v-card-text>
                    </v-card>
                  </v-col>
                </v-row>
              </v-window-item>

              <!-- 快取管理標籤 -->
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

              <!-- 系統日誌標籤 -->
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
                      <div v-for="(log, index) in logs" :key="index" class="text-white">
                        {{ log }}
                      </div>
                      <div v-if="logs.length === 0" class="text-grey text-center">
                        暫無日誌記錄
                      </div>
                    </v-sheet>
                  </v-card-text>
                </v-card>
              </v-window-item>
            </v-window>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- 成功提示 Snackbar -->
    <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="3000">
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
    // 狀態
    const currentTab = ref('overview')
    const overview = ref({})
    const queueJobs = ref({})
    const logs = ref([])
    const logLevel = ref(null)
    const logsLoading = ref(false)

    // 股票爬蟲表單
    const stockCrawlerSymbol = ref('')
    const stockCrawlerDate = ref(new Date().toISOString().split('T')[0])
    const stockCrawlerSync = ref(false)
    const stockCrawlerLoading = ref(false)

    // 選擇權爬蟲表單
    const optionCrawlerDate = ref(new Date().toISOString().split('T')[0])
    const optionCrawlerLoading = ref(false)

    // 快取管理
    const cacheLoading = ref({})
    const cacheTypes = ref([
      { value: 'all', title: '全部快取', description: '清除所有快取' },
      { value: 'config', title: '設定快取', description: '清除設定檔快取' },
      { value: 'route', title: '路由快取', description: '清除路由快取' },
      { value: 'view', title: '視圖快取', description: '清除視圖快取' },
      { value: 'cache', title: '應用快取', description: '清除應用程式快取' },
    ])

    // 日誌等級
    const logLevels = ref([
      { value: null, title: '全部' },
      { value: 'error', title: '錯誤' },
      { value: 'warning', title: '警告' },
      { value: 'info', title: '資訊' },
      { value: 'debug', title: '除錯' },
    ])

    // 失敗 Jobs 表頭
    const failedJobsHeaders = ref([
      { title: 'ID', key: 'id', width: '80px' },
      { title: 'Queue', key: 'queue' },
      { title: '錯誤訊息', key: 'exception' },
      { title: '失敗時間', key: 'failed_at' },
      { title: '操作', key: 'actions', sortable: false, width: '100px' },
    ])

    // Snackbar
    const snackbar = ref({
      show: false,
      message: '',
      color: 'success'
    })

    // 載入系統總覽
    const loadOverview = async () => {
      try {
        const response = await axios.get('/api/admin/overview')
        overview.value = response.data.data
      } catch (error) {
        console.error('載入系統總覽失敗:', error)
        showSnackbar('載入系統總覽失敗', 'error')
      }
    }

    // 載入 Queue Jobs
    const loadQueueJobs = async () => {
      try {
        const response = await axios.get('/api/admin/jobs/queue')
        queueJobs.value = response.data.data
      } catch (error) {
        console.error('載入 Queue Jobs 失敗:', error)
      }
    }

    // 觸發股票爬蟲
    const triggerStockCrawler = async () => {
      stockCrawlerLoading.value = true
      try {
        const response = await axios.post('/api/admin/jobs/trigger-stock-crawler', {
          symbol: stockCrawlerSymbol.value || undefined,
          date: stockCrawlerDate.value,
          sync: stockCrawlerSync.value
        })
        showSnackbar(response.data.message, 'success')
        loadQueueJobs()
      } catch (error) {
        console.error('觸發股票爬蟲失敗:', error)
        showSnackbar('觸發股票爬蟲失敗', 'error')
      } finally {
        stockCrawlerLoading.value = false
      }
    }

    // 觸發選擇權爬蟲
    const triggerOptionCrawler = async () => {
      optionCrawlerLoading.value = true
      try {
        const response = await axios.post('/api/admin/jobs/trigger-option-crawler', {
          date: optionCrawlerDate.value
        })
        showSnackbar(response.data.message, 'success')
        loadQueueJobs()
      } catch (error) {
        console.error('觸發選擇權爬蟲失敗:', error)
        showSnackbar('觸發選擇權爬蟲失敗', 'error')
      } finally {
        optionCrawlerLoading.value = false
      }
    }

    // 重試失敗的 Job
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

    // 清除快取
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

    // 載入日誌
    const loadLogs = async () => {
      logsLoading.value = true
      try {
        const response = await axios.get('/api/admin/logs', {
          params: {
            lines: 200,
            level: logLevel.value
          }
        })
        logs.value = response.data.data.logs
      } catch (error) {
        console.error('載入日誌失敗:', error)
        showSnackbar('載入日誌失敗', 'error')
      } finally {
        logsLoading.value = false
      }
    }

    // 顯示 Snackbar
    const showSnackbar = (message, color = 'success') => {
      snackbar.value = {
        show: true,
        message,
        color
      }
    }

    // 格式化數字
    const formatNumber = (num) => {
      return new Intl.NumberFormat('zh-TW').format(num)
    }

    // 初始化
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
      stockCrawlerSymbol,
      stockCrawlerDate,
      stockCrawlerSync,
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
      formatNumber
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