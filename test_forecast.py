import sys
import json
import numpy as np
from statsmodels.tsa.arima.model import ARIMA

# 測試數據
prices = np.array([100, 102, 105, 103, 107, 110, 108, 112, 115, 113, 118, 120, 119, 122, 125, 123, 128, 130, 129, 132, 135, 133, 138, 140, 139, 142, 145, 143, 148, 150])

# 建立模型
model = ARIMA(prices, order=(1, 1, 1))
fitted_model = model.fit()

# 預測
forecast = fitted_model.forecast(steps=1)

print('Forecast type:', type(forecast))
print('Forecast value:', forecast)
print('Forecast shape:', forecast.shape if hasattr(forecast, 'shape') else 'No shape')
print('First element:', forecast[0] if len(forecast) > 0 else 'Empty')
print('First element type:', type(forecast[0]) if len(forecast) > 0 else 'N/A')
