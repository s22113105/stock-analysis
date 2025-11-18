#!/usr/bin/env python3
"""
GARCH 波動率預測模型
用於預測股價波動率,對選擇權定價很重要
"""

import sys
import json
import numpy as np
import pandas as pd
from datetime import datetime, timedelta
import warnings
warnings.filterwarnings('ignore')

# ARCH/GARCH 模型套件
from arch import arch_model
from scipy import stats

class GARCHPredictor:
    """GARCH 波動率預測模型"""

    def __init__(self, p=1, q=1, dist='normal'):
        """
        初始化 GARCH 模型參數

        Args:
            p: GARCH 項數
            q: ARCH 項數
            dist: 誤差分配 ('normal', 't', 'skewt')
        """
        self.p = p
        self.q = q
        self.dist = dist
        self.model = None
        self.fitted_model = None

    def calculate_returns(self, prices):
        """
        計算對數報酬率

        Args:
            prices: 股價序列

        Returns:
            returns: 報酬率序列
        """
        # 計算對數報酬率
        log_prices = np.log(prices)
        returns = np.diff(log_prices) * 100  # 轉換為百分比

        return returns

    def train(self, prices):
        """
        訓練 GARCH 模型

        Args:
            prices: 歷史股價資料

        Returns:
            model_info: 模型資訊
        """
        # 計算報酬率
        returns = self.calculate_returns(prices)

        # 建立 GARCH 模型
        self.model = arch_model(
            returns,
            vol='Garch',
            p=self.p,
            q=self.q,
            dist=self.dist
        )

        # 訓練模型
        self.fitted_model = self.model.fit(disp='off')

        # 取得模型資訊
        aic = float(self.fitted_model.aic)
        bic = float(self.fitted_model.bic)
        llf = float(self.fitted_model.loglikelihood)

        # 計算無條件波動率
        params = self.fitted_model.params
        omega = params['omega']

        # 處理 GARCH 和 ARCH 參數
        alpha = 0
        for i in range(1, self.q + 1):
            key = f'alpha[{i}]'
            if key in params:
                alpha += params[key]

        beta = 0
        for i in range(1, self.p + 1):
            key = f'beta[{i}]'
            if key in params:
                beta += params[key]

        # 長期波動率
        if (alpha + beta) < 1:
            long_run_variance = omega / (1 - alpha - beta)
            long_run_volatility = np.sqrt(long_run_variance)
        else:
            long_run_volatility = None

        return {
            'aic': aic,
            'bic': bic,
            'log_likelihood': llf,
            'parameters': {
                'omega': float(omega),
                'alpha': float(alpha),
                'beta': float(beta)
            },
            'long_run_volatility': float(long_run_volatility) if long_run_volatility and not np.isnan(long_run_volatility) else None
        }

    def predict(self, horizon=7):
        """
        預測未來波動率

        Args:
            horizon: 預測期間

        Returns:
            predictions: 波動率預測
        """
        if self.fitted_model is None:
            raise ValueError("模型尚未訓練")

        # 預測波動率
        forecast = self.fitted_model.forecast(horizon=horizon)

        # 取得預測值
        variance_forecast = forecast.variance.values[-1, :]
        volatility_forecast = np.sqrt(variance_forecast)

        predictions = []
        for i in range(horizon):
            predictions.append({
                'volatility': float(volatility_forecast[i]),
                'variance': float(variance_forecast[i])
            })

        return predictions

    def calculate_var_cvar(self, prices, confidence_levels=[0.95, 0.99]):
        """
        計算 VaR 和 CVaR(風險值與條件風險值)

        Args:
            prices: 股價序列
            confidence_levels: 信賴水準

        Returns:
            risk_metrics: 風險指標
        """
        returns = self.calculate_returns(prices)

        risk_metrics = {}
        for level in confidence_levels:
            # 計算 VaR
            var = np.percentile(returns, (1 - level) * 100)

            # 計算 CVaR (Expected Shortfall)
            cvar = returns[returns <= var].mean()

            risk_metrics[f'VaR_{int(level*100)}'] = float(var)
            risk_metrics[f'CVaR_{int(level*100)}'] = float(cvar)

        return risk_metrics

    def volatility_clustering_test(self, prices):
        """
        測試波動率聚集效應

        Args:
            prices: 股價序列

        Returns:
            test_results: 測試結果
        """
        returns = self.calculate_returns(prices)

        # 計算報酬率的自相關
        squared_returns = returns ** 2
        correlation = np.corrcoef(squared_returns[:-1], squared_returns[1:])[0, 1]

        return {
            'squared_returns_correlation': float(correlation),
            'has_clustering': correlation > 0.1
        }

def main():
    """主函數"""
    try:
        # 從命令列參數讀取輸入
        if len(sys.argv) < 2:
            print(json.dumps({
                'success': False,
                'error': '請提供輸入資料'
            }))
            sys.exit(1)

        # 從文件讀取 JSON (Laravel 傳遞文件路徑)
        temp_file_path = sys.argv[1]
        with open(temp_file_path, 'r', encoding='utf-8') as f:
            input_data = json.load(f)

        # 解析參數
        prices = np.array(input_data['prices'])
        prediction_days = input_data.get('prediction_days', 7)

        # GARCH 參數
        p = input_data.get('p', 1)
        q = input_data.get('q', 1)
        dist = input_data.get('dist', 'normal')

        # 檢查資料長度
        if len(prices) < 100:
            print(json.dumps({
                'success': False,
                'error': '資料不足,GARCH模型至少需要100天的歷史資料'
            }))
            sys.exit(1)

        # 建立預測器
        predictor = GARCHPredictor(p=p, q=q, dist=dist)

        # 訓練模型
        model_info = predictor.train(prices)

        # 進行波動率預測
        volatility_predictions = predictor.predict(horizon=prediction_days)

        # 計算風險指標
        risk_metrics = predictor.calculate_var_cvar(prices)

        # 波動率聚集測試
        clustering_test = predictor.volatility_clustering_test(prices)

        # 建立預測日期
        base_date = datetime.strptime(input_data['base_date'], '%Y-%m-%d')
        predictions_with_dates = []

        # 計算當前價格(用於預測價格範圍)
        current_price = float(prices[-1])

        for i, vol_pred in enumerate(volatility_predictions):
            target_date = base_date + timedelta(days=i+1)

            # 使用波動率計算可能的價格範圍
            daily_volatility = vol_pred['volatility'] / 100  # 轉換回小數
            price_std = current_price * daily_volatility * np.sqrt(i + 1)

            predictions_with_dates.append({
                'target_date': target_date.strftime('%Y-%m-%d'),
                'predicted_volatility': round(vol_pred['volatility'], 4),
                'price_lower_bound': round(current_price - 1.96 * price_std, 2),
                'price_upper_bound': round(current_price + 1.96 * price_std, 2),
                'confidence_level': 0.95
            })

        # 輸出結果
        result = {
            'success': True,
            'predictions': predictions_with_dates,
            'model_info': {
                'model_type': 'GARCH',
                'order': f'GARCH({p},{q})',
                'aic': round(model_info['aic'], 2),
                'bic': round(model_info['bic'], 2),
                'long_run_volatility': round(model_info['long_run_volatility'], 4) if model_info['long_run_volatility'] else None
            },
            'risk_metrics': risk_metrics,
            'volatility_clustering': clustering_test
        }

        print(json.dumps(result, ensure_ascii=False))

    except Exception as e:
        import traceback
        print(json.dumps({
            'success': False,
            'error': str(e),
            'traceback': traceback.format_exc()
        }))
        sys.exit(1)

if __name__ == '__main__':
    main()
