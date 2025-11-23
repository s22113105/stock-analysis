<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OptionChainService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class OptionChainController extends Controller
{
    protected $chainService;

    public function __construct(OptionChainService $chainService)
    {
        $this->chainService = $chainService;
    }

    /**
     * 取得選擇權鏈 T 字報價表
     * GET /api/options/chain-table
     */
    public function getChainTable(Request $request): JsonResponse
    {
        try {
            $expiryDate = $request->input('expiry_date');
            $data = $this->chainService->getOptionChain($expiryDate);

            if (isset($data['error'])) {
                return response()->json([
                    'success' => false,
                    'message' => $data['error']
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '無法取得報價表: ' . $e->getMessage()
            ], 500);
        }
    }
}
