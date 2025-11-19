#!/usr/bin/env python3
"""
ARIMA 股價預測模型
用於時間序列分析與預測
"""

import sys
import json
import numpy as np
import pandas as pd
from datetime import datetime, timedelta
import warnings
warnings.filterwarnings('ignore')

# 統計模型相關套件
from statsmodels.tsa.arima.model import ARIMA
from statsmodels.tsa.stattools import adfuller
from pmdarima import auto_arima
import scipy.stats as stats
from scipy.stats import skew, kurtosis
import traceback

class ARIMAPredictor:
    """ARIMA 預測模型類別"""

    def __init__(self, p=None, d=None, q=None, auto_select=True):
        """
        初始化 ARIMA 模型參數

        Args:
            p: 自回歸項數
            d: 差分階數
            q: 移動平均項數
            auto_select: 是否自動選擇參數
        """
        self.p = p
        self.d = d
        self.q = q
        self.auto_select = auto_select
        self.model = None
        self.fitted_model = None

    def check_stationarity(self, prices):
        """
        檢查時間序列的平穩性

        Args:
            prices: 股價序列

        Returns:
            is_stationary: 是否平穩
            adf_result: ADF 測試結果
        """
        # Augmented Dickey-Fuller 測試
        adf_result = adfuller(prices)

        # p-value < 0.05 表示序列平穩
        is_stationary = adf_result[1] < 0.05

        return is_stationary, {
            'adf_statistic': float(adf_result[0]),
            'p_value': float(adf_result[1]),
            'critical_values': adf_result[4],
            'is_stationary': is_stationary
        }

    def find_optimal_parameters(self, prices):
        """
        自動尋找最佳 ARIMA 參數

        Args:
            prices: 股價序列

        Returns:
            order: (p, d, q) 參數
        """
        # 使用 auto_arima 自動選擇參數
        auto_model = auto_arima(
            prices,
            start_p=0, start_q=0,
            max_p=5, max_q=5, max_d=2,
            seasonal=False,
            stepwise=True,
            suppress_warnings=True,
            information_criterion='aic',
            error_action='ignore'
        )

        return auto_model.order

    def train(self, prices):
        """
        訓練 ARIMA 模型

        Args:
            prices: 歷史股價資料

        Returns:
            model_info: 模型資訊
        """
        # 檢查平穩性
        is_stationary, stationarity_test = self.check_stationarity(prices)

        # 如果需要自動選擇參數
        if self.auto_select or None in [self.p, self.d, self.q]:
            order = self.find_optimal_parameters(prices)
            self.p, self.d, self.q = order
        else:
            order = (self.p, self.d, self.q)

        # 建立並訓練模型
        self.model = ARIMA(prices, order=order)
        self.fitted_model = self.model.fit()

        # 取得模型資訊
        aic = float(self.fitted_model.aic)
        bic = float(self.fitted_model.bic)

        return {
            'order': order,
            'aic': aic,
            'bic': bic,
            'stationarity': stationarity_test,
            'params': {name: float(value) for name, value in zip(self.fitted_model.param_names, self.fitted_model.params)}
        }

    def predict(self, steps=7):
        """
        預測未來股價

        Args:
            steps: 預測步數

        Returns:
            predictions: 預測結果
        """
        if self.fitted_model is None:
            raise ValueError("模型尚未訓練")

        # 使用 get_forecast 取得完整的預測結果
        forecast_result = self.fitted_model.get_forecast(steps=steps)

        # 取得預測值和標準誤
        forecast_values = forecast_result.predicted_mean
        forecast_se_values = forecast_result.se_mean

        predictions = []
        for i in range(steps):
            predictions.append({
                'predicted': float(forecast_values[i]),
                'std_error': float(forecast_se_values[i])
            })

        return predictions

    def calculate_confidence_intervals(self, predictions, confidence=0.95):
        """
        計算信賴區間

        Args:
            predictions: 預測值與標準誤
            confidence: 信賴水準

        Returns:
            intervals: 信賴區間
        """
        # 計算 z 分數
        z_score = stats.norm.ppf((1 + confidence) / 2)

        intervals = []
        for pred in predictions:
            margin = z_score * pred['std_error']
            intervals.append({
                'predicted': pred['predicted'],
                'lower': pred['predicted'] - margin,
                'upper': pred['predicted'] + margin
            })

        return intervals

    def model_diagnostics(self):
        """
        模型診斷

        Returns:
            diagnostics: 診斷結果
        """
        if self.fitted_model is None:
            return None

        # 殘差分析
        residuals = self.fitted_model.resid

        # Ljung-Box 測試（檢查殘差自相關）
        try:
            ljung_box = self.fitted_model.test_serial_correlation('ljungbox')
            # ljung_box 是一個數組,取最後一個 p-value
            if len(ljung_box) > 0:
                ljung_box_pvalue = float(ljung_box[-1, 1])  # 取最後一行的 p-value
            else:
                ljung_box_pvalue = None
        except:
            ljung_box_pvalue = None

        # 計算殘差統計量
        diagnostics = {
            'residual_mean': float(np.mean(residuals)),
            'residual_std': float(np.std(residuals)),
            'residual_skew': float(skew(residuals)),
            'residual_kurt': float(kurtosis(residuals)),
            'ljung_box_pvalue': ljung_box_pvalue
        }

        return diagnostics

def main():
    """主函數"""
    try:
        # 從檔案讀取輸入資料
        if len(sys.argv) < 2:
            print(json.dumps({
                'success': False,
                'error': '請提供輸入資料檔案路徑'
            }))
            sys.exit(1)

        # 讀取檔案路徑
        input_file = sys.argv[1]

        # 讀取檔案內容
        with open(input_file, 'r', encoding='utf-8-sig') as f:
            input_data = json.load(f)# 解析參數
        prices = np.array(input_data['prices'])
        prediction_days = input_data.get('prediction_days', 7)

        # ARIMA 參數
        p = input_data.get('p', None)
        d = input_data.get('d', None)
        q = input_data.get('q', None)
        auto_select = input_data.get('auto_select', True)

        # 檢查資料長度
        if len(prices) < 30:
            print(json.dumps({
                'success': False,
                'error': '資料不足,至少需要30天的歷史資料'
            }))
            sys.exit(1)# 建立預測器
        predictor = ARIMAPredictor(p=p, d=d, q=q, auto_select=auto_select)# 訓練模型
        model_info = predictor.train(prices)# 進行預測
        predictions = predictor.predict(steps=prediction_days)# 計算信賴區間
        intervals = predictor.calculate_confidence_intervals(predictions)# 模型診斷
        diagnostics = predictor.model_diagnostics()# 建立預測日期
        base_date = datetime.strptime(input_data['base_date'], '%Y-%m-%d')
        predictions_with_dates = []

        for i, interval in enumerate(intervals):
            target_date = base_date + timedelta(days=i+1)
            predictions_with_dates.append({
                'target_date': target_date.strftime('%Y-%m-%d'),
                'predicted_price': round(interval['predicted'], 2),
                'confidence_lower': round(interval['lower'], 2),
                'confidence_upper': round(interval['upper'], 2),
                'confidence_level': 0.95
            })# 輸出結果
        result = {
            'success': True,
            'predictions': predictions_with_dates,
            'model_info': {
                'order': model_info['order'],
                'aic': round(model_info['aic'], 2),
                'bic': round(model_info['bic'], 2),
                'model_type': 'ARIMA'
            },
            'diagnostics': diagnostics
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
