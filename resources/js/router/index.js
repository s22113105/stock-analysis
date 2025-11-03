// resources/js/router/index.js
import { createRouter, createWebHistory } from 'vue-router'

// Import Views (lazy loading)
const Dashboard = () => import('../views/Dashboard.vue')
const Stocks = () => import('../views/Stocks.vue')
const Options = () => import('../views/Options.vue')
const BlackScholes = () => import('../views/BlackScholes.vue')
const Volatility = () => import('../views/Volatility.vue')
const Predictions = () => import('../views/Predictions.vue')
const Backtest = () => import('../views/Backtest.vue')
const Realtime = () => import('../views/Realtime.vue')
const Reports = () => import('../views/Reports.vue')
const Settings = () => import('../views/Settings.vue')
const Profile = () => import('../views/Profile.vue')
const Login = () => import('../views/Login.vue')

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
    meta: { guest: true }
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

// Navigation Guards
router.beforeEach((to, from, next) => {
  const isAuthenticated = localStorage.getItem('auth_token')

  if (to.matched.some(record => record.meta.requiresAuth)) {
    if (!isAuthenticated) {
      next({
        path: '/login',
        query: { redirect: to.fullPath }
      })
    } else {
      next()
    }
  } else if (to.matched.some(record => record.meta.guest)) {
    if (isAuthenticated) {
      next({ path: '/' })
    } else {
      next()
    }
  } else {
    next()
  }
})

export default router
