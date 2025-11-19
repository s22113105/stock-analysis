#!/usr/bin/env python3
"""
Python 模型檔案讀取測試腳本
用於驗證模型是否正確讀取檔案輸入
"""

import sys
import json
import os
import tempfile

def test_model_file_reading(model_name, model_path):
    """
    測試模型是否正確讀取檔案

    Args:
        model_name: 模型名稱 (LSTM, ARIMA, GARCH)
        model_path: 模型檔案路徑
    """
    print(f"\n{'='*60}")
    print(f"測試 {model_name} 模型")
    print('='*60)

    # 建立測試資料
    test_data = {
        "prices": [100 + i for i in range(120)],  # 120 天的測試資料
        "dates": [f"2025-{(i//30)+1:02d}-{(i%30)+1:02d}" for i in range(120)],
        "base_date": "2025-11-18",
        "prediction_days": 1,
        "stock_symbol": "TEST",
        "epochs": 5,  # LSTM 用少量 epochs 快速測試
        "units": 32,  # LSTM
        "lookback": 30,  # LSTM
        "p": 1,  # ARIMA/GARCH
        "q": 1,  # ARIMA/GARCH
        "d": 1,  # ARIMA
        "auto_select": False,  # ARIMA
        "dist": "normal"  # GARCH
    }

    # 建立臨時檔案
    with tempfile.NamedTemporaryFile(mode='w', suffix='.json', delete=False, encoding='utf-8') as f:
        json.dump(test_data, f, ensure_ascii=False)
        temp_file = f.name

    print(f"✓ 測試資料已寫入: {temp_file}")
    print(f"  資料筆數: {len(test_data['prices'])} 天")

    try:
        # 執行 Python 模型
        import subprocess

        result = subprocess.run(
            [sys.executable, model_path, temp_file],
            capture_output=True,
            text=True,
            timeout=60,
            encoding='utf-8'
        )

        print(f"\n執行結果:")
        print(f"  返回碼: {result.returncode}")

        if result.returncode == 0:
            # 解析輸出
            try:
                output = json.loads(result.stdout)

                if output.get('success'):
                    print(f"  ✅ 狀態: 成功")

                    # 顯示預測結果
                    predictions = output.get('predictions', [])
                    print(f"  預測筆數: {len(predictions)}")

                    if predictions:
                        first_pred = predictions[0]
                        print(f"  第一筆預測:")
                        print(f"    日期: {first_pred.get('target_date')}")

                        if 'predicted_price' in first_pred:
                            print(f"    預測價格: {first_pred.get('predicted_price')}")
                            print(f"    信賴區間: [{first_pred.get('confidence_lower')}, {first_pred.get('confidence_upper')}]")
                        elif 'predicted_volatility' in first_pred:
                            print(f"    預測波動率: {first_pred.get('predicted_volatility')}")
                            print(f"    價格範圍: [{first_pred.get('price_lower_bound')}, {first_pred.get('price_upper_bound')}]")

                    # 顯示模型資訊
                    if 'metrics' in output:
                        print(f"  模型指標: {output['metrics']}")
                    elif 'model_info' in output:
                        print(f"  模型資訊: {output['model_info']}")

                else:
                    print(f"  ❌ 狀態: 失敗")
                    print(f"  錯誤訊息: {output.get('error', '未知錯誤')}")

            except json.JSONDecodeError as e:
                print(f"  ❌ JSON 解析失敗: {e}")
                print(f"  原始輸出:\n{result.stdout}")
        else:
            print(f"  ❌ 執行失敗")
            if result.stderr:
                print(f"  錯誤輸出:\n{result.stderr}")
            if result.stdout:
                print(f"  標準輸出:\n{result.stdout}")

    except subprocess.TimeoutExpired:
        print(f"  ⏱️ 執行超時(>60秒)")
    except Exception as e:
        print(f"  ❌ 執行錯誤: {e}")
    finally:
        # 清理臨時檔案
        if os.path.exists(temp_file):
            os.remove(temp_file)
            print(f"\n✓ 臨時檔案已清理")

def main():
    """主函數"""
    print("\n" + "="*60)
    print("Python 模型檔案讀取測試")
    print("="*60)

    # 定義要測試的模型
    models = [
        ("LSTM", "python/models/lstm_model.py"),
        ("ARIMA", "python/models/arima_model.py"),
        ("GARCH", "python/models/garch_model.py"),
    ]

    results = []

    for model_name, model_path in models:
        if os.path.exists(model_path):
            test_model_file_reading(model_name, model_path)
            results.append((model_name, True))
        else:
            print(f"\n⚠️  找不到檔案: {model_path}")
            results.append((model_name, False))

    # 顯示總結
    print(f"\n{'='*60}")
    print("測試總結")
    print('='*60)

    for model_name, found in results:
        status = "✅" if found else "❌"
        print(f"{status} {model_name:10s} - {'已測試' if found else '檔案不存在'}")

    print("\n提示:")
    print("  - 如果所有模型都成功,表示檔案讀取邏輯正確")
    print("  - 如果有模型失敗,請檢查錯誤訊息")
    print("  - LSTM 模型可能需要較長時間(約10-20秒)")
    print("  - 確保已安裝所有必要的 Python 套件")
    print('='*60 + "\n")

if __name__ == '__main__':
    main()
