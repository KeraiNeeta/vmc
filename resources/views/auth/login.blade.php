<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · {{ config('app.name', 'Responda') }}</title>
    <link rel="stylesheet" href="{{ asset('css/dispatch.css') }}">
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/operations-theme.css') }}">
</head>
<body class="login-body">
<main class="login-layout">
    <section class="login-story">
        <a class="brand brand-light" href="{{ route('dashboard') }}">
            <span class="brand-mark">VMC</span>
            <span><strong>Vadodara</strong><small>EMERGENCY RESPONSE</small></span>
        </a>
        <div class="story-copy">
            <span class="story-kicker"><span class="online-dot"></span> FIRE &amp; AMBULANCE DISPATCH</span>
            <h1>Every second.<br>Every response.</h1>
            <p>A calm, connected command center for the people who show up when it matters most.</p>
            <div class="security-note"><span>✓</span><div><strong>Secure by design</strong><small>Protected access for authorized teams.</small></div></div>
        </div>
    </section>
    <section class="login-panel">
        <a class="back-link" href="{{ route('dashboard') }}">← Back to dashboard</a>
        <div class="login-card">
            <span class="eyebrow">WELCOME BACK</span>
            <h2>Sign in to your account</h2>
            <p class="muted">Enter your authorized control room or station account to continue.</p>
            @if ($errors->any())
                <div class="alert" role="alert">{{ $errors->first() }}</div>
            @endif
            <form method="POST" action="{{ route('login.store') }}" class="login-form">
                @csrf
                <label for="email">Work email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" placeholder="you@organization.gov" autocomplete="username" required autofocus>
                <label for="password">Password</label>
                <input id="password" name="password" type="password" placeholder="Enter your password" autocomplete="current-password" required>
                <button class="button button-primary login-submit" type="submit">Sign in securely <span>→</span></button>
            </form>
            <div class="login-divider"></div>
            <p class="setup-note">No administrator yet? Add <code>INITIAL_SUPER_ADMIN_EMAIL</code> and <code>INITIAL_SUPER_ADMIN_PASSWORD</code> to the server&apos;s private <code>.env</code>, then run <code>php artisan db:seed --class=InitialSuperAdminSeeder</code>.</p>
            <p class="secure-note">✓ &nbsp;Protected with Laravel session authentication.</p>
        </div>
        <div class="login-copyright">© {{ now()->year }} Responda · Emergency dispatch</div>
    </section>
</main>
</body>
</html>
