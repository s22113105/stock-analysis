#!/usr/bin/env python3
"""簡單測試腳本 - 不載入複雜套件"""

import sys
import json
import os

def main():
    try:
        # 讀取輸入
        if len(sys.argv) < 2:
            print(json.dumps({
                'success': False,
                'error': '需要輸入檔案'
            }))
            sys.exit(1)

        input_file = sys.argv[1]

        # 讀取檔案
        with open(input_file, 'r', encoding='utf-8') as f:
            data = json.load(f)

        # 簡單計算（不載入任何外部套件）
        prices = data.get('prices', [])
        base_date = data.get('base_date', '2025-01-01')

        if prices:
            # 計算簡單移動平均
            avg_price = sum(prices) / len(prices)
            last_price = prices[-1]

            # 簡單趨勢預測
            trend = (prices[-1] - prices[-10]) / 10 if len(prices) > 10 else 0
            predicted_price = last_price + trend

            print(json.dumps({
                'success': True,
                'predictions': [{
                    'target_date': base_date,
                    'predicted_price': round(predicted_price, 2),
                    'confidence_lower': round(predicted_price * 0.95, 2),
                    'confidence_upper': round(predicted_price * 1.05, 2),
                    'confidence_level': 0.95
                }],
                'metrics': {
                    'model_type': 'SIMPLE_TEST',
                    'avg_price': round(avg_price, 2),
                    'last_price': round(last_price, 2),
                    'trend': round(trend, 2),
                    'data_points': len(prices)
                }
            }, ensure_ascii=False))
        else:
            print(json.dumps({
                'success': False,
                'error': '沒有價格資料'
            }))

    except Exception as e:
        print(json.dumps({
            'success': False,
            'error': f'錯誤: {str(e)}'
        }))
        sys.exit(1)

if __name__ == '__main__':
    main()
