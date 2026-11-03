<?php

namespace App\Http\Controllers;

use App\Http\Requests\OperationsReportRequest;
use App\Services\OperationsReportService;

class OperationsReportController extends Controller
{
    public function __construct(private readonly OperationsReportService $reports) {}

    public function index(OperationsReportRequest $request)
    {
        $report = $this->reports->report($request->user(), $request->validated());

        return view('reports.operations', ['report' => $report]);
    }
}
