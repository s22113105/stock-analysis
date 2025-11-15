<template>
  <div class="profile-page">
    <v-row>
      <!-- 左側：個人資訊 -->
      <v-col cols="12" md="4">
        <v-card elevation="2">
          <v-card-text class="text-center">
            <!-- 頭像 -->
            <v-avatar size="120" class="mb-4">
              <v-img :src="profile.avatar" alt="Avatar">
                <template v-slot:placeholder>
                  <v-icon size="80">mdi-account-circle</v-icon>
                </template>
              </v-img>
            </v-avatar>

            <div class="text-h5">{{ profile.name }}</div>
            <div class="text-subtitle-1 text-grey">{{ profile.email }}</div>

            <v-chip class="mt-4" color="primary">
              {{ profile.memberLevel }}
            </v-chip>

            <v-divider class="my-4"></v-divider>

            <!-- 統計資訊 -->
            <v-row>
              <v-col cols="6">
                <div class="text-caption text-grey">會員天數</div>
                <div class="text-h6">{{ profile.memberDays }}</div>
              </v-col>
              <v-col cols="6">
                <div class="text-caption text-grey">交易次數</div>
                <div class="text-h6">{{ profile.tradeCount }}</div>
              </v-col>
            </v-row>

            <v-row class="mt-2">
              <v-col cols="6">
                <div class="text-caption text-grey">總報酬率</div>
                <div class="text-h6" :class="profile.totalReturn >= 0 ? 'text-success' : 'text-error'">
                  {{ profile.totalReturn >= 0 ? '+' : '' }}{{ profile.totalReturn }}%
                </div>
              </v-col>
              <v-col cols="6">
                <div class="text-caption text-grey">勝率</div>
                <div class="text-h6">{{ profile.winRate }}%</div>
              </v-col>
            </v-row>

            <v-btn color="primary" class="mt-4" block prepend-icon="mdi-camera">
              變更頭像
            </v-btn>
          </v-card-text>
        </v-card>

        <!-- 成就徽章 -->
        <v-card elevation="2" class="mt-4">
          <v-card-title>
            <v-icon class="mr-2">mdi-trophy</v-icon>
            成就徽章
          </v-card-title>
          <v-card-text>
            <v-chip-group column>
              <v-chip
                v-for="badge in badges"
                :key="badge.id"
                :color="badge.unlocked ? 'primary' : 'grey'"
                :disabled="!badge.unlocked"
              >
                <v-icon start>{{ badge.icon }}</v-icon>
                {{ badge.name }}
              </v-chip>
            </v-chip-group>
          </v-card-text>
        </v-card>
      </v-col>

      <!-- 右側：詳細資料與設定 -->
      <v-col cols="12" md="8">
        <!-- 個人資料 -->
        <v-card elevation="2" class="mb-4">
          <v-card-title>個人資料</v-card-title>
          <v-card-text>
            <v-form ref="form">
              <v-row>
                <v-col cols="12" md="6">
                  <v-text-field
                    v-model="profile.name"
                    label="姓名"
                    density="compact"
                  ></v-text-field>
                </v-col>
                <v-col cols="12" md="6">
                  <v-text-field
                    v-model="profile.email"
                    label="電子郵件"
                    type="email"
                    density="compact"
                  ></v-text-field>
                </v-col>
              </v-row>

              <v-row>
                <v-col cols="12" md="6">
                  <v-text-field
                    v-model="profile.phone"
                    label="電話"
                    density="compact"
                  ></v-text-field>
                </v-col>
                <v-col cols="12" md="6">
                  <v-text-field
                    v-model="profile.birthday"
                    label="生日"
                    type="date"
                    density="compact"
                  ></v-text-field>
                </v-col>
              </v-row>

              <v-textarea
                v-model="profile.bio"
                label="個人簡介"
                rows="3"
                density="compact"
              ></v-textarea>

              <v-btn color="primary" @click="saveProfile">儲存變更</v-btn>
            </v-form>
          </v-card-text>
        </v-card>

        <!-- 帳戶資訊 -->
        <v-card elevation="2" class="mb-4">
          <v-card-title>帳戶資訊</v-card-title>
          <v-card-text>
            <v-list density="compact">
              <v-list-item>
                <v-list-item-title>會員等級</v-list-item-title>
                <template v-slot:append>
                  <v-chip color="primary">{{ profile.memberLevel }}</v-chip>
                </template>
              </v-list-item>

              <v-list-item>
                <v-list-item-title>註冊日期</v-list-item-title>
                <template v-slot:append>
                  <span>{{ profile.registerDate }}</span>
                </template>
              </v-list-item>

              <v-list-item>
                <v-list-item-title>最後登入</v-list-item-title>
                <template v-slot:append>
                  <span>{{ profile.lastLogin }}</span>
                </template>
              </v-list-item>

              <v-list-item>
                <v-list-item-title>帳戶狀態</v-list-item-title>
                <template v-slot:append>
                  <v-chip color="success">啟用</v-chip>
                </template>
              </v-list-item>
            </v-list>
          </v-card-text>
        </v-card>

        <!-- 交易統計 -->
        <v-card elevation="2" class="mb-4">
          <v-card-title>交易統計</v-card-title>
          <v-card-text>
            <v-row>
              <v-col cols="6" md="3">
                <v-card color="primary" dark variant="tonal">
                  <v-card-text>
                    <div class="text-caption">總交易</div>
                    <div class="text-h6">{{ tradingStats.totalTrades }}</div>
                  </v-card-text>
                </v-card>
              </v-col>
              <v-col cols="6" md="3">
                <v-card color="success" dark variant="tonal">
                  <v-card-text>
                    <div class="text-caption">獲利次數</div>
                    <div class="text-h6">{{ tradingStats.winningTrades }}</div>
                  </v-card-text>
                </v-card>
              </v-col>
              <v-col cols="6" md="3">
                <v-card color="error" dark variant="tonal">
                  <v-card-text>
                    <div class="text-caption">虧損次數</div>
                    <div class="text-h6">{{ tradingStats.losingTrades }}</div>
                  </v-card-text>
                </v-card>
              </v-col>
              <v-col cols="6" md="3">
                <v-card color="info" dark variant="tonal">
                  <v-card-text>
                    <div class="text-caption">勝率</div>
                    <div class="text-h6">{{ tradingStats.winRate }}%</div>
                  </v-card-text>
                </v-card>
              </v-col>
            </v-row>

            <v-row class="mt-4">
              <v-col cols="12">
                <canvas ref="performanceChart" height="200"></canvas>
              </v-col>
            </v-row>
          </v-card-text>
        </v-card>

        <!-- 偏好設定 -->
        <v-card elevation="2">
          <v-card-title>偏好設定</v-card-title>
          <v-card-text>
            <v-switch
              v-model="preferences.receiveNewsletter"
              label="接收電子報"
              color="primary"
              class="mb-2"
            ></v-switch>

            <v-switch
              v-model="preferences.receiveTradeAlerts"
              label="接收交易警示"
              color="primary"
              class="mb-2"
            ></v-switch>

            <v-switch
              v-model="preferences.receiveMarketNews"
              label="接收市場資訊"
              color="primary"
              class="mb-2"
            ></v-switch>

            <v-switch
              v-model="preferences.showPublicProfile"
              label="公開個人檔案"
              color="primary"
              class="mb-2"
            ></v-switch>

            <v-btn color="primary" @click="savePreferences">儲存偏好</v-btn>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>
  </div>
