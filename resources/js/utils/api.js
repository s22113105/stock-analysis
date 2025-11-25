/**
 * API 工具類
 * 
 * 功能：
 * - 封裝 Axios 請求
 * - 統一處理錯誤
 * - 自動添加認證 Token
 * - 請求/回應攔截器
 */

import axios from 'axios'

// 建立 Axios 實例
const api = axios.create({
    baseURL: '/api',
    timeout: 30000,
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
    }
})

// 請求攔截器
api.interceptors.request.use(
    (config) => {
        // 從 localStorage 取得 Token
        const token = localStorage.getItem('auth_token')
        
        if (token) {
            config.headers.Authorization = `Bearer ${token}`
        }

        // 開發模式下記錄請求
        if (import.meta.env.DEV) {
            console.log(`[API Request] ${config.method?.toUpperCase()} ${config.url}`, config.params || config.data)
        }

        return config
    },
    (error) => {
        console.error('[API Request Error]', error)
        return Promise.reject(error)
    }
)

// 回應攔截器
api.interceptors.response.use(
    (response) => {
        // 開發模式下記錄回應
        if (import.meta.env.DEV) {
            console.log(`[API Response] ${response.config.url}`, response.data)
        }

        return response
    },
    (error) => {
        // 處理錯誤回應
        if (error.response) {
            const { status, data } = error.response

            switch (status) {
                case 401:
                    // 未授權，清除 Token 並重新導向登入頁
                    localStorage.removeItem('auth_token')
                    localStorage.removeItem('user')
                    
                    // 避免在登入頁面重複重新導向
                    if (window.location.pathname !== '/login') {
                        window.location.href = '/login'
                    }
                    break

                case 403:
                    console.error('[API 403] 權限不足:', data.message)
                    break

                case 404:
                    console.error('[API 404] 資源不存在:', data.message)
                    break

                case 422:
                    console.error('[API 422] 驗證失敗:', data.errors)
                    break

                case 429:
                    console.error('[API 429] 請求過於頻繁')
                    break

                case 500:
                    console.error('[API 500] 伺服器錯誤:', data.message)
                    break

                default:
                    console.error(`[API ${status}]`, data.message || '未知錯誤')
            }
        } else if (error.request) {
            // 請求已發送但沒有收到回應
            console.error('[API Network Error] 網路連線失敗')
        } else {
            // 請求設定時發生錯誤
            console.error('[API Config Error]', error.message)
        }

        return Promise.reject(error)
    }
)

// ========================================
// 波動率相關 API
// ========================================

/**
 * 波動率 API 服務
 */
export const volatilityApi = {
    /**
     * 取得歷史波動率
     * @param {number} stockId - 股票 ID
     * @param {Object} options - 選項
     * @param {number} options.period - 計算期間 (天)
     * @param {string} options.endDate - 結束日期
     * @param {string} options.method - 計算方法
     */
    getHistorical(stockId, options = {}) {
        return api.get(`/volatility/historical/${stockId}`, { params: options })
    },

    /**
     * 取得隱含波動率
     * @param {number} optionId - 選擇權 ID
     */
    getImplied(optionId) {
        return api.get(`/volatility/implied/${optionId}`)
    },

    /**
     * 取得波動率錐
     * @param {number} stockId - 股票 ID
     * @param {number} lookbackDays - 回測天數
     */
    getCone(stockId, lookbackDays = 252) {
        return api.get(`/volatility/cone/${stockId}`, { 
            params: { lookback_days: lookbackDays } 
        })
    },

    /**
     * 取得波動率曲面
     * @param {number} stockId - 股票 ID
     * @param {string} date - 日期
     */
    getSurface(stockId, date = null) {
        const params = date ? { date } : {}
        return api.get(`/volatility/surface/${stockId}`, { params })
    },

    /**
     * 取得波動率偏斜
     * @param {number} stockId - 股票 ID
     * @param {string} expiry - 到期日
     */
    getSkew(stockId, expiry = null) {
        const params = expiry ? { expiry } : {}
        return api.get(`/volatility/skew/${stockId}`, { params })
    },

    /**
     * 取得 GARCH 預測
     * @param {number} stockId - 股票 ID
     * @param {Object} options - 選項
     * @param {number} options.forecastDays - 預測天數
     * @param {string} options.modelType - 模型類型
     */
    getGarch(stockId, options = {}) {
        return api.get(`/volatility/garch/${stockId}`, { 
            params: {
                forecast_days: options.forecastDays || 5,
                model_type: options.modelType || 'GARCH'
            } 
        })
    },

    /**
     * 觸發波動率計算
     * @param {number} stockId - 股票 ID
     * @param {string} date - 日期
     */
    calculate(stockId, date = null) {
        return api.post('/volatility/calculate', { stock_id: stockId, date })
    }
}

