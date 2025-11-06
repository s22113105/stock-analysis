<template>
  <v-app>
    <!-- 導航列 -->
    <v-app-bar 
      :elevation="2" 
      color="primary"
      dark
    >
      <v-app-bar-nav-icon @click="drawer = !drawer"></v-app-bar-nav-icon>
      
      <v-toolbar-title class="font-weight-bold">
        📈 選擇權交易分析系統
      </v-toolbar-title>

      <v-spacer></v-spacer>

      <!-- 即時時間 -->
      <v-chip color="success" variant="flat" class="mr-4">
        <v-icon start>mdi-clock-outline</v-icon>
        {{ currentTime }}
      </v-chip>

      <!-- 通知鈴鐺 -->
      <v-btn icon @click="showNotifications = !showNotifications">
        <v-badge 
          :content="notificationCount" 
          :value="notificationCount" 
          color="error"
        >
          <v-icon>mdi-bell</v-icon>
        </v-badge>
      </v-btn>

      <!-- 使用者選單 -->
      <v-menu>
        <template v-slot:activator="{ props }">
          <v-btn icon v-bind="props">
            <v-avatar size="32">
              <v-icon>mdi-account</v-icon>
            </v-avatar>
          </v-btn>
        </template>
        <v-list>
          <v-list-item>
            <v-list-item-title>個人設定</v-list-item-title>
          </v-list-item>
          <v-list-item>
            <v-list-item-title>登出</v-list-item-title>
          </v-list-item>
        </v-list>
      </v-menu>
    </v-app-bar>

    <!-- 側邊導航 -->
    <v-navigation-drawer
      v-model="drawer"
      :rail="rail"
      permanent
      @click="rail = false"
    >
      <v-list-item
        prepend-icon="mdi-chart-line"
        title="選擇權系統"
        nav
      >
        <template v-slot:append>
          <v-btn
            icon="mdi-chevron-left"
            variant="text"
            @click.stop="rail = !rail"
          ></v-btn>
        </template>
      </v-list-item>

      <v-divider></v-divider>

      <v-list density="compact" nav>
        <v-list-item
          v-for="item in menuItems"
          :key="item.title"
          :prepend-icon="item.icon"
          :title="item.title"
          :to="item.path"
          :value="item.title"
          color="primary"
        ></v-list-item>
      </v-list>
    </v-navigation-drawer>

    <!-- 主要內容區 -->
    <v-main>
      <v-container fluid>
        <!-- 麵包屑 -->
        <v-breadcrumbs :items="breadcrumbs" class="px-0">
          <template v-slot:divider>
            <v-icon>mdi-chevron-right</v-icon>
          </template>
        </v-breadcrumbs>

        <!-- 頁面內容 -->
        <router-view></router-view>
      </v-container>
    </v-main>

    <!-- 通知抽屜 -->
    <v-navigation-drawer
      v-model="showNotifications"
      location="right"
      temporary
      width="400"
    >
      <v-list>
        <v-list-item>
          <v-list-item-title class="text-h6">
            通知中心
          </v-list-item-title>
        </v-list-item>
        <v-divider></v-divider>

        <v-list-item
          v-for="notification in notifications"
          :key="notification.id"
          :subtitle="notification.time"
        >
          <template v-slot:prepend>
            <v-icon :color="notification.color">{{ notification.icon }}</v-icon>
          </template>
          <v-list-item-title>{{ notification.title }}</v-list-item-title>
          <v-list-item-subtitle>{{ notification.message }}</v-list-item-subtitle>
        </v-list-item>

        <v-list-item v-if="notifications.length === 0">
          <v-list-item-title class="text-center text-grey">
            目前沒有通知
          </v-list-item-title>
        </v-list-item>
      </v-list>
    </v-navigation-drawer>
  </v-app>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRoute } from 'vue-router'

// 狀態管理
const drawer = ref(true)
const rail = ref(false)
const showNotifications = ref(false)
const currentTime = ref('')
const notifications = ref([])

// 選單項目
const menuItems = ref([
  {
    title: '儀表板',
    icon: 'mdi-view-dashboard',
    path: '/dashboard'
  },
  {
    title: '股票管理',
    icon: 'mdi-chart-candlestick',
    path: '/stocks'
  },
  {
    title: '選擇權管理',
    icon: 'mdi-finance',
    path: '/options'
  },
  {
    title: 'Black-Scholes',
    icon: 'mdi-calculator',
    path: '/black-scholes'
  },
  {
    title: '波動率分析',
    icon: 'mdi-chart-bell-curve',
    path: '/volatility'
  },
  {
    title: '策略回測',
    icon: 'mdi-history',
    path: '/backtest'
  },
  {
    title: '預測模型',
    icon: 'mdi-crystal-ball',
    path: '/predictions'
  },
  {
    title: '資料爬蟲',
    icon: 'mdi-spider',
    path: '/crawler'
  },
  {
    title: '系統設定',
    icon: 'mdi-cog',
    path: '/settings'
  }
])

// 路由相關
const route = useRoute()
const breadcrumbs = computed(() => {
  const paths = route.path.split('/').filter(p => p)
  return [
    { title: '首頁', disabled: false, href: '/' },
    ...paths.map((path, index) => ({
      title: path.charAt(0).toUpperCase() + path.slice(1),
      disabled: index === paths.length - 1,
      href: '/' + paths.slice(0, index + 1).join('/')
    }))
  ]
})

// 通知數量
const notificationCount = computed(() => notifications.value.length)

// 更新當前時間
const updateTime = () => {
  const now = new Date()
  currentTime.value = now.toLocaleString('zh-TW', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false
  })
}

// 載入通知
const loadNotifications = () => {
  // TODO: 從 API 載入通知
  notifications.value = [
    {
      id: 1,
      title: '資料更新完成',
      message: '股票價格資料已更新至最新',
      time: '5分鐘前',
      icon: 'mdi-check-circle',
      color: 'success'
    },
    {
      id: 2,
      title: '波動率異常',
      message: 'TXO 隱含波動率超過歷史平均值',
      time: '1小時前',
      icon: 'mdi-alert',
      color: 'warning'
    }
  ]
}

// 計時器
let timeInterval

onMounted(() => {
  updateTime()
  loadNotifications()
  timeInterval = setInterval(updateTime, 1000)
})

onUnmounted(() => {
  if (timeInterval) {
    clearInterval(timeInterval)
  }
})
</script>

<style scoped>
.v-app-bar {
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1) !important;
}

.v-navigation-drawer {
  z-index: 1001;
}
</style>