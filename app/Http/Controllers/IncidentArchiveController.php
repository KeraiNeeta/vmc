<?php

namespace App\Http\Controllers;

use App\Models\EmergencyJob;
use App\Services\IncidentWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class IncidentArchiveController extends Controller
{
    public function __construct(private readonly IncidentWorkflow $workflow) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $search = trim($validated['search'] ?? '');
        $jobs = $this->workflow->visibleArchivedJobs($request->user())
            ->with('ward:id,name,code')
            ->withCount('assignments');

        if ($search !== '') {
            $jobs->where(function (Builder $query) use ($search): void {
                foreach (['job_id', 'caller_name', 'caller_phone', 'incident_type', 'society_name', 'incident_address'] as $column) {
                    $query->orWhereLike($column, '%'.$search.'%');
                }
            });
        }

        return view('archive.index', [
            'jobs' => $jobs->orderByDesc('deleted_at')->paginate(15)->withQueryString(),
            'search' => $search,
        ]);
    }

    public function archive(Request $request, EmergencyJob $job)
    {
        $this->workflow->archive($request->user(), $job);

        return redirect()->route('app.archive')->with('status', 'Incident archived. Its history is preserved and can be restored.');
    }

    public function restore(Request $request, int $jobId)
    {
        $this->workflow->restoreArchived($request->user(), $jobId);

        return redirect()->route('app.archive')->with('status', 'Incident restored to the active incident directory.');
    }
}
