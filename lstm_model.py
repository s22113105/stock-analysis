#!/usr/bin/env python3
"""
LSTM 股價預測模型
用於預測台股價格走勢
"""

import sys
import json
import numpy as np
import pandas as pd
from datetime import datetime, timedelta
import warnings
warnings.filterwarnings('ignore')

# 機器學習相關套件
from sklearn.preprocessing import MinMaxScaler
from sklearn.model_selection import train_test_split
import tensorflow as tf
from tensorflow.keras.models import Sequential
from tensorflow.keras.layers import LSTM, Dense, Dropout
from tensorflow.keras.optimizers import Adam
from tensorflow.keras.callbacks import EarlyStopping, ReduceLROnPlateau

class LSTMPredictor:
    """LSTM 預測模型類別"""

    def __init__(self, lookback=60, units=128, dropout=0.2, epochs=100):
        """
        初始化模型參數

        Args:
            lookback: 回顧期間（使用過去多少天的資料）
            units: LSTM 單元數
            dropout: Dropout 比率
            epochs: 訓練輪數
        """
        self.lookback = lookback
        self.units = units
        self.dropout = dropout
        self.epochs = epochs
        self.model = None
        self.scaler = MinMaxScaler(feature_range=(0, 1))

    def prepare_data(self, prices):
        """
        準備訓練資料

        Args:
            prices: 股價陣列

        Returns:
            X, y: 特徵與標籤
        """
        # 資料標準化
        prices_scaled = self.scaler.fit_transform(prices.reshape(-1, 1))

        X, y = [], []
        for i in range(self.lookback, len(prices_scaled)):
            X.append(prices_scaled[i-self.lookback:i, 0])
            y.append(prices_scaled[i, 0])

        return np.array(X), np.array(y)

    def build_model(self, input_shape):
        """
        建立 LSTM 模型架構

        Args:
            input_shape: 輸入形狀
        """
        self.model = Sequential([
            # 第一層 LSTM
            LSTM(units=self.units,
                 return_sequences=True,
                 input_shape=(input_shape[1], 1)),
            Dropout(self.dropout),

            # 第二層 LSTM
            LSTM(units=self.units // 2,
                 return_sequences=True),
            Dropout(self.dropout),

            # 第三層 LSTM
            LSTM(units=self.units // 4,
                 return_sequences=False),
            Dropout(self.dropout),

            # 輸出層
            Dense(units=25),
            Dense(units=1)
        ])

        # 編譯模型
        self.model.compile(
            optimizer=Adam(learning_rate=0.001),
            loss='mean_squared_error',
            metrics=['mae']
        )

    def train(self, prices):
        """
        訓練模型

        Args:
            prices: 歷史股價資料

        Returns:
            history: 訓練歷史
        """
        # 準備資料
        X, y = self.prepare_data(prices)

        # Reshape 為 LSTM 格式 [samples, time steps, features]
        X = X.reshape((X.shape[0], X.shape[1], 1))

        # 分割訓練與驗證資料
        X_train, X_val, y_train, y_val = train_test_split(
            X, y, test_size=0.2, shuffle=False
        )

        # 建立模型
        self.build_model(X_train.shape)

        # 設定回調函數
        callbacks = [
            EarlyStopping(
                monitor='val_loss',
                patience=10,
                restore_best_weights=True
            ),
            ReduceLROnPlateau(
                monitor='val_loss',
                factor=0.5,
                patience=5,
                min_lr=1e-6
            )
        ]

        # 訓練模型
        history = self.model.fit(
            X_train, y_train,
            epochs=self.epochs,
            batch_size=32,
            validation_data=(X_val, y_val),
            callbacks=callbacks,
            verbose=0
        )

        return history

    def predict(self, prices, days=7):
        """
        預測未來股價

        Args:
            prices: 歷史股價
            days: 預測天數

        Returns:
            predictions: 預測結果
        """
        if self.model is None:
            raise ValueError("模型尚未訓練")

        # 使用最近的資料作為輸入
        last_sequence = prices[-self.lookback:]
        last_sequence_scaled = self.scaler.transform(last_sequence.reshape(-1, 1))

        predictions = []
        current_sequence = last_sequence_scaled.copy()

        for _ in range(days):
            # Reshape 為模型輸入格式
            current_input = current_sequence.reshape((1, self.lookback, 1))

            # 預測下一個值
            next_pred = self.model.predict(current_input, verbose=0)

            # 反標準化
            next_price = self.scaler.inverse_transform(next_pred)[0, 0]
            predictions.append(float(next_price))

            # 更新序列（滑動視窗）
            current_sequence = np.append(current_sequence[1:], next_pred)
            current_sequence = current_sequence.reshape(-1, 1)

        return predictions

    def calculate_confidence_intervals(self, predictions, confidence=0.95):
        """
        計算信賴區間

        Args:
            predictions: 預測值
            confidence: 信賴水準

        Returns:
            intervals: 上下界
        """
        # 基於歷史波動率計算信賴區間
        std_dev = np.std(predictions) * 0.1  # 簡化計算

        intervals = []
        for pred in predictions:
            margin = std_dev * 1.96  # 95% 信賴區間
            intervals.append({
                'predicted': float(pred),
                'lower': float(pred - margin),
                'upper': float(pred + margin)
            })

        return intervals

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

        input_data = json.loads(sys.argv[1])

        # 解析參數
        prices = np.array(input_data['prices'])
        prediction_days = input_data.get('prediction_days', 7)
        epochs = input_data.get('epochs', 100)
        units = input_data.get('units', 128)

        # 檢查資料長度
        if len(prices) < 100:
            print(json.dumps({
                'success': False,
                'error': '資料不足，至少需要100天的歷史資料'
            }))
            sys.exit(1)

        # 建立並訓練模型
        predictor = LSTMPredictor(
            lookback=60,
            units=units,
            epochs=epochs
        )

        # 訓練模型
        history = predictor.train(prices)

        # 進行預測
        predictions = predictor.predict(prices, days=prediction_days)

        # 計算信賴區間
        intervals = predictor.calculate_confidence_intervals(predictions)

        # 建立預測日期
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
            })

        # 計算模型指標
        final_loss = float(history.history['loss'][-1])
        final_mae = float(history.history['mae'][-1])

        # 輸出結果
        result = {
            'success': True,
            'predictions': predictions_with_dates,
            'metrics': {
                'final_loss': round(final_loss, 6),
                'final_mae': round(final_mae, 4),
                'epochs_trained': len(history.history['loss']),
                'model_type': 'LSTM'
            }
        }

        print(json.dumps(result, ensure_ascii=False))

    except Exception as e:
        print(json.dumps({
            'success': False,
            'error': str(e)
        }))
        sys.exit(1)

if __name__ == '__main__':
    main()
