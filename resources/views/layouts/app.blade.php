<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#071a35">
    <title>@yield('title', 'Dashboard') · {{ config('app.name', 'Responda') }}</title>
    <link rel="stylesheet" href="{{ asset('css/dispatch.css') }}">
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/operations-theme.css') }}">
    <link rel="stylesheet" href="{{ asset('css/dashboard-reference.css') }}">
    <link rel="stylesheet" href="{{ asset('css/master-data.css') }}">
    <link rel="stylesheet" href="{{ asset('css/tracking.css') }}">
    <link rel="stylesheet" href="{{ asset('css/reports.css') }}">
    <link rel="stylesheet" href="{{ asset('css/dashboard-polish.css') }}?v={{ filemtime(public_path('css/dashboard-polish.css')) }}">
</head>
<body class="operations-dashboard-page {{ request()->routeIs('dashboard') ? 'operations-dashboard-layout' : '' }}">
@php
    $currentUser = auth()->user();
    $navigation = [
        ['section' => null, 'links' => [
            ['label' => 'Dashboard', 'route' => 'dashboard', 'permission' => 'dashboard.view', 'icon' => '▦'],
        ]],
        ['section' => 'Emergency desk', 'dropdown' => ['label' => 'Jobs', 'icon' => '▤'], 'links' => [
            ['label' => 'All jobs', 'route' => 'app.jobs', 'permission' => 'jobs.view', 'icon' => '▤'],
            ['label' => 'New registration', 'route' => 'app.jobs.new', 'permission' => 'jobs.create', 'icon' => '+'],
            ['label' => 'Pending jobs', 'route' => 'app.jobs.pending', 'permission' => 'jobs.view', 'icon' => '◷'],
            ['label' => 'Duplicate calls', 'route' => 'app.jobs.duplicates', 'permission' => 'jobs.view', 'icon' => '↔'],
            ['label' => 'Transferred to 112', 'route' => 'app.jobs.transferred', 'permission' => 'jobs.view', 'icon' => '⇢'],
            ['label' => 'Active jobs', 'route' => 'app.jobs.active', 'permission' => 'jobs.view', 'icon' => '◉'],
            ['label' => 'Incident archive', 'route' => 'app.archive', 'permission' => 'jobs.archive', 'icon' => '▣'],
        ]],
        ['section' => 'Field operations', 'links' => [
            ['label' => 'Live GPS tracking', 'route' => 'app.tracking', 'permission' => 'gps.view', 'icon' => '⌖'],
            ['label' => 'Fire stations', 'route' => 'app.stations', 'permission' => 'stations.view', 'icon' => '⌂'],
            ['label' => 'Ward management', 'route' => 'app.wards', 'permission' => 'wards.view', 'icon' => '◎'],
            ['label' => 'Vehicles', 'route' => 'app.vehicles', 'permission' => 'vehicles.view', 'icon' => '▰'],
            ['label' => 'Teams & members', 'route' => 'app.teams', 'permission' => 'teams.view', 'icon' => '♧'],
        ]],
        ['section' => 'Administration', 'links' => [
            ['label' => 'Reports & analytics', 'route' => 'app.reports', 'permission' => 'reports.view', 'icon' => '▥'],
            ['label' => 'User management', 'route' => 'users.index', 'permission' => 'users.view', 'icon' => '♙'],
            ['label' => 'Roles & permissions', 'route' => 'app.roles', 'permission' => 'roles.view', 'icon' => '◇'],
            ['label' => 'System settings', 'route' => 'app.settings', 'permission' => 'settings.manage', 'icon' => '⚙'],
        ]],
    ];
