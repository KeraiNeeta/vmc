<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\OperationsReportRequest;
use App\Services\OperationsReportService;
use Illuminate\Http\JsonResponse;

class OperationsReportController extends Controller
{
    public function __construct(private readonly OperationsReportService $reports) {}

    public function __invoke(OperationsReportRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->reports->report($request->user(), $request->validated()),
        ]);
    }
}
