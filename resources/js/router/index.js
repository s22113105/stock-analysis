import { createRouter, createWebHistory } from 'vue-router'

import DashboardLayout from '@/components/DashboardLayout.vue'
import BlackScholesCalculator from '@/views/BlackScholesCalculator.vue'
import VolatilityAnalysis from '@/views/VolatilityAnalysis.vue'

// Views
import Dashboard from '../views/Dashboard.vue'
import Stocks from '../views/Stocks.vue'
import Options from '../views/Options.vue'
import BlackScholes from '../views/BlackScholes.vue'
import Volatility from '../views/Volatility.vue'
import Predictions from '../views/Predictions.vue'
import Backtest from '../views/Backtest.vue'
import Realtime from '../views/Realtime.vue'
import Reports from '../views/Reports.vue'
import Settings from '../views/Settings.vue'
import Profile from '../views/Profile.vue'
import Login from '../views/Login.vue'

const routes = [
  {
    path: '/',
    name: 'Dashboard',
    component: Dashboard,
    meta: { requiresAuth: true }
  },
  {
    path: '/stocks',
    name: 'Stocks',
    component: Stocks,
    meta: { requiresAuth: true }
  },
  {
    path: '/options',
    name: 'Options',
    component: Options,
    meta: { requiresAuth: true }
  },
  {
    path: '/black-scholes',
    name: 'BlackScholes',
    component: BlackScholes,
    meta: { requiresAuth: true }
  },
  {
    path: '/volatility',
    name: 'Volatility',
    component: Volatility,
    meta: { requiresAuth: true }
  },
  {
    path: '/predictions',
    name: 'Predictions',
    component: Predictions,
    meta: { requiresAuth: true }
  },
  {
    path: '/backtest',
    name: 'Backtest',
    component: Backtest,
    meta: { requiresAuth: true }
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
    meta: { requiresAuth: true }
  },
  {
    path: '/settings',
    name: 'Settings',
    component: Settings,
    meta: { requiresAuth: true }
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