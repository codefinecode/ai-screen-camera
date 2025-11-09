<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Contracts\AwsFramesReaderInterface;
use App\Services\AggregationService;

class DashboardFramesController extends Controller
{
    public function __construct(
        private readonly AwsFramesReaderInterface $reader,
        private readonly AggregationService $aggregationService
    ) {}

    public function index(Request $request)
    {
        try {
            $start = $request->input('filter.start');
            $end = $request->input('filter.end');
            $screenIds = $request->input('filter.screenIds');
            $returnRaw = filter_var($request->input('returnRawFrames', 'false'), FILTER_VALIDATE_BOOL);
            $bucketType = $request->input('bucketType');

            if (!$start || !$end || !$screenIds) {
                return response()->json(['error' => 'INVALID', 'message' => 'Required filters: filter[start], filter[end], filter[screenIds]'], 400);
            }

            $filters = $request->query();
            
            try {
                $frames = $this->reader->fetchFrames($filters);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to fetch frames from AWS', [
                    'filters' => $filters,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                return response()->json([
                    'error' => 'FETCH_FAILED',
                    'message' => 'Failed to fetch frames from storage'
                ], 500);
            }

            if ($returnRaw) {
                usort($frames, fn($a, $b) => ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0));
                return response()->json(['frames' => $frames]);
            }

            try {
                $agg = $this->aggregationService->aggregate($frames, $start, $end, $bucketType);
                return response()->json($agg);
            } catch (\InvalidArgumentException $e) {
                return response()->json([
                    'error' => 'INVALID',
                    'message' => $e->getMessage()
                ], 400);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Aggregation failed', [
                    'frameCount' => count($frames),
                    'start' => $start,
                    'end' => $end,
                    'bucketType' => $bucketType,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                return response()->json([
                    'error' => 'AGGREGATION_FAILED',
                    'message' => 'Failed to aggregate frame data'
                ], 500);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Unexpected error in dashboard frames endpoint', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'error' => 'INTERNAL_ERROR',
                'message' => 'An unexpected error occurred'
            ], 500);
        }
    }
}