@endphp
<div class="shell">
    <aside class="sidebar" id="app-sidebar">
        <a class="brand" href="{{ route('dashboard') }}">
            <span class="brand-mark">VMC</span>
            <span><strong>Vadodara</strong><small>EMERGENCY RESPONSE</small></span>
        </a>
        <div class="workspace"><span class="workspace-icon">⌖</span><span><strong>Control room</strong><small>Operations workspace</small></span></div>
        <nav class="navigation" aria-label="Main navigation">
            @foreach ($navigation as $group)
                @php
                    $visibleLinks = collect($group['links'])->filter(fn ($link) => !$currentUser || $currentUser->hasPermission($link['permission']));
                @endphp
                @if ($visibleLinks->isNotEmpty())
                    <div class="nav-group">
                        @if ($group['section']) <div class="nav-caption">{{ $group['section'] }}</div> @endif
                        @if (!empty($group['dropdown']))
                            @php
                                $hasActiveLink = request()->routeIs('app.jobs.*', 'app.archive*')
                                    || $visibleLinks->contains(fn ($link) => Route::has($link['route']) && request()->routeIs($link['route']));
                            @endphp
                            <details class="nav-dropdown" @if ($hasActiveLink) open @endif>
                                <summary class="nav-link nav-dropdown-trigger {{ $hasActiveLink ? 'active' : '' }}">
                                    <span class="nav-icon">{{ $group['dropdown']['icon'] }}</span>
                                    <span>{{ $group['dropdown']['label'] }}</span>
                                    <span class="nav-dropdown-chevron" aria-hidden="true">⌄</span>
                                </summary>
                                <div class="nav-dropdown-links">
                                    @foreach ($visibleLinks as $link)
                                        @if (Route::has($link['route']))
                                            @php
                                                $isActiveLink = request()->routeIs($link['route'])
                                                    || ($link['route'] === 'app.jobs' && request()->routeIs('app.jobs.show'))
                                                    || ($link['route'] === 'app.archive' && request()->routeIs('app.archive.*'));
                                            @endphp
                                            <a class="nav-link {{ $isActiveLink ? 'active' : '' }}" href="{{ route($link['route']) }}">
                                                <span class="nav-icon">{{ $link['icon'] }}</span>{{ $link['label'] }}
                                            </a>
                                        @endif
                                    @endforeach
                                </div>
                            </details>
                        @else
                            @foreach ($visibleLinks as $link)
                                @if (Route::has($link['route']))
                                    <a class="nav-link {{ request()->routeIs($link['route']) || ($link['route'] === 'dashboard' && request()->routeIs('home')) ? 'active' : '' }}" href="{{ route($link['route']) }}">
                                        <span class="nav-icon">{{ $link['icon'] }}</span>{{ $link['label'] }}
                                    </a>
                                @endif
                            @endforeach
                        @endif
                    </div>
                @endif
            @endforeach
        </nav>
        <div class="sidebar-footer"><span class="online-dot"></span> RESPONSE NETWORK <span class="version">VMC</span></div>
    </aside>
    <div class="main-column">
        <header class="topbar">
            <div class="app-heading">
                <button class="sidebar-toggle" type="button" data-sidebar-toggle aria-controls="app-sidebar" aria-expanded="true" aria-label="Hide sidebar" title="Hide sidebar">
                    <span aria-hidden="true">☰</span>
                </button>
                <span class="app-alert-icon" aria-hidden="true">🚨</span>
                <span><strong>Emergency Response System</strong><small>Ambulance &amp; Fire Brigade · Vadodara</small></span>
            </div>
            <div class="topbar-account">
                <span class="ops-live {{ app()->environment('local') ? 'is-demo' : '' }}"><span></span>{{ app()->environment('local') ? 'Demo data' : 'Live' }}</span>
                <span class="topbar-date">{{ now()->format('D, d M Y H:i') }}</span>
                @if ($currentUser)
                    <span class="avatar">{{ collect(explode(' ', $currentUser->name))->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->implode('') }}</span>
                    <span class="account-name">{{ $currentUser->name }}</span>
                    <form method="POST" action="{{ route('logout') }}">@csrf<button class="button button-light button-small" type="submit">Sign out</button></form>
                @else
                    <span class="preview-tag"><span></span> Preview mode</span>
                    <a class="button button-primary button-small" href="{{ route('login') }}">Sign in</a>
                @endif
            </div>
        </header>
        <main class="content">@yield('content')</main>
        <footer class="footer"><strong>Responda Operations</strong><span>Secure emergency response management</span></footer>
    </div>
</div>
<script>
(() => {
    const toggle = document.querySelector('[data-sidebar-toggle]');
    const sidebar = document.getElementById('app-sidebar');
    if (!toggle || !sidebar) return;

    toggle.addEventListener('click', () => {
        const isExpanded = toggle.getAttribute('aria-expanded') === 'true';
        document.body.classList.toggle('sidebar-hidden', isExpanded);
        toggle.setAttribute('aria-expanded', String(!isExpanded));
        toggle.setAttribute('aria-label', isExpanded ? 'Show sidebar' : 'Hide sidebar');
        toggle.setAttribute('title', isExpanded ? 'Show sidebar' : 'Hide sidebar');
    });
})();
</script>
</body>
</html>
