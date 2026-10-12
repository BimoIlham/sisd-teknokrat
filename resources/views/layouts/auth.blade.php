<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'Masuk Sistem' }} - Lembaga Penjaminan Mutu Universitas Teknokrat Indonesia</title>

    <link rel="icon" href="{{ asset('images/logo-teknokrat-sm.png') }}" type="image/png">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[#F8F9FA] text-stone-900 antialiased selection:bg-[#7B1113] selection:text-white">
    <a href="#main-content" class="skip-link">Lewati ke konten utama</a>

    <main id="main-content">
        @yield('content')
    </main>

    @stack('scripts')
</body>
</html>
