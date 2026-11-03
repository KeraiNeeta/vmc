<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmergencyJobResource;
use App\Services\IncidentWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncidentArchiveController extends Controller
{
    public function __construct(private readonly IncidentWorkflow $workflow) {}

    public function archive(Request $request, int $id): JsonResponse
    {
        $job = $this->workflow->findVisibleJobOrFail($request->user(), $id);

        return response()->json([
            'data' => new EmergencyJobResource($this->workflow->archive($request->user(), $job)),
        ]);
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        return response()->json([
            'data' => new EmergencyJobResource($this->workflow->restoreArchived($request->user(), $id)),
        ]);
    }
}
