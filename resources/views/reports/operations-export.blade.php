<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>VMC Operations Report</title>
    <style>
        body { color: #172033; font-family: DejaVu Sans, sans-serif; font-size: 9px; }
        h1 { color: #123d72; font-size: 20px; margin: 0 0 8px; }
        h2 { background: #eaf1f8; color: #123d72; font-size: 12px; margin: 18px 0 6px; padding: 6px; }
        .meta { color: #526174; margin-bottom: 12px; }
        table { border-collapse: collapse; margin-bottom: 10px; width: 100%; }
        th, td { border: 1px solid #d5deea; padding: 5px 7px; text-align: left; }
        th { background: #f3f6fa; }
        .note { color: #526174; font-size: 8px; margin-top: 16px; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
    </style>
</head>
<body>
    <h1>VMC Operations Report</h1>
    <div class="meta">
        Period: {{ $report['filters']['from'] }} to {{ $report['filters']['to'] }}<br>
        Station: {{ $stationName }}
    </div>
    @foreach ($sections as $section)
        <h2>{{ $section['title'] }}</h2>
        @if ($section['rows'] !== [])
            <table>
                <thead><tr>@foreach ($section['headers'] as $header)<th>{{ $header }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach ($section['rows'] as $row)
                        <tr>@foreach ($row as $value)<td>{{ is_numeric($value) ? $value : (string) $value }}</td>@endforeach</tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p>No records for this section.</p>
        @endif
    @endforeach
    <p class="note">Response and resolution averages are descriptive operational metrics based on recorded transitions. They are not SLA guarantees.</p>
</body>
</html>