</template>

<script>
import { ref, onMounted, onUnmounted } from 'vue'
import Chart from 'chart.js/auto'

export default {
  name: 'Profile',
  setup() {
    // 個人資料
    const profile = ref({
      avatar: '',
      name: '使用者名稱',
      email: 'user@example.com',
      phone: '0912-345-678',
      birthday: '1990-01-01',
      bio: '這是我的個人簡介...',
      memberLevel: '黃金會員',
      memberDays: 365,
      tradeCount: 156,
      totalReturn: 25.5,
      winRate: 62.2,
      registerDate: '2024-01-01',
      lastLogin: '2025-11-15 14:30'
    })

    // 成就徽章
    const badges = ref([
      { id: 1, name: '新手交易者', icon: 'mdi-star', unlocked: true },
      { id: 2, name: '連續獲利', icon: 'mdi-trophy', unlocked: true },
      { id: 3, name: '百戰老將', icon: 'mdi-shield', unlocked: false },
      { id: 4, name: '風險管理大師', icon: 'mdi-shield-check', unlocked: true },
      { id: 5, name: '策略高手', icon: 'mdi-brain', unlocked: false },
      { id: 6, name: '社群貢獻', icon: 'mdi-account-group', unlocked: false }
    ])

    // 交易統計
    const tradingStats = ref({
      totalTrades: 156,
      winningTrades: 97,
      losingTrades: 59,
      winRate: 62.2
    })

    // 偏好設定
    const preferences = ref({
      receiveNewsletter: true,
      receiveTradeAlerts: true,
      receiveMarketNews: true,
      showPublicProfile: false
    })

    // 圖表
    const performanceChart = ref(null)
    let chartInstance = null

    // 方法
    const saveProfile = () => {
      console.log('儲存個人資料:', profile.value)
    }

    const savePreferences = () => {
      console.log('儲存偏好設定:', preferences.value)
    }

    const initChart = () => {
      if (performanceChart.value) {
        const ctx = performanceChart.value.getContext('2d')
        chartInstance = new Chart(ctx, {
          type: 'line',
          data: {
            labels: ['1月', '2月', '3月', '4月', '5月', '6月', '7月', '8月', '9月', '10月'],
            datasets: [{
              label: '累積報酬率',
              data: [0, 5, 8, 6, 12, 15, 18, 16, 22, 25.5],
              borderColor: 'rgb(75, 192, 192)',
              backgroundColor: 'rgba(75, 192, 192, 0.2)',
              fill: true,
              tension: 0.4
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: {
                display: false
              }
            }
          }
        })
      }
    }

    onMounted(() => {
      initChart()
    })

    onUnmounted(() => {
      if (chartInstance) chartInstance.destroy()
    })

    return {
      profile,
      badges,
      tradingStats,
      preferences,
      performanceChart,
      saveProfile,
      savePreferences
    }
  }
}
</script>

<style scoped>
.profile-page {
  padding: 16px;
}
</style>