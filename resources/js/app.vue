<template>
  <v-app>
    <!-- 側邊欄 -->
    <v-navigation-drawer
      v-model="drawer"
      :rail="rail"
      permanent
      @click="rail = false"
    >
      <v-list-item
        prepend-avatar="/logo.png"
        title="Stock_Analysis"
        subtitle="台股選擇權交易系統"
        nav
      >
        <template v-slot:append>
          <v-btn
            variant="text"
            icon="mdi-chevron-left"
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
          :to="item.to"
          :value="item.title"
          color="primary"
        ></v-list-item>
      </v-list>
    </v-navigation-drawer>

    <!-- 頂部導航欄 -->
    <v-app-bar>
      <v-app-bar-nav-icon @click="drawer = !drawer" v-if="mobile"></v-app-bar-nav-icon>

      <v-toolbar-title>
        {{ currentPageTitle }}
      </v-toolbar-title>

      <v-spacer></v-spacer>

      <!-- 市場狀態指示 -->
      <v-chip class="mr-2" :color="marketStatus.color">
        <v-icon start>{{ marketStatus.icon }}</v-icon>
        {{ marketStatus.text }}
      </v-chip>

      <!-- 時間顯示 -->
      <v-chip class="mr-2">
        <v-icon start>mdi-clock</v-icon>
        {{ currentTime }}
      </v-chip>

      <!-- 主題切換 -->
      <v-btn icon @click="toggleTheme">
        <v-icon>{{ theme.global.current.value.dark ? 'mdi-weather-sunny' : 'mdi-weather-night' }}</v-icon>
      </v-btn>

      <!-- 通知 -->
      <v-btn icon>
        <v-badge :content="notifications" color="error" v-if="notifications > 0">
          <v-icon>mdi-bell</v-icon>
        </v-badge>
        <v-icon v-else>mdi-bell-outline</v-icon>
      </v-btn>

      <!-- 使用者選單 -->
      <v-menu>
        <template v-slot:activator="{ props }">
          <v-btn icon v-bind="props">
            <v-avatar size="32">
              <v-icon>mdi-account-circle</v-icon>
            </v-avatar>
          </v-btn>
        </template>
        <v-list>
          <v-list-item @click="profile">
            <v-list-item-title>個人資料</v-list-item-title>
          </v-list-item>
          <v-list-item @click="settings">
            <v-list-item-title>系統設定</v-list-item-title>
          </v-list-item>
          <v-divider></v-divider>
          <v-list-item @click="logout">
            <v-list-item-title>登出</v-list-item-title>
          </v-list-item>
        </v-list>
      </v-menu>
    </v-app-bar>

    <!-- 主要內容區 -->
    <v-main>
      <v-container fluid>
        <router-view></router-view>
      </v-container>
    </v-main>

    <!-- 頁尾 -->
    <v-footer app>
      <v-row justify="center" no-gutters>
        <v-col class="text-center" cols="12">
          {{ new Date().getFullYear() }} — <strong>Stock_Analysis System</strong>
        </v-col>
      </v-row>
    </v-footer>
  </v-app>
</template>

<script>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useTheme } from 'vuetify'
import { useDisplay } from 'vuetify'

export default {
  name: 'App',
  setup() {
    const router = useRouter()
    const route = useRoute()
    const theme = useTheme()
    const { mobile } = useDisplay()

    const drawer = ref(true)
    const rail = ref(false)
    const notifications = ref(0)
    const currentTime = ref('')
    let timeInterval = null

    const menuItems = ref([
      {
        title: '儀表板',
        icon: 'mdi-view-dashboard',
        to: '/dashboard'
      },
      {
        title: '股票報價',
        icon: 'mdi-chart-line',
        to: '/stocks'
      },
      {
        title: '選擇權鏈',
        icon: 'mdi-format-list-bulleted',
        to: '/options'
      },
      {
        title: 'Black-Scholes',
        icon: 'mdi-calculator',
        to: '/black-scholes'
      },
      {
        title: '波動率分析',
        icon: 'mdi-chart-bell-curve',
        to: '/volatility'
      },
      {
        title: '預測模型',
        icon: 'mdi-crystal-ball',
        to: '/predictions'
      },
      {
        title: '策略回測',
        icon: 'mdi-history',
        to: '/backtest'
      },
      {
        title: '即時監控',
        icon: 'mdi-monitor-eye',
        to: '/realtime'
      },
      {
        title: '報表分析',
        icon: 'mdi-file-chart',
        to: '/reports'
      },
      {
        title: '系統設定',
        icon: 'mdi-cog',
        to: '/settings'
      }
    ])

    // 計算當前頁面標題
    const currentPageTitle = computed(() => {
      const currentRoute = menuItems.value.find(item => item.to === route.path)
      return currentRoute ? currentRoute.title : 'Stock_Analysis'
    })

    // 市場狀態
    const marketStatus = computed(() => {
      const now = new Date()
      const hour = now.getHours()
      const minute = now.getMinutes()
      const day = now.getDay()

      // 週末
      if (day === 0 || day === 6) {
        return {
          text: '休市',
          color: 'grey',
          icon: 'mdi-sleep'
        }
      }

      // 交易時間: 9:00 - 13:30
      if (hour === 9 && minute >= 0 ||
          hour >= 10 && hour < 13 ||
          hour === 13 && minute <= 30) {
        return {
          text: '開盤中',
          color: 'success',
          icon: 'mdi-chart-line-variant'
        }
      }

      // 盤後
      if (hour === 13 && minute > 30 || hour === 14) {
        return {
          text: '盤後交易',
          color: 'warning',
          icon: 'mdi-clock-alert'
        }
      }

      return {
        text: '已收盤',
        color: 'grey',
        icon: 'mdi-close-circle'
      }
    })

    // 更新時間
    const updateTime = () => {
      const now = new Date()
      currentTime.value = now.toLocaleTimeString('zh-TW', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
      })
    }

    // 切換主題
    const toggleTheme = () => {
      theme.global.name.value = theme.global.current.value.dark ? 'light' : 'dark'
    }

    // 個人資料
    const profile = () => {
      router.push('/profile')
    }

    // 系統設定
    const settings = () => {
      router.push('/settings')
    }

    // 登出
    const logout = () => {
      if (confirm('確定要登出嗎？')) {
        // 清除 token
        localStorage.removeItem('authToken')
        // 導向登入頁（如果有的話）
        // router.push('/login')
        window.location.reload()
      }
    }

    // 生命週期
    onMounted(() => {
      updateTime()
      timeInterval = setInterval(updateTime, 1000)

      // 檢查認證狀態
      // const token = localStorage.getItem('authToken')
      // if (!token && route.path !== '/login') {
      //   router.push('/login')
      // }
    })

    onUnmounted(() => {
      if (timeInterval) {
        clearInterval(timeInterval)
      }
    })

    return {
      drawer,
      rail,
      notifications,
      menuItems,
      currentPageTitle,
      currentTime,
      marketStatus,
      theme,
      mobile,
      toggleTheme,
      profile,
      settings,
      logout
    }
  }
}
</script>

<style>
html {
  overflow-y: auto !important;
}

.v-application {
  font-family: 'Noto Sans TC', sans-serif !important;
}

[v-cloak] {
  display: none;
}
</style>
