import { createRouter, createWebHistory } from 'vue-router'

// 登入相關頁面 (立即載入)
import Login from '@/views/Login.vue'
import Register from '@/views/Register.vue'

// 主要頁面元件 (立即載入 Dashboard)
import Dashboard from '@/views/Dashboard.vue'

// 延遲載入其他頁面
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
  // ==========================================
  // 認證路由 (公開訪問，不需登入)
  // ==========================================
  {
    path: '/login',
    name: 'Login',
    component: Login,
    meta: {
      requiresAuth: false,
      hideLayout: true, // 不顯示主要佈局
      title: '登入'
    }
  },
  {
    path: '/register',
    name: 'Register',
    component: Register,
    meta: {
      requiresAuth: false,
      hideLayout: true,
      title: '註冊'
    }
  },

  // ==========================================
  // 主要應用路由
  // ==========================================
  {
    path: '/',
    redirect: '/dashboard'
  },
  {
    path: '/dashboard',
    name: 'Dashboard',
    component: Dashboard,
    meta: {
      requiresAuth: true, // ✅ 需要登入
      title: '儀表板',
      icon: 'mdi-view-dashboard'
    }
  },

  // ==========================================
  // 查詢功能 (可選擇是否需要登入)
  // ==========================================
  {
    path: '/stocks',
    name: 'Stocks',
    component: Stocks,
    meta: {
      requiresAuth: false, // 公開查詢
      title: '股票報價',
      icon: 'mdi-chart-line'
    }
  },
  {
    path: '/options',
    name: 'Options',
    component: Options,
    meta: {
      requiresAuth: false, // 公開查詢
      title: '選擇權鏈',
      icon: 'mdi-chart-bell-curve-cumulative'
    }
  },
  {
    path: '/black-scholes',
    name: 'BlackScholes',
    component: BlackScholes,
    meta: {
      requiresAuth: false, // 公開計算工具
      title: 'Black-Scholes 計算',
      icon: 'mdi-calculator'
    }
  },
  {
    path: '/volatility',
    name: 'Volatility',
    component: Volatility,
    meta: {
      requiresAuth: false, // 公開查詢
      title: '波動率分析',
      icon: 'mdi-chart-timeline-variant'
    }
  },

  // ==========================================
  // 進階功能 (需要登入)
  // ==========================================
  {
    path: '/predictions',
    name: 'PredictionAnalysis',
    component: PredictionAnalysis,
    meta: {
      requiresAuth: true, // ✅ 需要登入
      title: '預測模型',
      icon: 'mdi-crystal-ball'
    }
  },
  {
    path: '/backtest',
    name: 'Backtest',
    component: Backtest,
    meta: {
      requiresAuth: true, // ✅ 需要登入
      title: '策略回測',
      icon: 'mdi-history'
    }
  },
  {
    path: '/reports',
    name: 'Reports',
    component: Reports,
    meta: {
      requiresAuth: true, // ✅ 需要登入
      title: '報表分析',
      icon: 'mdi-file-document-multiple'
    }
  },

  // ==========================================
  // 系統功能 (需要登入)
  // ==========================================
  {
    path: '/realtime',
    name: 'Realtime',
    component: Realtime,
    meta: {
      requiresAuth: true, // ✅ 需要登入
      title: '即時監控',
      icon: 'mdi-access-point'
    }
  },
  {
    path: '/settings',
    name: 'Settings',
    component: Settings,
    meta: {
      requiresAuth: true, // ✅ 需要登入
      title: '系統設定',
      icon: 'mdi-cog'
    }
  },
  {
    path: '/profile',
    name: 'Profile',
    component: Profile,
    meta: {
      requiresAuth: true, // ✅ 需要登入
      title: '個人資料',
      icon: 'mdi-account'
    }
  },

  // ==========================================
  // 404 頁面
  // ==========================================
  {
    path: '/:pathMatch(.*)*',
    redirect: '/dashboard'
  }
]

// 建立路由實例
const router = createRouter({
  history: createWebHistory(),
  routes
})

// ==========================================
// 路由守衛 - 認證檢查
// ==========================================
router.beforeEach((to, from, next) => {
  // 設定頁面標題
  if (to.meta.title) {
    document.title = `${to.meta.title} - Stock_Analysis`
  }

  // 檢查是否需要認證
  const requiresAuth = to.matched.some(record => record.meta.requiresAuth)
  const token = localStorage.getItem('authToken')
  
  if (requiresAuth && !token) {
    // 需要登入但沒有 token
    console.log('🔒 需要登入，重新導向到登入頁面')
    next({
      path: '/login',
      query: { redirect: to.fullPath } // 記錄原本要去的位置
    })
  } else if (!requiresAuth && token && (to.path === '/login' || to.path === '/register')) {
    // 已登入但試圖訪問登入/註冊頁，導向儀表板
    console.log('✅ 已登入，導向儀表板')
    next('/dashboard')
  } else {
    // 其他情況，正常導航
    next()
  }
})

// 全域後置鉤子 - 記錄路由切換
router.afterEach((to, from) => {
  console.log(`📍 路由切換: ${from.path} → ${to.path}`)
})

export default router