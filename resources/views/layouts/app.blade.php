<!DOCTYPE html>
<html lang="id" x-data="{ 
    sidebarOpen: false,
    sidebarCollapsed: false
}" @keydown.escape.window="sidebarOpen = false">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'Sistem Supervisi Mutu Perkuliahan' }} - Universitas Teknokrat Indonesia</title>

    <link rel="icon" href="{{ asset('images/logo-teknokrat-sm.png') }}" type="image/png">

    <style>
        [x-cloak] { display: none !important; }
        #primary-navigation { width: 16rem; transform: translateX(-100%); }
        #primary-navigation[data-open="true"] { transform: translateX(0); }
        #primary-navigation[data-collapsed="true"] { width: 5rem; }
        .sidebar-branding { padding-left: 1rem; padding-right: 1rem; gap: .75rem; }
        #primary-navigation[data-collapsed="true"] .sidebar-branding { padding-left: .5rem; padding-right: .5rem; gap: .25rem; }
        .sidebar-brand-logo { display: block; width: 100%; max-width: 220px; height: auto; }
        @media (min-width: 1024px) { #primary-navigation { transform: translateX(0); } }
        @media (min-width: 1280px) { #primary-navigation:not([data-collapsed="true"]) { width: 18rem; } }
    </style>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[#F8F9FA] text-stone-900 antialiased selection:bg-[#7B1113] selection:text-white flex">
    <a href="#main-content" class="skip-link">Lewati ke konten utama</a>

    <!-- ============================================================ -->
    <!-- LEFT SIDEBAR: Pure Rich Institutional Maroon & White Shell   -->
    <!-- ============================================================ -->
    @auth
    <!-- Mobile Backdrop Overlay -->
    <div 
        x-show="sidebarOpen" 
        x-cloak 
        @click="sidebarOpen = false" 
        class="fixed inset-0 z-40 bg-black/60 backdrop-blur-xs lg:hidden transition-opacity"
    ></div>

    <!-- Sidebar Container: Elegant Deep Maroon Palette -->
    <aside 
        id="primary-navigation"
        data-open="false"
        :data-open="sidebarOpen.toString()"
        data-collapsed="false"
        :data-collapsed="sidebarCollapsed.toString()"
        class="fixed lg:sticky top-0 left-0 z-50 h-screen bg-gradient-to-b from-[#3F080C] via-[#4B0B10] to-[#320608] text-white flex flex-col justify-between transition-all duration-300 ease-in-out shrink-0 border-r border-[#5C1015] shadow-xl"
    >
        <!-- Top Branding Section -->
        <div class="sidebar-branding h-20 flex items-center justify-between border-b border-white/10 shrink-0">
            <a href="{{ auth()->user()->hasRole('superadmin') ? route('lpm.dashboard') : route('supervisor.dashboard') }}" class="flex flex-1 items-center min-w-0" aria-label="Lembaga Penjaminan Mutu — halaman utama">
                <img
                    x-show="!sidebarCollapsed"
                    src="{{ asset('images/logo-lpm-uti.png') }}"
                    width="180" height="33"
                    alt="Lembaga Penjaminan Mutu Universitas Teknokrat Indonesia"
                    class="sidebar-brand-logo object-contain"
                >
                <img
                    x-show="sidebarCollapsed"
                    x-cloak
                    src="{{ asset('images/logo-teknokrat-sm.png') }}" 
                    width="28" height="28"
                    alt="Logo Universitas Teknokrat Indonesia" 
                    class="w-7 h-7 object-contain shrink-0"
                >
            </a>

            <!-- Desktop Collapse Toggle Button -->
            <button 
                @click="sidebarCollapsed = !sidebarCollapsed" 
                type="button" 
                class="hidden lg:flex w-7 h-7 shrink-0 rounded-full bg-white/15 hover:bg-white/25 text-white items-center justify-center transition-colors cursor-pointer"
                :title="sidebarCollapsed ? 'Perluas Sidebar' : 'Ciutkan Sidebar'"
                :aria-label="sidebarCollapsed ? 'Perluas sidebar' : 'Ciutkan sidebar'"
                :aria-expanded="(!sidebarCollapsed).toString()"
            >
                <svg :class="{ 'rotate-180': sidebarCollapsed }" class="w-4 h-4 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
            </button>

            <!-- Mobile Close Button -->
            <button 
                @click="sidebarOpen = false" 
                type="button" 
                class="lg:hidden shrink-0 p-1.5 rounded-lg text-rose-200 hover:text-white hover:bg-white/10"
                aria-label="Tutup menu utama"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>

        <!-- Middle Scrollable Navigation Menu -->
        <nav aria-label="Menu utama" class="flex-1 overflow-y-auto sidebar-scroll px-3.5 py-4 space-y-6">
            
            @if(auth()->user()->hasRole('superadmin'))
            <!-- GROUP 1: OVERVIEW -->
            <div>
                <div x-show="!sidebarCollapsed" class="text-[11px] font-extrabold uppercase tracking-wider text-rose-100/80 px-3 mb-2">
                    Overview
                </div>
                <div class="space-y-1">
                    <a 
                        href="{{ route('lpm.dashboard') }}" 
                        class="flex items-center px-3.5 py-2.5 rounded-xl text-sm font-bold transition-all {{ request()->routeIs('lpm.dashboard') ? 'bg-white text-[#7B1113] shadow-md' : 'text-rose-100 hover:text-white hover:bg-white/10' }}"
                        :title="sidebarCollapsed ? 'Dashboard' : ''"
                    >
                        <svg class="w-5 h-5 mr-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                        <span x-show="!sidebarCollapsed" class="truncate">Dashboard LPM</span>
                    </a>
                </div>
            </div>

            <!-- GROUP 2: JADWAL PERKULIAHAN -->
            <div x-data="{ open: true }">
                <button
                    type="button"
                    @click="open = !open" 
                    x-show="!sidebarCollapsed" 
                    :aria-expanded="open.toString()"
                    class="flex w-full items-center justify-between text-[11px] font-extrabold uppercase tracking-wider text-rose-100/80 px-3 mb-2 cursor-pointer hover:text-white transition-colors"
                >
                    <span>Jadwal Perkuliahan</span>
                    <svg :class="{ 'rotate-180': !open }" class="w-3.5 h-3.5 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </button>
                <div x-show="open || sidebarCollapsed" class="space-y-1">
                    <a 
                        href="{{ route('lpm.periods.index') }}" 
                        class="flex items-center px-3.5 py-2 rounded-xl text-xs sm:text-sm font-medium transition-colors {{ request()->routeIs('lpm.periods.*') || request()->routeIs('lpm.audit_periods.*', 'lpm.supervision_periods.*') ? 'bg-white text-[#7B1113] font-bold shadow-xs' : 'text-rose-100/90 hover:text-white hover:bg-white/10' }}"
                        :title="sidebarCollapsed ? 'Periode Supervisi' : ''"
                    >
                        <svg class="w-4.5 h-4.5 mr-3 text-rose-200 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                        <span x-show="!sidebarCollapsed" class="truncate">Periode Supervisi</span>
                    </a>

                    <a 
                        href="{{ route('lpm.import.index') }}" 
                        class="flex items-center px-3.5 py-2 rounded-xl text-xs sm:text-sm font-medium transition-colors {{ request()->routeIs('lpm.import.*') ? 'bg-white text-[#7B1113] font-bold shadow-xs' : 'text-rose-100/90 hover:text-white hover:bg-white/10' }}"
                        :title="sidebarCollapsed ? 'Import Jadwal (PDF)' : ''"
                    >
                        <svg class="w-4.5 h-4.5 mr-3 text-rose-200 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path></svg>
                        <span x-show="!sidebarCollapsed" class="truncate">Import Jadwal (PDF)</span>
                    </a>
                </div>
            </div>

            <!-- GROUP 3: PENUGASAN & SOLVER -->
            <div x-data="{ open: true }">
                <button
                    type="button"
                    @click="open = !open" 
                    x-show="!sidebarCollapsed" 
                    :aria-expanded="open.toString()"
                    class="flex w-full items-center justify-between text-[11px] font-extrabold uppercase tracking-wider text-rose-100/80 px-3 mb-2 cursor-pointer hover:text-white transition-colors"
                >
                    <span>Penugasan & Solver</span>
                    <svg :class="{ 'rotate-180': !open }" class="w-3.5 h-3.5 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </button>
                <div x-show="open || sidebarCollapsed" class="space-y-1">
                    <a 
                        href="{{ route('lpm.assignments.index') }}" 
                        class="flex items-center px-3.5 py-2 rounded-xl text-xs sm:text-sm font-medium transition-colors {{ request()->routeIs('lpm.assignments.*') ? 'bg-white text-[#7B1113] font-bold shadow-xs' : 'text-rose-100/90 hover:text-white hover:bg-white/10' }}"
                        :title="sidebarCollapsed ? 'Workspace Penugasan' : ''"
                    >
                        <svg class="w-4.5 h-4.5 mr-3 text-rose-200 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path></svg>
                        <span x-show="!sidebarCollapsed" class="truncate">Workspace Penugasan</span>
                    </a>
                </div>
            </div>

            <!-- GROUP 4: BANK PERTANYAAN -->
            <div x-data="{ open: true }">
                <button
                    type="button"
                    @click="open = !open" 
                    x-show="!sidebarCollapsed" 
                    :aria-expanded="open.toString()"
                    class="flex w-full items-center justify-between text-[11px] font-extrabold uppercase tracking-wider text-rose-100/80 px-3 mb-2 cursor-pointer hover:text-white transition-colors"
                >
                    <span>Bank Pertanyaan</span>
                    <svg :class="{ 'rotate-180': !open }" class="w-3.5 h-3.5 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </button>
                <div x-show="open || sidebarCollapsed" class="space-y-1">
                    <a 
                        href="{{ route('lpm.instruments.mode', 'offline') }}" 
                        class="flex items-center px-3.5 py-2 rounded-xl text-xs sm:text-sm font-medium transition-colors {{ ((request()->routeIs('lpm.instruments.mode') && request()->route('mode') === 'offline') || (request()->routeIs('lpm.instruments.index') && request()->query('mode', 'offline') === 'offline') || (request()->routeIs('lpm.instruments.show') && isset($template) && $template->delivery_mode === 'OFFLINE')) ? 'bg-white text-[#7B1113] font-bold shadow-xs' : 'text-rose-100/90 hover:text-white hover:bg-white/10' }}"
                        :title="sidebarCollapsed ? 'Bank Pertanyaan Offline' : ''"
                    >
                        <svg class="w-4.5 h-4.5 mr-3 text-rose-200 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
                        <span x-show="!sidebarCollapsed" class="truncate">Pertanyaan Offline</span>
                    </a>
                    <a 
                        href="{{ route('lpm.instruments.mode', 'online') }}" 
                        class="flex items-center px-3.5 py-2 rounded-xl text-xs sm:text-sm font-medium transition-colors {{ ((request()->routeIs('lpm.instruments.mode') && request()->route('mode') === 'online') || (request()->routeIs('lpm.instruments.index') && request()->query('mode') === 'online') || (request()->routeIs('lpm.instruments.show') && isset($template) && $template->delivery_mode === 'ONLINE')) ? 'bg-white text-[#7B1113] font-bold shadow-xs' : 'text-rose-100/90 hover:text-white hover:bg-white/10' }}"
                        :title="sidebarCollapsed ? 'Bank Pertanyaan Online' : ''"
                    >
                        <svg class="w-4.5 h-4.5 mr-3 text-rose-200 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                        <span x-show="!sidebarCollapsed" class="truncate">Pertanyaan Online</span>
                    </a>
                </div>
            </div>

            <!-- GROUP 5: DATA DOSEN -->
            <div x-data="{ open: true }">
                <button
                    type="button"
                    @click="open = !open" 
                    x-show="!sidebarCollapsed" 
                    :aria-expanded="open.toString()"
                    class="flex w-full items-center justify-between text-[11px] font-extrabold uppercase tracking-wider text-rose-100/80 px-3 mb-2 cursor-pointer hover:text-white transition-colors"
                >
                    <span>Data Dosen</span>
                    <svg :class="{ 'rotate-180': !open }" class="w-3.5 h-3.5 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </button>
                <div x-show="open || sidebarCollapsed" class="space-y-1">
                    <a 
                        href="{{ route('lpm.supervisors.index') }}" 
                        class="flex items-center px-3.5 py-2 rounded-xl text-xs sm:text-sm font-medium transition-colors {{ request()->routeIs('lpm.supervisors.*') ? 'bg-white text-[#7B1113] font-bold shadow-xs' : 'text-rose-100/90 hover:text-white hover:bg-white/10' }}"
                        :title="sidebarCollapsed ? 'Daftar Supervisor' : ''"
                    >
                        <svg class="w-4.5 h-4.5 mr-3 text-rose-200 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                        <span x-show="!sidebarCollapsed" class="truncate">Daftar Supervisor</span>
                    </a>
                    <a 
                        href="{{ route('lpm.lecturers.index') }}" 
                        class="flex items-center px-3.5 py-2 rounded-xl text-xs sm:text-sm font-medium transition-colors {{ request()->routeIs('lpm.lecturers.*') ? 'bg-white text-[#7B1113] font-bold shadow-xs' : 'text-rose-100/90 hover:text-white hover:bg-white/10' }}"
                        :title="sidebarCollapsed ? 'Direktori Dosen' : ''"
                    >
                        <svg class="w-4.5 h-4.5 mr-3 text-rose-200 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                        <span x-show="!sidebarCollapsed" class="truncate">Direktori Dosen</span>
                    </a>
                </div>
            </div>

            <!-- GROUP 6: ANALISIS & LAPORAN -->
            <div x-data="{ open: true }">
                <button
                    type="button"
                    @click="open = !open" 
                    x-show="!sidebarCollapsed" 
                    :aria-expanded="open.toString()"
                    class="flex w-full items-center justify-between text-[11px] font-extrabold uppercase tracking-wider text-rose-100/80 px-3 mb-2 cursor-pointer hover:text-white transition-colors"
                >
                    <span>Hasil & Analisis</span>
                    <svg :class="{ 'rotate-180': !open }" class="w-3.5 h-3.5 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </button>
                <div x-show="open || sidebarCollapsed" class="space-y-1">
                    <a 
                        href="{{ route('lpm.analytics.index') }}" 
                        class="flex items-center px-3.5 py-2 rounded-xl text-xs sm:text-sm font-medium transition-colors {{ request()->routeIs('lpm.analytics.*') ? 'bg-white text-[#7B1113] font-bold shadow-xs' : 'text-rose-100/90 hover:text-white hover:bg-white/10' }}"
                        :title="sidebarCollapsed ? 'Analitik & Komparasi T1/T2' : ''"
                    >
                        <svg class="w-4.5 h-4.5 mr-3 text-rose-200 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path></svg>
                        <span x-show="!sidebarCollapsed" class="truncate">Analisis Kohort T1/T2</span>
                    </a>

                    <a 
                        href="{{ route('lpm.reports.supervision') }}" 
                        class="flex items-center px-3.5 py-2 rounded-xl text-xs sm:text-sm font-medium transition-colors {{ request()->routeIs('lpm.reports.*') ? 'bg-white text-[#7B1113] font-bold shadow-xs' : 'text-rose-100/90 hover:text-white hover:bg-white/10' }}"
                        :title="sidebarCollapsed ? 'Laporan Hasil Supervisi' : ''"
                    >
                        <svg class="w-4.5 h-4.5 mr-3 text-rose-200 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                        <span x-show="!sidebarCollapsed" class="truncate">Laporan Hasil Supervisi</span>
                    </a>

                    <a
                        href="{{ route('lpm.supervisions.completed') }}"
                        class="flex items-center px-3.5 py-2 rounded-xl text-xs sm:text-sm font-medium transition-colors {{ request()->routeIs('lpm.supervisions.completed') ? 'bg-white text-[#7B1113] font-bold shadow-xs' : 'text-rose-100/90 hover:text-white hover:bg-white/10' }}"
                        :title="sidebarCollapsed ? 'Dosen yang Sudah Disupervisi' : ''"
                    >
                        <svg class="w-4.5 h-4.5 mr-3 text-rose-200 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622C17.176 19.29 21 14.591 21 9c0-1.042-.133-2.053-.382-3.016z"></path></svg>
                        <span x-show="!sidebarCollapsed" class="truncate">Dosen yang Sudah Disupervisi</span>
                    </a>
                </div>
            </div>

            @else
            <!-- SUPERVISOR MENU -->
            <div>
                <div x-show="!sidebarCollapsed" class="text-[11px] font-extrabold uppercase tracking-wider text-rose-100/80 px-3 mb-2">
                    Supervisi Mutu Perkuliahan
                </div>
                <div class="space-y-1">
                    <a 
                        href="{{ route('supervisor.dashboard') }}" 
                        class="flex items-center px-3.5 py-2.5 rounded-xl text-sm font-bold transition-all {{ request()->routeIs('supervisor.dashboard') ? 'bg-white text-[#7B1113] shadow-md' : 'text-rose-100 hover:text-white hover:bg-white/10' }}"
                    >
                        <svg class="w-5 h-5 mr-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                        <span x-show="!sidebarCollapsed">Dashboard</span>
                    </a>

                    <a 
                        href="{{ route('supervisor.schedule') }}" 
                        class="flex items-center px-3.5 py-2.5 rounded-xl text-sm font-bold transition-all {{ request()->routeIs('supervisor.schedule*') ? 'bg-white text-[#7B1113] shadow-md' : 'text-rose-100 hover:text-white hover:bg-white/10' }}"
                    >
                        <svg class="w-5 h-5 mr-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                        <span x-show="!sidebarCollapsed">Jadwal Saya</span>
                    </a>

                    <a href="{{ route('supervisor.assignment_letters.index') }}"
                       :title="sidebarCollapsed ? 'Surat Tugas Saya' : ''"
                       class="flex items-center px-3.5 py-2.5 rounded-xl text-sm font-bold transition-all {{ request()->routeIs('supervisor.assignment_letters.*') ? 'bg-white text-[#7B1113] shadow-md' : 'text-rose-100 hover:text-white hover:bg-white/10' }}">
                        <svg class="w-5 h-5 mr-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6M9 3H6a2 2 0 00-2 2v14a2 2 0 002 2h12a2 2 0 002-2V9l-6-6H9zm5 0v6h6"></path></svg>
                        <span x-show="!sidebarCollapsed">Surat Tugas Saya</span>
                    </a>

                    <a 
                        href="{{ route('supervisor.assignments.index') }}" 
                        class="flex items-center px-3.5 py-2.5 rounded-xl text-sm font-bold transition-all {{ request()->routeIs('supervisor.assignments.*') || request()->routeIs('supervisor.supervisions.*') ? 'bg-white text-[#7B1113] shadow-md' : 'text-rose-100 hover:text-white hover:bg-white/10' }}"
                    >
                        <svg class="w-5 h-5 mr-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                        <span x-show="!sidebarCollapsed">Tugas Supervisi</span>
                    </a>
                </div>
            </div>
            @endif

        </nav>

        <!-- Bottom User Card in Sidebar: Pure Maroon Aesthetic -->
        <div class="p-3 border-t border-white/10 bg-[#2C0507] shrink-0">
            <div class="flex items-center justify-between p-2 rounded-xl hover:bg-white/10 transition-colors">
                <div class="flex items-center space-x-3 min-w-0">
                    <div class="w-9 h-9 rounded-full bg-white text-[#7B1113] font-black text-xs flex items-center justify-center shrink-0 shadow-sm">
                        {{ strtoupper(substr(auth()->user()->name, 0, 2)) }}
                    </div>
                    <div x-show="!sidebarCollapsed" class="flex flex-col min-w-0 transition-opacity">
                        <span class="text-xs font-bold text-white truncate">{{ auth()->user()->name }}</span>
                        <span class="text-[10px] text-rose-200/80 truncate">
                            {{ auth()->user()->hasRole('superadmin') ? 'Superadmin' : 'Supervisor' }}
                        </span>
                    </div>
                </div>

                <!-- Logout Icon Button -->
                <form x-show="!sidebarCollapsed" method="POST" action="{{ route('logout') }}" class="shrink-0">
                    @csrf
                    <button 
                        type="submit" 
                        class="p-1.5 rounded-lg text-rose-200 hover:text-white hover:bg-white/15 transition-colors cursor-pointer" 
                        aria-label="Keluar dari sistem"
                        title="Keluar (Logout)"
                    >
                        <svg class="w-4.5 h-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                    </button>
                </form>
            </div>
        </div>
    </aside>
    @endauth

    <!-- ============================================================ -->
    <!-- RIGHT MAIN WORKSPACE: Header Bar + Page Content (Pure White) -->
    <!-- ============================================================ -->
    <div class="flex-1 flex flex-col min-w-0 bg-[#F8F9FA] min-h-screen">
        
        <!-- Top Navigation Bar: Radiant Pure White -->
        <header class="sticky top-0 z-30 bg-white border-b border-stone-200/80 px-4 sm:px-6 lg:px-8 h-18 flex items-center justify-between shadow-2xs">
            
            <div class="flex min-w-0 flex-1 items-center gap-3 sm:gap-4">
                @auth
                <button 
                    @click="sidebarOpen = !sidebarOpen" 
                    type="button" 
                    class="lg:hidden p-2 rounded-xl text-stone-600 hover:bg-stone-100"
                    :aria-expanded="sidebarOpen.toString()"
                    aria-controls="primary-navigation"
                    aria-label="Buka menu utama"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                </button>
                @endauth

                <div class="min-w-0">
                    <p class="text-[11px] font-bold uppercase tracking-[0.14em] text-[#7B1113]">Sistem Supervisi Mutu Perkuliahan</p>
                    <p class="truncate text-sm font-semibold text-stone-700">{{ $title ?? 'Ruang kerja supervisi' }}</p>
                </div>
            </div>

            <div class="flex items-center gap-2 sm:gap-3">
                 
                @auth
                <div class="relative" x-data="{ menuOpen: false }" @keydown.escape.window="menuOpen = false">
                    <button 
                        @click="menuOpen = !menuOpen" 
                        @click.outside="menuOpen = false" 
                        type="button" 
                        class="w-9 h-9 rounded-full bg-[#7B1113] hover:bg-[#5E0C0F] text-white font-bold text-xs flex items-center justify-center shadow-sm cursor-pointer transition-colors"
                        :aria-expanded="menuOpen.toString()"
                        aria-controls="user-menu"
                        aria-label="Buka menu akun {{ auth()->user()->name }}"
                    >
                        {{ strtoupper(substr(auth()->user()->name, 0, 2)) }}
                    </button>

                    <!-- User Menu Dropdown -->
                    <div 
                        id="user-menu"
                        x-show="menuOpen" 
                        x-cloak 
                        x-transition 
                        class="absolute right-0 mt-2 w-56 bg-white rounded-2xl shadow-xl border border-stone-200/80 py-1.5 z-50 text-sm"
                    >
                        <div class="px-4 py-2.5 border-b border-stone-100">
                            <p class="text-xs text-stone-500">Masuk sebagai:</p>
                            <p class="font-semibold text-stone-800 truncate">{{ auth()->user()->name }}</p>
                            <span class="inline-block mt-0.5 text-[10px] font-semibold text-[#7B1113] bg-rose-50 px-2 py-0.5 rounded">
                                {{ auth()->user()->hasRole('superadmin') ? 'Administrator LPM' : 'Supervisor' }}
                            </span>
                        </div>
                        
                        <a href="{{ route('password.change') }}" class="flex items-center px-4 py-2 text-stone-700 hover:bg-stone-50 transition-colors">
                            <svg class="w-4 h-4 mr-2.5 text-stone-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path></svg>
                            Ganti Kata Sandi
                        </a>

                        <div class="border-t border-stone-100 my-1"></div>

                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="w-full flex items-center px-4 py-2 text-[#7B1113] hover:bg-rose-50 transition-colors text-left font-semibold cursor-pointer">
                                <svg class="w-4 h-4 mr-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                                Keluar (Logout)
                            </button>
                        </form>
                    </div>
                </div>
                @else
                <a href="{{ route('login') }}" class="inline-flex items-center px-4 py-2 rounded-xl bg-[#7B1113] text-white hover:bg-[#5E0C0F] font-semibold text-xs sm:text-sm shadow-md transition-colors">
                    Masuk Sistem
                </a>
                @endauth

            </div>
        </header>

        <!-- Main Content Area: Pure Clean Light Surface -->
        <main id="main-content" class="flex-1 p-4 sm:p-6 lg:p-8 {{ request()->routeIs('lpm.reports.*') ? 'max-w-[1600px]' : 'max-w-7xl' }} w-full mx-auto">
            
            <!-- Global Flash Messages -->
            @if (session('success'))
                <div class="mb-6 p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 flex items-start space-x-3 shadow-2xs" role="alert">
                    <svg class="w-5 h-5 text-emerald-600 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <div class="text-sm font-medium">{{ session('success') }}</div>
                </div>
            @endif

            @if (session('error'))
                <div class="mb-6 p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 flex items-start space-x-3 shadow-2xs" role="alert">
                    <svg class="w-5 h-5 text-rose-600 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <div class="text-sm font-medium">{{ session('error') }}</div>
                </div>
            @endif

            @if (session('warning'))
                <div class="mb-6 p-4 rounded-2xl bg-amber-50 border border-amber-200 text-amber-900 flex items-start space-x-3 shadow-2xs" role="alert">
                    <svg class="w-5 h-5 text-amber-700 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4c-.77-1.33-2.69-1.33-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"></path></svg>
                    <div class="text-sm font-medium">{{ session('warning') }}</div>
                </div>
            @endif

            @if (session('info'))
                <div class="mb-6 p-4 rounded-2xl bg-blue-50 border border-blue-200 text-blue-900 flex items-start space-x-3 shadow-2xs" role="status">
                    <svg class="w-5 h-5 text-blue-700 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <div class="text-sm font-medium">{{ session('info') }}</div>
                </div>
            @endif

            @yield('content')
        </main>

    </div>

    <script>
        window.confirmationContent = function(prefix, value, suffix, detail = '', detailClass = 'text-slate-400') {
            const content = document.createElement('div');
            content.className = 'text-xs text-slate-600 text-left space-y-2';
            const message = document.createElement('p');
            const name = document.createElement('strong');
            name.textContent = value;
            message.append(document.createTextNode(prefix), name, document.createTextNode(suffix));
            content.append(message);
            if (detail) {
                const notice = document.createElement('p');
                notice.className = detailClass;
                notice.textContent = detail;
                content.append(notice);
            }
            return content;
        };

        window.confirmModal = function({ title, text, html, icon = 'question', confirmText = 'Lanjutkan', cancelText = 'Batal', confirmColor = '#7B1113', onConfirm }) {
            if (window.Swal) {
                Swal.fire({
                    titleText: title,
                    text: text,
                    html: html,
                    icon: icon,
                    showCancelButton: true,
                    confirmButtonText: confirmText,
                    cancelButtonText: cancelText,
                    confirmButtonColor: confirmColor,
                    cancelButtonColor: '#64748B',
                    reverseButtons: true,
                    customClass: {
                        popup: 'rounded-2xl shadow-2xl border border-slate-200 font-sans p-6',
                        title: 'text-base font-bold text-slate-900',
                        confirmButton: 'px-4 py-2.5 rounded-xl font-semibold text-xs shadow-xs',
                        cancelButton: 'px-4 py-2.5 rounded-xl font-semibold text-xs shadow-xs'
                    }
                }).then((result) => {
                    if (result.isConfirmed && typeof onConfirm === 'function') {
                        onConfirm();
                    }
                });
            } else if (typeof onConfirm === 'function') {
                onConfirm();
            }
        };
    </script>

    @stack('scripts')
</body>
</html>
