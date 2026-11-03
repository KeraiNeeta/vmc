<?php

namespace App\Http\Controllers;

use App\Http\Requests\OperationsReportRequest;
use App\Services\OperationsReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OperationsReportExportController extends Controller
{
    public function __construct(private readonly OperationsReportService $reports) {}

    public function export(OperationsReportRequest $request, string $format): Response|StreamedResponse
    {
        abort_unless(in_array($format, ['csv', 'xlsx', 'pdf'], true), 404);

        $report = $this->reports->report($request->user(), $request->validated());
        $stationName = $this->stationName($report['filters']['station_id'], $report['stations_available']);
        $sections = $this->sections($report);
        $rows = $this->rows($report, $stationName, $sections);
        $filename = sprintf('vmc-operations-%s-to-%s', $report['filters']['from'], $report['filters']['to']);

        return match ($format) {
            'csv' => $this->csv($rows, $filename),
            'xlsx' => $this->xlsx($rows, $filename),
            'pdf' => Pdf::loadView('reports.operations-export', [
                'report' => $report,
                'stationName' => $stationName,
                'sections' => $sections,
            ])->setPaper('a4', 'landscape')->download($filename.'.pdf'),
        };
    }

    private function csv(array $rows, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the report export stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            foreach ($rows as $row) {
                fputcsv($output, array_map($this->safeSpreadsheetValue(...), $row));
            }
            fclose($output);
        }, $filename.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function xlsx(array $rows, string $filename): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Operations report');
        $sheet->fromArray(
            array_map(fn (array $row): array => array_map($this->safeSpreadsheetValue(...), $row), $rows),
            null,
            'A1',
            true,
        );
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->freezePane('A5');

        foreach (range('A', 'F') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(
            fn () => $writer->save('php://output'),
            $filename.'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    private function safeSpreadsheetValue(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^\s*[=+\-@]/', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }

    private function stationName(?int $stationId, array $stations): string
    {
        if ($stationId === null) {
            return 'All accessible stations';
        }

        $station = collect($stations)->firstWhere('id', $stationId);
        if ($station === null) {
            throw new \LogicException('The selected report station is not available in the validated scope.');
        }

        return $station['name'].' · '.$station['code'];
    }

    private function sections(array $report): array
    {
        $summary = $report['summary'];

        return [
            [
                'title' => 'Incident summary',
                'headers' => ['Metric', 'Value'],
                'rows' => [
                    ['Total incidents', $summary['total_jobs']],
                    ['Pending', $summary['pending_jobs']],
                    ['Active', $summary['active_jobs']],
                    ['Completed', $summary['completed_jobs']],
                    ['Duplicate', $summary['duplicate_jobs']],
                    ['Transferred to 112', $summary['transferred_jobs']],
                    ['Critical priority', $summary['critical_jobs']],
                    ['Completion rate (%)', $summary['completion_rate']],
                    ['Average response (minutes)', $summary['average_response_minutes'] ?? 'Not available'],
                    ['Incidents with response time', $summary['jobs_with_response_time']],
                    ['Average resolution (minutes)', $summary['average_resolution_minutes'] ?? 'Not available'],
                    ['Incidents with resolution time', $summary['jobs_with_resolution_time']],
                ],
            ],
            [
                'title' => 'Daily incident volume',
                'headers' => ['Date', 'Incidents'],
                'rows' => array_map(fn (array $day): array => [$day['date'], $day['count']], $report['daily']),
            ],
            ...array_map(
                fn (string $title, string $key): array => [
                    'title' => $title,
                    'headers' => ['Category', 'Incidents', 'Share (%)'],
                    'rows' => array_map(
                        fn (array $item): array => [str_replace('_', ' ', $item['label']), $item['count'], $item['percentage']],
                        $report[$key],
                    ),
                ],
                ['Incident status', 'Priority', 'Incident type'],
                ['statuses', 'priorities', 'incident_types'],
            ),
            [
                'title' => 'Station deployment workload',
                'headers' => ['Fire station', 'Code', 'Deployments', 'Completed deployments'],
                'rows' => array_map(
                    fn (array $station): array => [
                        $station['name'],
                        $station['code'],
                        $station['deployments'],
                        $station['completed_deployments'],
                    ],
                    $report['stations'],
                ),
            ],
        ];
    }

    private function rows(array $report, string $stationName, array $sections): array
    {
        $rows = [
            ['VMC Operations Report'],
            ['Reporting period', $report['filters']['from'], 'to', $report['filters']['to']],
            ['Station', $stationName],
            [],
        ];

        foreach ($sections as $section) {
            $rows[] = [$section['title']];
            $rows[] = $section['headers'];
            array_push($rows, ...$section['rows']);
            $rows[] = [];
        }

        return $rows;
    }
}
