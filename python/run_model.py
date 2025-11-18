#!/usr/bin/env python3
"""
模型執行包裝腳本
避免 Windows asyncio 初始化問題
"""

import sys
import os
import json
import subprocess

def run_model(model_type, input_file):
    """
    使用子程序執行模型，避免直接載入可能有問題的模組

    Args:
        model_type: 模型類型 (lstm, arima, garch)
        input_file: 輸入資料檔案路徑
    """
    try:
        # 取得腳本目錄
        script_dir = os.path.dirname(os.path.abspath(__file__))
        model_script = os.path.join(script_dir, 'models', f'{model_type}_model.py')

        if not os.path.exists(model_script):
            print(json.dumps({
                'success': False,
                'error': f'模型腳本不存在: {model_script}'
            }))
            return

        # 使用子程序執行，避免環境問題
        python_exe = sys.executable
        result = subprocess.run(
            [python_exe, model_script, input_file],
            capture_output=True,
            text=True,
            encoding='utf-8',
            errors='replace'
        )

        if result.returncode != 0:
            print(json.dumps({
                'success': False,
                'error': result.stderr
            }))
        else:
            # 直接輸出結果
            print(result.stdout)

    except Exception as e:
        print(json.dumps({
            'success': False,
            'error': str(e)
        }))

if __name__ == '__main__':
    if len(sys.argv) != 3:
        print(json.dumps({
            'success': False,
            'error': '使用方式: python run_model.py <model_type> <input_file>'
        }))
        sys.exit(1)

    model_type = sys.argv[1]
    input_file = sys.argv[2]
    run_model(model_type, input_file)
