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
      hideLayout: true,
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
      requiresAuth: true,
      title: '儀表板',
      icon: 'mdi-view-dashboard'
    }
  },

  // ==========================================
  // 查詢功能 (公開訪問，不需登入)
  // ==========================================
  {
    path: '/stocks',
    name: 'Stocks',
    component: Stocks,
    meta: {
      requiresAuth: false,
      title: '股票報價',
      icon: 'mdi-chart-line'
    }
  },
  {
    path: '/options',
    name: 'Options',
    component: Options,
    meta: {
      requiresAuth: false,
      title: '選擇權鏈',
      icon: 'mdi-chart-bell-curve-cumulative'
    }
  },
  {
    path: '/black-scholes',
    name: 'BlackScholes',
    component: BlackScholes,
    meta: {
      requiresAuth: false,
      title: 'Black-Scholes 計算',
      icon: 'mdi-calculator'
    }
  },
  {
    path: '/volatility',
    name: 'Volatility',
    component: Volatility,
    meta: {
      requiresAuth: false,
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
      requiresAuth: true,
      title: '預測模型',
      icon: 'mdi-crystal-ball'
    }
  },
  {
    path: '/backtest',
    name: 'Backtest',
    component: Backtest,
    meta: {
      requiresAuth: true,
      title: '策略回測',
      icon: 'mdi-history'
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
  if (to.meta.title) {
    document.title = `${to.meta.title} - Stock_Analysis`
  }

  const requiresAuth = to.matched.some(record => record.meta.requiresAuth)
  const token = localStorage.getItem('authToken')

  if (requiresAuth && !token) {
    next({
      path: '/login',
      query: { redirect: to.fullPath }
    })
  } else if (!requiresAuth && token && (to.path === '/login' || to.path === '/register')) {
    next('/dashboard')
  } else {
    next()
  }
})

router.afterEach((to, from) => {
  console.log(`📍 路由切換: ${from.path} → ${to.path}`)
})

export default router
