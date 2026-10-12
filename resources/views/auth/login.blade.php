@extends('layouts.auth', ['title' => 'Masuk ke Sistem'])

@section('content')
<div class="min-h-screen w-full flex flex-col lg:flex-row bg-[#F8F9FA]">

    <!-- ========================================== -->
    <!-- LEFT PANEL: Institutional Branding Banner -->
    <!-- ========================================== -->
    <div class="relative w-full lg:w-[58%] xl:w-[60%] flex flex-col justify-between p-7 sm:p-10 lg:p-12 xl:p-16 text-white min-h-[500px] lg:min-h-screen overflow-hidden">
        
        <!-- Campus Architecture Background with Warm Institutional Maroon Overlay -->
        <div class="absolute inset-0 z-0 select-none pointer-events-none">
            <img 
                src="{{ asset('images/gedung-utama-uti.png') }}" 
                alt="Gedung Universitas Teknokrat Indonesia" 
                width="1920"
                height="1080"
                fetchpriority="high"
                decoding="async"
                class="w-full h-full object-cover object-center transform scale-105"
            >
            <!-- Atmospheric warm maroon overlay matching reference image -->
            <div class="absolute inset-0 bg-[#4A0D12]/35 mix-blend-multiply"></div>
            <div class="absolute inset-0 bg-gradient-to-t from-[#280406]/75 via-[#540e14]/35 to-[#38060a]/30"></div>
        </div>

        <!-- Top Left: Lembaga Penjaminan Mutu Logo -->
        <div class="relative z-10 flex items-center">
            <img 
                src="{{ asset('images/logo-lpm-uti.png') }}" 
                alt="Logo Lembaga Penjaminan Mutu Universitas Teknokrat Indonesia" 
                width="1571"
                height="285"
                class="w-[250px] sm:w-[300px] xl:w-[340px] max-w-full h-auto object-contain drop-shadow-md"
            >
        </div>

        <!-- Middle Content: Welcome Headline, Description, & 2x2 Feature Highlights -->
        <div class="relative z-10 my-auto py-8 sm:py-12 max-w-2xl">
            <!-- Headline -->
            <h1 class="text-2xl sm:text-3xl md:text-4xl xl:text-[42px] font-extrabold tracking-tight text-white leading-[1.18] drop-shadow-md">
                Selamat Datang di<br>
                Sistem Supervisi Mutu Perkuliahan
            </h1>

            <!-- Subtitle Description -->
            <p class="mt-4 sm:mt-5 text-sm sm:text-base text-rose-100/90 leading-relaxed font-normal max-w-xl">
                Kelola jadwal perkuliahan, penugasan supervisor, penilaian berbasis instrumen, dan analisis hasil supervisi dalam satu sistem.
            </p>

            <!-- Empat fitur utama dalam susunan dua kolom -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-y-5 gap-x-10 mt-8 sm:mt-10 max-w-xl">
                <!-- Penugasan supervisor -->
                <div class="flex items-start space-x-3">
                    <span class="w-1.5 h-1.5 rounded-full bg-white mt-2 shrink-0"></span>
                    <div>
                        <h2 class="text-sm font-bold text-white tracking-tight leading-snug">Penugasan Supervisor</h2>
                        <p class="text-xs text-rose-100/80 mt-0.5 font-normal">Jadwal tugas supervisi sesuai periode dan tahap</p>
                    </div>
                </div>

                <!-- Instrumen supervisi -->
                <div class="flex items-start space-x-3">
                    <span class="w-1.5 h-1.5 rounded-full bg-white mt-2 shrink-0"></span>
                    <div>
                        <h2 class="text-sm font-bold text-white tracking-tight leading-snug">Instrumen Supervisi</h2>
                        <p class="text-xs text-rose-100/80 mt-0.5 font-normal">Penilaian perkuliahan luring dan daring beserta bukti</p>
                    </div>
                </div>

                <!-- Penyimpanan draf otomatis -->
                <div class="flex items-start space-x-3">
                    <span class="w-1.5 h-1.5 rounded-full bg-white mt-2 shrink-0"></span>
                    <div>
                        <h2 class="text-sm font-bold text-white tracking-tight leading-snug">Simpan Otomatis</h2>
                        <p class="text-xs text-rose-100/80 mt-0.5 font-normal">Draf penilaian tersimpan saat formulir diisi</p>
                    </div>
                </div>

                <!-- Laporan PDF -->
                <div class="flex items-start space-x-3">
                    <span class="w-1.5 h-1.5 rounded-full bg-white mt-2 shrink-0"></span>
                    <div>
                        <h2 class="text-sm font-bold text-white tracking-tight leading-snug">Laporan PDF</h2>
                        <p class="text-xs text-rose-100/80 mt-0.5 font-normal">Unduh jadwal dan hasil supervisi dalam format PDF</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bottom Left Footer -->
        <div class="relative z-10 flex flex-col sm:flex-row items-start sm:items-center justify-between text-xs text-rose-200/80 pt-6 space-y-2 sm:space-y-0">
            <div>
                &copy; {{ date('Y') }} Lembaga Penjaminan Mutu Universitas Teknokrat Indonesia
            </div>
            <div class="flex items-center space-x-5">
                <button type="button" @click="showModal('kebijakan')" class="hover:text-white transition-colors cursor-pointer">
                    Kebijakan
                </button>
                <button type="button" @click="showModal('bantuan')" class="hover:text-white transition-colors cursor-pointer">
                    Bantuan
                </button>
            </div>
        </div>

    </div>

    <!-- ========================================== -->
    <!-- RIGHT PANEL: Clean Elevated Login Card     -->
    <!-- ========================================== -->
    <div class="w-full lg:w-[42%] xl:w-[40%] flex flex-col justify-between items-center p-6 sm:p-10 lg:p-12 xl:p-14 relative min-h-[550px] lg:min-h-screen bg-[#F8F9FA]">
        
        <div><!-- Top balance spacer --></div>

        <!-- Centered Card Container -->
        <div class="w-full max-w-[420px] my-auto">
            <div class="bg-white rounded-3xl shadow-[0_12px_45px_rgba(0,0,0,0.05)] border border-stone-200/80 p-8 sm:p-10">
                
                <!-- Card Header -->
                <div class="mb-6">
                    <h2 class="text-2xl sm:text-[26px] font-bold text-stone-900 tracking-tight">
                        Selamat Datang
                    </h2>
                    <p class="text-xs sm:text-sm text-stone-500 mt-1 leading-normal">
                        Masukkan detail akun Anda untuk masuk ke sistem.
                    </p>
                </div>

                <!-- Flash Status Alert -->
                @if (session('status'))
                    <div class="mb-5 p-3.5 rounded-xl bg-blue-50 border border-blue-200 text-blue-700 text-xs font-medium flex items-start gap-2">
                        <svg class="w-4 h-4 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <span>{{ session('status') }}</span>
                    </div>
                @endif

                @if (session('error'))
                    <div class="mb-5 p-3.5 rounded-xl bg-red-50 border border-red-200 text-red-700 text-xs font-medium flex items-start gap-2">
                        <svg class="w-4 h-4 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <span>{{ session('error') }}</span>
                    </div>
                @endif

                <!-- Form -->
                <form method="POST" action="{{ route('login') }}" class="space-y-5" x-data="{ showPassword: false }">
                    @csrf

                    <!-- Alamat Email -->
                    <div>
                        <label for="email" class="block text-xs font-semibold text-stone-700 mb-1.5">
                            Alamat Email
                        </label>
                        <input 
                            id="email" 
                            type="email" 
                            name="email" 
                            value="{{ old('email') }}" 
                            required 
                            autofocus 
                            autocomplete="username" 
                            placeholder="Masukkan alamat email Anda" 
                            class="w-full px-4 py-2.5 sm:py-3 rounded-xl border {{ $errors->has('email') ? 'border-red-500 focus:ring-red-500/20 focus:border-red-500' : 'border-stone-200 focus:ring-[#7B1113]/15 focus:border-[#7B1113]' }} bg-white text-stone-900 placeholder:text-stone-400 text-sm transition focus:outline-none focus:ring-2 shadow-2xs"
                        >
                        @error('email')
                            <p class="text-xs text-red-600 mt-1.5 font-medium flex items-center gap-1">
                                <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                {{ $message }}
                            </p>
                        @enderror
                    </div>

                    <!-- Password Field with Show/Hide Toggle & Lupa Password Link -->
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label for="password" class="block text-xs font-semibold text-stone-700">
                                Password
                            </label>
                            <button 
                                type="button" 
                                @click="showForgotPasswordAlert()" 
                                class="text-xs font-semibold text-[#7B1113] hover:text-[#540B0F] transition-colors cursor-pointer"
                            >
                                Lupa password?
                            </button>
                        </div>
                        <div class="relative">
                            <input 
                                id="password" 
                                :type="showPassword ? 'text' : 'password'" 
                                name="password" 
                                required 
                                autocomplete="current-password" 
                                placeholder="••••••••" 
                                class="w-full pl-4 pr-11 py-2.5 sm:py-3 rounded-xl border {{ $errors->has('password') ? 'border-red-500 focus:ring-red-500/20 focus:border-red-500' : 'border-stone-200 focus:ring-[#7B1113]/15 focus:border-[#7B1113]' }} bg-white text-stone-900 placeholder:text-stone-400 text-sm transition focus:outline-none focus:ring-2 shadow-2xs"
                            >
                            <!-- Eye icon toggle -->
                            <button 
                                type="button" 
                                @click="showPassword = !showPassword" 
                                class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-stone-500 hover:text-stone-700 transition-colors"
                                :aria-label="showPassword ? 'Sembunyikan password' : 'Lihat password'"
                                :aria-pressed="showPassword.toString()"
                            >
                                <!-- Eye Off Icon -->
                                <svg x-show="showPassword" x-cloak class="w-4.5 h-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18"></path></svg>
                                <!-- Eye Icon -->
                                <svg x-show="!showPassword" class="w-4.5 h-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                            </button>
                        </div>
                        @error('password')
                            <p class="text-xs text-red-600 mt-1.5 font-medium flex items-center gap-1">
                                <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                {{ $message }}
                            </p>
                        @enderror
                    </div>

                    <!-- Remember Me Checkbox -->
                    <div class="flex items-center pt-0.5">
                        <label class="inline-flex items-center cursor-pointer select-none">
                            <input 
                                type="checkbox" 
                                name="remember" 
                                class="w-4 h-4 rounded border-stone-300 text-[#7B1113] focus:ring-[#7B1113]/25 bg-white transition cursor-pointer"
                            >
                            <span class="ml-2.5 text-xs sm:text-sm text-stone-600 font-normal">
                                Ingat saya
                            </span>
                        </label>
                    </div>

                    <!-- Submit Button -->
                    <div class="pt-2">
                        <button 
                            type="submit" 
                            class="w-full py-3 px-4 rounded-xl bg-[#7B1113] hover:bg-[#600C0E] active:scale-[0.99] text-white font-semibold text-sm sm:text-base flex items-center justify-center gap-2 shadow-md shadow-[#7B1113]/25 hover:shadow-lg transition-all focus:outline-none focus:ring-2 focus:ring-[#7B1113] focus:ring-offset-2 cursor-pointer"
                        >
                            <span>Masuk</span>
                            <span class="text-lg leading-none font-bold">→</span>
                        </button>
                    </div>
                </form>

            </div>
        </div>

        <!-- Footer Copyright Below Card -->
        <div class="w-full text-center text-xs text-stone-400 py-2 font-normal">
            &copy; {{ date('Y') }} Lembaga Penjaminan Mutu Universitas Teknokrat Indonesia
        </div>

    </div>