// ========================================
// 股票相關 API
// ========================================

/**
 * 股票 API 服務
 */
export const stockApi = {
    /**
     * 取得股票列表
     * @param {Object} params - 查詢參數
     */
    getList(params = {}) {
        return api.get('/stocks', { params })
    },

    /**
     * 取得單一股票
     * @param {number} id - 股票 ID
     */
    getById(id) {
        return api.get(`/stocks/${id}`)
    },

    /**
     * 依代碼取得股票
     * @param {string} symbol - 股票代碼
     */
    getBySymbol(symbol) {
        return api.get(`/stocks/symbol/${symbol}`)
    },

    /**
     * 取得股票價格歷史
     * @param {number} id - 股票 ID
     * @param {Object} params - 查詢參數
     */
    getPrices(id, params = {}) {
        return api.get(`/stocks/${id}/prices`, { params })
    },

    /**
     * 取得股票統計資料
     * @param {number} id - 股票 ID
     */
    getStatistics(id) {
        return api.get(`/stocks/${id}/statistics`)
    }
}

// ========================================
// 選擇權相關 API
// ========================================

/**
 * 選擇權 API 服務
 */
export const optionApi = {
    /**
     * 取得選擇權列表
     * @param {Object} params - 查詢參數
     */
    getList(params = {}) {
        return api.get('/options', { params })
    },

    /**
     * 取得選擇權鏈
     * @param {string} underlying - 標的
     */
    getChain(underlying) {
        return api.get(`/options/chain/${underlying}`)
    },

    /**
     * 取得 T 字報價表
     * @param {Object} params - 查詢參數
     */
    getChainTable(params = {}) {
        return api.get('/options/chain-table', { params })
    },

    /**
     * 取得單一選擇權
     * @param {number} id - 選擇權 ID
     */
    getById(id) {
        return api.get(`/options/${id}`)
    }
}

// ========================================
// Black-Scholes 相關 API
// ========================================

/**
 * Black-Scholes API 服務
 */
export const blackScholesApi = {
    /**
     * 計算選擇權理論價格
     * @param {Object} params - 計算參數
     */
    calculate(params) {
        return api.post('/black-scholes/calculate', params)
    },

    /**
     * 批次計算
     * @param {Array} items - 計算項目列表
     */
    batchCalculate(items) {
        return api.post('/black-scholes/batch', { items })
    }
}

// ========================================
// 儀表板相關 API
// ========================================

/**
 * 儀表板 API 服務
 */
export const dashboardApi = {
    /**
     * 取得統計資料
     */
    getStats() {
        return api.get('/dashboard/stats')
    },

    /**
     * 取得股票走勢
     */
    getStockTrends() {
        return api.get('/dashboard/stock-trends')
    },

    /**
     * 取得波動率概覽
     */
    getVolatilityOverview() {
        return api.get('/dashboard/volatility-overview')
    },

    /**
     * 取得警示
     */
    getAlerts() {
        return api.get('/dashboard/alerts')
    }
}

// ========================================
// 預測相關 API
// ========================================

/**
 * 預測 API 服務
 */
export const predictionApi = {
    /**
     * 執行預測
     * @param {Object} params - 預測參數
     */
    run(params) {
        return api.post('/predictions/run', params)
    },

    /**
     * 取得預測結果
     * @param {number} stockId - 股票 ID
     */
    getResults(stockId) {
        return api.get(`/predictions/results/${stockId}`)
    },

    /**
     * 取得模型狀態
     */
    getModelStatus() {
        return api.get('/predictions/model-status')
    }
}

// ========================================
// 回測相關 API
// ========================================

/**
 * 回測 API 服務
 */
export const backtestApi = {
    /**
     * 執行回測
     * @param {Object} params - 回測參數
     */
    run(params) {
        return api.post('/backtest/run', params)
    },

    /**
     * 取得回測結果
     * @param {number} id - 回測 ID
     */
    getResult(id) {
        return api.get(`/backtest/results/${id}`)
    },

    /**
     * 取得回測歷史
     * @param {Object} params - 查詢參數
     */
    getHistory(params = {}) {
        return api.get('/backtest/history', { params })
    }
}

// 匯出預設實例
export default api