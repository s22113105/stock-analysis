import sys
import numpy as np
from statsmodels.tsa.arima.model import ARIMA

# 測試數據
prices = np.array([100, 102, 105, 103, 107, 110, 108, 112, 115, 113, 118, 120, 119, 122, 125, 123, 128, 130, 129, 132, 135, 133, 138, 140, 139, 142, 145, 143, 148, 150])

# 建立模型
model = ARIMA(prices, order=(1, 1, 1))
fitted_model = model.fit()

# 使用 get_forecast
forecast_result = fitted_model.get_forecast(steps=1)

print('=== forecast_result ===')
print('Type:', type(forecast_result))

print('\n=== predicted_mean ===')
print('Type:', type(forecast_result.predicted_mean))
print('Value:', forecast_result.predicted_mean)
print('Shape:', forecast_result.predicted_mean.shape if hasattr(forecast_result.predicted_mean, 'shape') else 'No shape')

print('\n=== se_mean ===')
print('Type:', type(forecast_result.se_mean))
print('Value:', forecast_result.se_mean)
print('Shape:', forecast_result.se_mean.shape if hasattr(forecast_result.se_mean, 'shape') else 'No shape')

print('\n=== 測試取值 ===')
try:
    val = forecast_result.predicted_mean[0]
    print('predicted_mean[0]:', val, 'Type:', type(val))
except Exception as e:
    print('predicted_mean[0] Error:', e)

try:
    # 如果是 pandas Series
    if hasattr(forecast_result.predicted_mean, 'iloc'):
        val = forecast_result.predicted_mean.iloc[0]
        print('predicted_mean.iloc[0]:', val, 'Type:', type(val))
except Exception as e:
    print('predicted_mean.iloc[0] Error:', e)
