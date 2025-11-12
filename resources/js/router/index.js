import { createRouter, createWebHistory } from 'vue-router'

// 頁面元件
import Dashboard from '@/views/Dashboard.vue'

// 延遲載入其他頁面（當實際建立這些檔案後）
const Stocks = () => import('@/views/Stocks.vue').catch(() => Dashboard)
const Options = () => import('@/views/Options.vue').catch(() => Dashboard)
const BlackScholes = () => import('@/views/BlackScholes.vue').catch(() => Dashboard)
const Volatility = () => import('@/views/Volatility.vue').catch(() => Dashboard)
const PredictionAnalysis = () => import('@/views/PredictionAnalysis.vue').catch(() => Dashboard)
const Backtest = () => import('@/views/Backtest.vue').catch(() => Dashboard)
const Realtime = () => import('@/views/Realtime.vue').catch(() => Dashboard)
const Reports = () => import('@/views/Reports.vue').catch(() => Dashboard)
const Settings = () => import('@/views/Settings.vue').catch(() => Dashboard)
const Profile = () => import('@/views/Profile.vue').catch(() => Dashboard)

const routes = [
  {
    path: '/',
    redirect: '/dashboard'
  },
  {
    path: '/dashboard',
    name: 'Dashboard',
    component: Dashboard,
    meta: {
      requiresAuth: false, // 暫時設為 false 以便測試
      title: '儀表板'
    }
  },
  {
    path: '/stocks',
    name: 'Stocks',
    component: Stocks,
    meta: {
      requiresAuth: false,
      title: '股票報價'
    }
  },
  {
    path: '/options',
    name: 'Options',
    component: Options,
    meta: {
      requiresAuth: false,
      title: '選擇權鏈'
    }
  },
  {
    path: '/black-scholes',
    name: 'BlackScholes',
    component: BlackScholes,
    meta: {
      requiresAuth: false,
      title: 'Black-Scholes 計算'
    }
  },
  {
    path: '/volatility',
    name: 'Volatility',
    component: Volatility,
    meta: {
      requiresAuth: false,
      title: '波動率分析'
    }
  },
  {
    path: '/predictions',
    name: 'PredictionAnalysis',
    component: PredictionAnalysis,
    meta: {
      requiresAuth: false,
      title: '預測模型'
    }
  },
  {
    path: '/backtest',
    name: 'Backtest',
    component: Backtest,
    meta: {
      requiresAuth: false,
      title: '策略回測'
    }
  },
  {
    path: '/realtime',
    name: 'Realtime',
    component: Realtime,
    meta: {
      requiresAuth: false,
      title: '即時監控'
    }
  },
  {
    path: '/reports',
    name: 'Reports',
    component: Reports,
    meta: {
      requiresAuth: false,
      title: '報表分析'
    }
  },
  {
    path: '/settings',
    name: 'Settings',
    component: Settings,
    meta: {
      requiresAuth: false,
      title: '系統設定'
    }
  },
  {
    path: '/profile',
    name: 'Profile',
    component: Profile,
    meta: {
      requiresAuth: false,
      title: '個人資料'
    }
  },
  // 404 頁面
  {
    path: '/:pathMatch(.*)*',
    redirect: '/dashboard'
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

// 路由守衛
router.beforeEach((to, from, next) => {
  // 設定頁面標題
  if (to.meta.title) {
    document.title = `${to.meta.title} - Stock_Analysis`
  }

  // 檢查認證（如果需要）
  // if (to.meta.requiresAuth) {
  //   const token = localStorage.getItem('authToken')
  //   if (!token) {
  //     next('/login')
  //   } else {
  //     next()
  //   }
  // } else {
  //   next()
  // }

  next()
})

export default router
