import { createRouter, createWebHistory } from 'vue-router'

import DashboardLayout from '@/components/DashboardLayout.vue'
import BlackScholesCalculator from '@/views/BlackScholesCalculator.vue'
import VolatilityAnalysis from '@/views/VolatilityAnalysis.vue'

// Views
import Dashboard from '../views/Dashboard.vue'
import BlackScholes from '../views/BlackScholes.vue'
import Volatility from '../views/Volatility.vue'
import Backtest from '../views/Backtest.vue'
import Realtime from '../views/Realtime.vue'
import Reports from '../views/Reports.vue'
import Settings from '../views/Settings.vue'
import Profile from '../views/Profile.vue'
import Login from '../views/Login.vue'
import PredictionAnalysis from '@/views/PredictionAnalysis.vue'
import StockList from '@/views/StockList.vue'
import OptionList from '@/views/OptionList.vue'

const routes = [
  {
    path: '/',
    name: 'Dashboard',
    component: Dashboard,
    meta: {
      title: '儀表板',
      icon: 'mdi-view-dashboard'
    }
  },
  {
    path: '/stocks',
    name: 'StockList',
    component: StockList,
    meta: {
      title: '股票列表',
      icon: 'mdi-chart-line'
    }
  },

  {
    path: '/options',
    name: 'OptionList',
    component: OptionList,
    meta: {
      title: '選擇權列表',
      icon: 'mdi-chart-bell-curve'
    }
  },
  {
    path: '/black-scholes',
    name: 'BlackScholes',
    component: BlackScholes,
    meta: {
      title: 'Black-Scholes 計算',
      icon: 'mdi-calculator'
    }
  },
   {
    path: '/volatility',
    name: 'VolatilityAnalysis',
    component: VolatilityAnalysis,
    meta: {
      title: '波動率分析',
      icon: 'mdi-pulse'
    }
  },
  {
    path: '/predictions',
    name: 'PredictionAnalysis',
    component: PredictionAnalysis,
    meta: {
      title: '預測分析',
      icon: 'mdi-crystal-ball',
      badge: 'NEW'
    }
  },
  {
    path: '/backtest',
    name: 'BacktestSystem',
    component: BacktestSystem,
    meta: {
      title: '策略回測',
      icon: 'mdi-history'
    }
  },
  {
    path: '/realtime',
    name: 'Realtime',
    component: Realtime,
    meta: { requiresAuth: true }
  },
  {
    path: '/reports',
    name: 'Reports',
    component: Reports,
    meta: {
      title: '報表中心',
      icon: 'mdi-file-chart'
    }
  },
  {
    path: '/settings',
    name: 'Settings',
    component: Settings,
    meta: {
      title: '系統設定',
      icon: 'mdi-cog'
    }
  },
  {
    path: '/profile',
    name: 'Profile',
    component: Profile,
    meta: { requiresAuth: true }
  },
  {
    path: '/login',
    name: 'Login',
    component: Login,
    meta: { requiresAuth: false }
  },
  {
    path: '/',
    component: DashboardLayout,
    children: [
      {
        path: 'black-scholes',
        name: 'BlackScholes',
        component: BlackScholesCalculator
      },
      {
        path: 'volatility',
        name: 'Volatility',
        component: VolatilityAnalysis
      }
    ]
  }
]

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes
})

// Navigation Guard
router.beforeEach((to, from, next) => {
  const isAuthenticated = localStorage.getItem('authToken')

  if (to.meta.requiresAuth && !isAuthenticated) {
    next('/login')
  } else if (to.name === 'Login' && isAuthenticated) {
    next('/')
  } else {
    next()
  }
})

export default router
