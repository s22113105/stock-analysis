/**
 * 波動率分析 Store
 * 
 * 功能：
 * - 管理波動率相關資料狀態
 * - 呼叫波動率 API
 * - 快取波動率計算結果
 */

import { defineStore } from 'pinia'
import axios from 'axios'

export const useVolatilityStore = defineStore('volatility', {
    state: () => ({
        // 當前選擇的股票
        currentStock: null,
        currentStockId: null,
        
        // 波動率資料
        historicalVolatility: null,     // 歷史波動率 (HV)
        impliedVolatility: null,        // 隱含波動率 (IV)
        realizedVolatility: null,       // 實現波動率
        marketIV: null,                 // 市場隱含波動率 (從選擇權)
        
        // 波動率錐資料
        volatilityCone: [],
        
        // 波動率曲面資料
        volatilitySurface: null,
        
        // 波動率偏斜資料
        volatilitySkew: null,
        
        // GARCH 模型預測
        garchForecast: null,
        
        // 歷史波動率走勢
        volatilityTrend: [],
        
        // 多週期波動率統計
        volatilityStats: [],
        
        // 波動率事件
        volatilityEvents: [],
        
        // 載入狀態
        loading: {
            historical: false,
            implied: false,
            marketIV: false,
            cone: false,
            surface: false,
            skew: false,
            garch: false,
            trend: false,
            batch: false
        },
        
        // 錯誤訊息
        errors: {
            historical: null,
            implied: null,
            marketIV: null,
            cone: null,
            surface: null,
            skew: null,
            garch: null,
            trend: null
        },
        
        // 最後更新時間
        lastUpdated: null
    }),

    getters: {
        /**
         * 取得 IV/HV 比率
         */
        ivHvRatio: (state) => {
            if (!state.historicalVolatility || !state.impliedVolatility) {
                return null
            }
            const hv = state.historicalVolatility.historical_volatility || 0
            const iv = state.impliedVolatility.implied_volatility || 0
            return hv > 0 ? (iv / hv) : null
        },

        /**
         * IV/HV 比率分析
         */
        ivHvAnalysis: (state) => {
            const ratio = state.ivHvRatio
            if (ratio === null) return { status: 'unknown', text: '資料不足', color: 'grey' }
            
            if (ratio < 0.9) {
                return { status: 'low', text: 'IV 低估', color: 'success' }
            } else if (ratio > 1.1) {
                return { status: 'high', text: 'IV 高估', color: 'error' }
            }
            return { status: 'normal', text: 'IV 合理', color: 'warning' }
        },

        /**
         * 波動率等級 (百分位數)
         */
        volatilityRank: (state) => {
            if (!state.volatilityCone.length) return null
            
            // 取得 30 天的波動率錐資料
            const cone30 = state.volatilityCone.find(c => c.period === 30)
            if (!cone30) return null
            
            const { current, min, max } = cone30
            if (max === min) return 50
            
            return Math.round(((current - min) / (max - min)) * 100)
        },

        /**
         * 是否有任何載入中
         */
        isLoading: (state) => {
            return Object.values(state.loading).some(v => v)
        },

        /**
         * 是否有任何關鍵錯誤 (排除非關鍵的 marketIV)
         */
        hasError: (state) => {
            // marketIV 是非關鍵功能，不需要顯示錯誤
            const criticalErrors = ['historical', 'implied', 'cone', 'surface', 'skew', 'garch', 'trend']
            return criticalErrors.some(key => state.errors[key] !== null)
        },

        /**
         * 當前 HV (百分比格式)
         */
        currentHV: (state) => {
            if (!state.historicalVolatility) return null
            const hv = state.historicalVolatility.historical_volatility
            return hv ? (hv * 100).toFixed(2) : null
        },

        /**
         * 當前 IV (百分比格式)
         */
        currentIV: (state) => {
            if (!state.impliedVolatility) return null
            const iv = state.impliedVolatility.implied_volatility
            return iv ? (iv * 100).toFixed(2) : null
        },

        /**
         * 交易建議
         */
        tradingRecommendation: (state) => {
            const ratio = state.ivHvRatio
            if (ratio === null) {
                return {
                    title: '資料不足',
                    description: '請先選擇股票並載入波動率資料',
                    type: 'info'
                }
            }
            
            if (ratio > 1.1) {
                return {
                    title: 'IV 高於 HV - 考慮賣出策略',
                    description: `當前隱含波動率高於歷史波動率 ${((ratio - 1) * 100).toFixed(1)}%，表示選擇權價格可能被高估。可以考慮賣出選擇權策略，如賣出 Covered Call 或 Cash-Secured Put。`,
                    type: 'warning'
                }
            } else if (ratio < 0.9) {
                return {
                    title: 'IV 低於 HV - 考慮買入策略',
                    description: `當前隱含波動率低於歷史波動率 ${((1 - ratio) * 100).toFixed(1)}%，表示選擇權價格可能被低估。可以考慮買入選擇權策略，如買入跨式或勒式組合。`,
                    type: 'success'
                }
            }
            
            return {
                title: 'IV 與 HV 接近 - 觀望為宜',
                description: '當前隱含波動率與歷史波動率接近，選擇權定價合理。建議觀察後續走勢再做決定。',
                type: 'info'
            }
        }
    },

    actions: {
        /**
         * 設定當前股票
         */
        setCurrentStock(stock) {
            this.currentStock = stock
            this.currentStockId = stock?.id || null
        },

        /**
         * 清除所有資料
         */
        clearData() {
            this.historicalVolatility = null
            this.impliedVolatility = null
            this.realizedVolatility = null
            this.marketIV = null
            this.volatilityCone = []
            this.volatilitySurface = null
            this.volatilitySkew = null
            this.garchForecast = null
            this.volatilityTrend = []
            this.volatilityStats = []
            this.errors = {
                historical: null,
                implied: null,
                marketIV: null,
                cone: null,
                surface: null,
                skew: null,
                garch: null,
                trend: null
            }
        },

        /**
         * 取得歷史波動率
         * GET /api/volatility/historical/{stockId}
         */
        async fetchHistoricalVolatility(stockId, options = {}) {
            const { period = 30, endDate = null } = options
            
            this.loading.historical = true
            this.errors.historical = null
            
            try {
                const params = { period }
                if (endDate) params.end_date = endDate
                
                const response = await axios.get(`volatility/historical/${stockId}`, { params })
                
                if (response.data.success) {
                    this.historicalVolatility = response.data.data
                    return response.data.data
                } else {
                    throw new Error(response.data.message || '取得歷史波動率失敗')
                }
            } catch (error) {
                this.errors.historical = error.response?.data?.message || error.message
                console.error('取得歷史波動率失敗:', error)
                throw error
            } finally {
                this.loading.historical = false
            }
        },

        /**
         * 取得隱含波動率
         * GET /api/volatility/implied/{optionId}
         */
        async fetchImpliedVolatility(optionId) {
            this.loading.implied = true
            this.errors.implied = null
            
            try {
                const response = await axios.get(`volatility/implied/${optionId}`)
                
                if (response.data.success) {
                    this.impliedVolatility = response.data.data
                    return response.data.data
                } else {
                    throw new Error(response.data.message || '取得隱含波動率失敗')
                }
            } catch (error) {
                this.errors.implied = error.response?.data?.message || error.message
                console.error('取得隱含波動率失敗:', error)
                throw error
            } finally {
                this.loading.implied = false
            }
        },

        /**
         * 取得波動率錐
         * GET /api/volatility/cone/{stockId}
         */
        async fetchVolatilityCone(stockId, lookbackDays = 252) {
            this.loading.cone = true
            this.errors.cone = null
            
            try {
                const response = await axios.get(`volatility/cone/${stockId}`, {
                    params: { lookback_days: lookbackDays }
                })
                
                if (response.data.success) {
                    this.volatilityCone = response.data.data.cone || []
                    return response.data.data
                } else {
                    throw new Error(response.data.message || '取得波動率錐失敗')
                }
            } catch (error) {
                this.errors.cone = error.response?.data?.message || error.message
                console.error('取得波動率錐失敗:', error)
                throw error
            } finally {
                this.loading.cone = false
            }
        },

        /**
         * 取得波動率曲面
         * GET /api/volatility/surface/{stockId}
         */
        async fetchVolatilitySurface(stockId, date = null) {
            this.loading.surface = true
            this.errors.surface = null
            
            try {
                const params = date ? { date } : {}
                const response = await axios.get(`volatility/surface/${stockId}`, { params })
                
                if (response.data.success) {
                    this.volatilitySurface = response.data.data
                    return response.data.data
                } else {
                    throw new Error(response.data.message || '取得波動率曲面失敗')
                }
            } catch (error) {
                this.errors.surface = error.response?.data?.message || error.message
                console.error('取得波動率曲面失敗:', error)
                throw error
            } finally {
                this.loading.surface = false
            }
        },

        /**
         * 取得波動率偏斜
         * GET /api/volatility/skew/{stockId}
         */
        async fetchVolatilitySkew(stockId, expiry = null) {
            this.loading.skew = true
            this.errors.skew = null
            
            try {
                const params = expiry ? { expiry } : {}
                const response = await axios.get(`volatility/skew/${stockId}`, { params })
                
                if (response.data.success) {
                    this.volatilitySkew = response.data.data
                    return response.data.data
                } else {
                    throw new Error(response.data.message || '取得波動率偏斜失敗')
                }
            } catch (error) {
                this.errors.skew = error.response?.data?.message || error.message
                console.error('取得波動率偏斜失敗:', error)
                throw error
            } finally {
                this.loading.skew = false
            }
        },

        /**
         * 取得 GARCH 模型預測
         * GET /api/volatility/garch/{stockId}
         */
        async fetchGarchForecast(stockId, options = {}) {
            const { forecastDays = 5, modelType = 'GARCH' } = options
            
            this.loading.garch = true
            this.errors.garch = null
            
            try {
                const response = await axios.get(`volatility/garch/${stockId}`, {
                    params: {
                        forecast_days: forecastDays,
                        model_type: modelType
                    }
                })
                
                if (response.data.success) {
                    this.garchForecast = response.data.data
                    return response.data.data
                } else {
                    throw new Error(response.data.message || '取得 GARCH 預測失敗')
                }
            } catch (error) {
                this.errors.garch = error.response?.data?.message || error.message
                console.error('取得 GARCH 預測失敗:', error)
                throw error
            } finally {
                this.loading.garch = false
            }
        },

        /**
         * 取得市場隱含波動率 (從選擇權價格)
         * GET /api/volatility/market-iv/{stockId}
         */
        async fetchMarketIV(stockId) {
            this.loading.marketIV = true
            this.errors.marketIV = null
            
            try {
                const response = await axios.get(`volatility/market-iv/${stockId}`)
                
                if (response.data.success) {
                    this.marketIV = response.data.data
                    return response.data.data
                } else {
                    throw new Error(response.data.message || '取得市場 IV 失敗')
                }
            } catch (error) {
                // 市場 IV 取得失敗不影響其他功能，只記錄錯誤
                this.errors.marketIV = error.response?.data?.message || error.message
                console.warn('取得市場 IV 失敗 (非關鍵錯誤):', error.message)
                this.marketIV = null
                // 不拋出錯誤，讓其他功能繼續
            } finally {
                this.loading.marketIV = false
            }
        },

        /**
         * 計算多週期波動率統計
         */
        async fetchMultiPeriodStats(stockId) {
            const periods = [10, 20, 30, 60, 90, 252]
            this.loading.trend = true
            
            try {
                const statsPromises = periods.map(period => 
                    axios.get(`volatility/historical/${stockId}`, { params: { period } })
                        .then(res => ({
                            period: `${period}天`,
                            periodDays: period,
                            ...res.data.data
                        }))
                        .catch(() => null)
                )
                
                const results = await Promise.all(statsPromises)
                this.volatilityStats = results.filter(r => r !== null)
                
                return this.volatilityStats
            } catch (error) {
                console.error('取得多週期波動率統計失敗:', error)
                throw error
            } finally {
                this.loading.trend = false
            }
        },

        /**
         * 批次載入所有波動率資料
         */
        async loadAllVolatilityData(stockId, options = {}) {
            const { period = 30, includeGarch = true, includeSurface = false, includeMarketIV = true } = options
            
            this.loading.batch = true
            this.currentStockId = stockId
            
            try {
                // 並行請求
                const promises = [
                    this.fetchHistoricalVolatility(stockId, { period }),
                    this.fetchVolatilityCone(stockId),
                    this.fetchMultiPeriodStats(stockId)
                ]
                
                if (includeGarch) {
                    promises.push(this.fetchGarchForecast(stockId))
                }
                
                // 嘗試取得市場 IV (非關鍵，失敗不影響其他功能)
                if (includeMarketIV) {
                    promises.push(this.fetchMarketIV(stockId))
                }
                
                if (includeSurface) {
                    promises.push(this.fetchVolatilitySurface(stockId))
                }
                
                await Promise.allSettled(promises)
                
                this.lastUpdated = new Date().toISOString()
                
                return {
                    historicalVolatility: this.historicalVolatility,
                    volatilityCone: this.volatilityCone,
                    volatilityStats: this.volatilityStats,
                    garchForecast: this.garchForecast,
                    marketIV: this.marketIV
                }
            } catch (error) {
                console.error('批次載入波動率資料失敗:', error)
                throw error
            } finally {
                this.loading.batch = false
            }
        },

        /**
         * 手動觸發波動率計算
         * POST /api/volatility/calculate
         */
        async triggerCalculation(stockId, date = null) {
            try {
                const response = await axios.post('volatility/calculate', {
                    stock_id: stockId,
                    date: date
                })
                
                if (response.data.success) {
                    // 重新載入資料
                    await this.loadAllVolatilityData(stockId)
                    return response.data.data
                } else {
                    throw new Error(response.data.message || '計算失敗')
                }
            } catch (error) {
                console.error('觸發波動率計算失敗:', error)
                throw error
            }
        }
    }
})