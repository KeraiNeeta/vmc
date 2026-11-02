@extends('layouts.app')

@section('title', $module['title'])

@section('content')
<section class="page-stack">
    <div class="page-heading"><div><span class="eyebrow">{{ strtoupper($module['category']) }}</span><h1>{{ $module['title'] }}</h1><p class="muted">A live, permission-scoped view will appear when this module is connected.</p></div><span class="phase-badge">◷ &nbsp; Planned · {{ $module['phase'] }}</span></div>
    <section class="module-card">
        <span class="module-icon">◇</span><span class="eyebrow">ROADMAP · {{ strtoupper($module['phase']) }}</span>
        <h2>{{ $module['title'] }} is planned.</h2>
        <p>This operational module has not been implemented yet. No sample incidents, vehicles, or response metrics are shown.</p>
        <div class="module-notice">When its database and API are available, this page will display authorized live records.</div>
        <a class="button button-primary" href="{{ route('dashboard') }}">Back to dashboard</a>
    </section>
</section>
@endsection
