<template>
  <v-app>
    <!-- Navigation Drawer -->
    <v-navigation-drawer
      v-model="drawer"
      app
      permanent
      :rail="miniVariant"
      @click="miniVariant = false"
    >
      <v-list-item
        prepend-avatar="/logo.png"
        :title="miniVariant ? '' : '選擇權交易系統'"
        :subtitle="miniVariant ? '' : 'Options Trading System'"
      />

      <v-divider></v-divider>

      <v-list density="compact" nav>
        <v-list-item
          v-for="item in menuItems"
          :key="item.title"
          :to="item.to"
          :prepend-icon="item.icon"
          :title="item.title"
        />
      </v-list>
    </v-navigation-drawer>

    <!-- App Bar -->
    <v-app-bar app elevation="1">
      <v-app-bar-nav-icon @click="miniVariant = !miniVariant"></v-app-bar-nav-icon>

      <v-toolbar-title>{{ pageTitle }}</v-toolbar-title>

      <v-spacer></v-spacer>

      <!-- Market Status -->
      <v-chip
        :color="marketStatus.color"
        variant="flat"
        class="mr-2"
      >
        <v-icon start>{{ marketStatus.icon }}</v-icon>
        {{ marketStatus.text }}
      </v-chip>

      <!-- Notifications -->
      <v-btn icon>
        <v-badge :content="notifications" :value="notifications" color="error" overlap>
          <v-icon>mdi-bell</v-icon>
        </v-badge>
      </v-btn>

      <!-- Theme Toggle -->
      <v-btn icon @click="toggleTheme">
        <v-icon>{{ theme === 'light' ? 'mdi-weather-sunny' : 'mdi-weather-night' }}</v-icon>
      </v-btn>

      <!-- User Menu -->
      <v-menu>
        <template v-slot:activator="{ props }">
          <v-btn icon v-bind="props">
            <v-icon>mdi-account-circle</v-icon>
          </v-btn>
        </template>
        <v-list>
          <v-list-item @click="profile">
            <v-list-item-title>個人資料</v-list-item-title>
          </v-list-item>
          <v-list-item @click="settings">
            <v-list-item-title>設定</v-list-item-title>
          </v-list-item>
          <v-divider></v-divider>
          <v-list-item @click="logout">
            <v-list-item-title>登出</v-list-item-title>
          </v-list-item>
        </v-list>
      </v-menu>
    </v-app-bar>

    <!-- Main Content -->
    <v-main>
      <v-container fluid>
        <router-view></router-view>
      </v-container>
    </v-main>

    <!-- Footer -->
    <v-footer app>
      <v-row justify="center" no-gutters>
        <v-col class="text-center" cols="12">
          {{ new Date().getFullYear() }} — <strong>Options Trading System</strong>
        </v-col>
      </v-row>
    </v-footer>
  </v-app>
</template>

<script>
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useTheme } from 'vuetify'

export default {
  name: 'App',
  setup() {
    const router = useRouter()
    const route = useRoute()
    const theme = useTheme()

    const drawer = ref(true)
    const miniVariant = ref(false)
    const notifications = ref(3)

    const menuItems = ref([
      {
        title: '儀表板',
        icon: 'mdi-view-dashboard',
        to: '/'
      },
      {
        title: '股票行情',
        icon: 'mdi-chart-line',
        to: '/stocks'
      },
      {
        title: '選擇權',
        icon: 'mdi-chart-bell-curve',
        to: '/options'
      },
      {
        title: 'Black-Scholes',
        icon: 'mdi-calculator',
        to: '/black-scholes'
      },
      {
        title: '波動率分析',
        icon: 'mdi-wave',
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
        title: '報表',
        icon: 'mdi-file-chart',
        to: '/reports'
      },
      {
        title: '設定',
        icon: 'mdi-cog',
        to: '/settings'
      }
    ])

    const pageTitle = computed(() => {
      const currentRoute = menuItems.value.find(item => item.to === route.path)
      return currentRoute ? currentRoute.title : '選擇權交易系統'
    })

    const marketStatus = computed(() => {
      const now = new Date()
      const hour = now.getHours()
      const minute = now.getMinutes()
      const day = now.getDay()

      // 週末休市
      if (day === 0 || day === 6) {
        return {
          text: '休市',
          color: 'grey',
          icon: 'mdi-sleep'
        }
      }

      // 交易時間 9:00 - 13:30
      if (hour === 9 && minute >= 0 ||
          hour >= 10 && hour < 13 ||
          hour === 13 && minute <= 30) {
        return {
          text: '開盤中',
          color: 'success',
          icon: 'mdi-check-circle'
        }
      }

      // 盤前
      if (hour >= 8 && hour < 9) {
        return {
          text: '盤前',
          color: 'warning',
          icon: 'mdi-clock-alert'
        }
      }

      // 盤後
      return {
        text: '收盤',
        color: 'error',
        icon: 'mdi-close-circle'
      }
    })

    const toggleTheme = () => {
      theme.global.name.value = theme.global.current.value.dark ? 'light' : 'dark'
    }

    const profile = () => {
      router.push('/profile')
    }

    const settings = () => {
      router.push('/settings')
    }

    const logout = () => {
      // 執行登出邏輯
      console.log('Logging out...')
    }

    onMounted(() => {
      // WebSocket 連線
      if (window.Echo) {
        window.Echo.channel('stock-updates')
          .listen('StockPriceUpdated', (e) => {
            console.log('Stock price updated:', e)
          })
      }
    })

    return {
      drawer,
      miniVariant,
      notifications,
      menuItems,
      pageTitle,
      marketStatus,
      theme: theme.global.name,
      toggleTheme,
      profile,
      settings,
      logout
    }
  }
}
</script>

<style scoped>
.v-navigation-drawer {
  padding-top: 0;
}
</style>
