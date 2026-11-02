<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CloseDuplicateIncidentRequest;
use App\Http\Requests\CreateIncidentRequest;
use App\Http\Requests\TransferIncidentTo112Request;
use App\Http\Resources\EmergencyJobResource;
use App\Models\EmergencyJob;
use App\Services\IncidentWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncidentController extends Controller
{
    public function __construct(private readonly IncidentWorkflow $workflow) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = $this->workflow->visibleJobs($request->user())->with('ward:id,name,code');
        $search = trim($validated['search'] ?? '');
        if ($search !== '') {
            $query->where(function (Builder $jobs) use ($search): void {
                foreach (['job_id', 'caller_name', 'caller_phone', 'incident_type', 'incident_address'] as $column) {
                    $jobs->orWhereLike($column, '%'.$search.'%');
                }
            });
        }

        $records = $query->orderByDesc('occurred_at')
            ->paginate((int) ($validated['per_page'] ?? 15))
            ->withQueryString();

        return response()->json([
            'data' => EmergencyJobResource::collection($records->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
            ],
        ]);
    }

    public function callerSearch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['required', 'string', 'min:3', 'max:120'],
        ]);
        $records = $this->workflow->callers($request->user(), trim($validated['search']));

        return response()->json([
            'data' => $records->map(fn (EmergencyJob $job): array => [
                'job_id' => $job->job_id,
                'caller_name' => $job->caller_name,
                'caller_phone' => $job->caller_phone,
                'incident_type' => $job->incident_type,
                'priority' => $job->priority,
                'occurred_at' => $job->occurred_at->toIso8601String(),
                'status' => $job->status,
                'ward' => $job->ward?->name,
            ]),
        ]);
    }

    public function store(CreateIncidentRequest $request): JsonResponse
    {
        $job = $this->workflow->register($request->user(), $request->validated());

        return (new EmergencyJobResource($job->load('callLogs', 'statusHistory', 'externalReferences')))
            ->response()
            ->setStatusCode(201);
    }

    public function closeDuplicate(CloseDuplicateIncidentRequest $request, int $id): JsonResponse
    {
        $job = $this->workflow->findVisibleJobOrFail($request->user(), $id);
        $job = $this->workflow->closeAsDuplicate(
            $request->user(),
            $job,
            (int) $request->validated('duplicate_of'),
            $request->validated('remarks'),
        );

        return (new EmergencyJobResource($job))->response();
    }

    public function transferTo112(TransferIncidentTo112Request $request, int $id): JsonResponse
    {
        $job = $this->workflow->findVisibleJobOrFail($request->user(), $id);
        $job = $this->workflow->transferTo112(
            $request->user(),
            $job,
            $request->validated('reference_number'),
            $request->validated('details'),
        );

        return (new EmergencyJobResource($job))->response();
    }
}