</div>
@endsection

@push('scripts')
<script>
    function showForgotPasswordAlert() {
        if (window.Swal) {
            Swal.fire({
                title: 'Lupa Password?',
                html: '<div class="text-left text-sm text-stone-600 space-y-2">' +
                      '<p>Akun sistem dikelola secara terpusat oleh <strong>Lembaga Penjaminan Mutu (LPM)</strong> Universitas Teknokrat Indonesia.</p>' +
                      '<p class="mt-2">Jika Anda lupa kata sandi atau mengalami kendala akses, silakan hubungi administrator LPM melalui:</p>' +
                      '<div class="mt-3 p-3 bg-stone-100 rounded-lg text-xs font-mono text-stone-800">' +
                      'Email: <strong>lpm@teknokrat.ac.id</strong><br>' +
                      'Kantor: Gedung GSG Lt. 2, Kampus UTI' +
                      '</div>' +
                      '</div>',
                icon: 'info',
                confirmButtonText: 'Tutup',
                confirmButtonColor: '#7B1113'
            });
        }
    }

    function showModal(type) {
        if (!window.Swal) return;

        if (type === 'kebijakan') {
            Swal.fire({
                title: 'Kebijakan Penggunaan Sistem',
                html: '<div class="text-left text-xs sm:text-sm text-stone-600 space-y-2">' +
                      '<p>Sistem Supervisi Mutu Perkuliahan ini merupakan aset teknologi resmi Universitas Teknokrat Indonesia di bawah pengelolaan LPM.</p>' +
                      '<ul class="list-disc pl-4 space-y-1 mt-2">' +
                      '<li>Akses sistem hanya diperuntukkan bagi Dosen Supervisor dan Auditor yang ditugaskan secara resmi.</li>' +
                      '<li>Seluruh instrumen, penilaian, dan data observasi bersifat rahasia dan terlindungi.</li>' +
                      '<li>Setiap aktivitas login dan pengisian formulir terekam dalam sistem audit trail.</li>' +
                      '</ul>' +
                      '</div>',
                confirmButtonText: 'Saya Mengerti',
                confirmButtonColor: '#7B1113'
            });
        } else if (type === 'bantuan') {
            Swal.fire({
                title: 'Pusat Bantuan & Layanan',
                html: '<div class="text-left text-xs sm:text-sm text-stone-600 space-y-2">' +
                      '<p>Butuh bantuan teknis terkait pengisian supervisi atau sinkronisasi jadwal perkuliahan?</p>' +
                      '<div class="mt-3 p-3 bg-stone-100 rounded-lg text-xs font-mono text-stone-800 space-y-1">' +
                      '<div>LPM Universitas Teknokrat Indonesia</div>' +
                      '<div>Email: <strong>lpm@teknokrat.ac.id</strong></div>' +
                      '<div>Portal: <strong>teknokrat.ac.id</strong></div>' +
                      '</div>' +
                      '</div>',
                confirmButtonText: 'Tutup',
                confirmButtonColor: '#7B1113'
            });
        }
    }
</script>
@endpush
