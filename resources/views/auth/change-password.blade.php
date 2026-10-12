@extends('layouts.app', ['title' => 'Ubah Kata Sandi'])

@section('content')
<div class="min-h-[75vh] flex items-center justify-center px-4 py-8">
    <div class="w-full max-w-lg">
        <div class="bg-white rounded-2xl shadow-xl border border-stone-200 overflow-hidden">
            <div class="bg-gradient-to-br from-amber-700 via-amber-800 to-amber-950 p-6 sm:p-8 text-white">
                <div class="flex items-center space-x-3 mb-2">
                    <div class="w-10 h-10 rounded-xl bg-white/20 backdrop-blur-md flex items-center justify-center text-white">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                    </div>
                    <div>
                        <h1 class="text-xl font-bold tracking-tight">Pembaruan Kata Sandi Akun</h1>
                        <p class="text-xs text-amber-100">Kebijakan Keamanan Akun Dosen & LPM</p>
                    </div>
                </div>
                <p class="text-xs text-amber-200/90 mt-2 leading-relaxed">
                    Sistem mewajibkan Anda untuk memperbarui kata sandi akun Anda demi menjaga integritas data penilaian supervisi dan kerahasiaan dokumen.
                </p>
            </div>

            <form method="POST" action="{{ route('password.update') }}" class="p-6 sm:p-8 space-y-5">
                @csrf

                <!-- Current Password -->
                <div>
                    <label for="current_password" class="block text-xs font-semibold uppercase tracking-wider text-stone-700 mb-1.5">
                        Kata Sandi Saat Ini
                    </label>
                    <input id="current_password" type="password" name="current_password" required autofocus class="w-full px-4 py-2.5 rounded-xl border {{ $errors->has('current_password') ? 'border-red-500' : 'border-stone-300' }} bg-white text-stone-900 text-sm focus:outline-none focus:ring-2 focus:ring-[#7B1113]">
                    @error('current_password')
                        <p class="text-xs text-red-600 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                <!-- New Password -->
                <div>
                    <label for="password" class="block text-xs font-semibold uppercase tracking-wider text-stone-700 mb-1.5">
                        Kata Sandi Baru (Minimal 8 Karakter)
                    </label>
                    <input id="password" type="password" name="password" required placeholder="Gunakan kombinasi huruf dan angka" class="w-full px-4 py-2.5 rounded-xl border {{ $errors->has('password') ? 'border-red-500' : 'border-stone-300' }} bg-white text-stone-900 text-sm focus:outline-none focus:ring-2 focus:ring-[#7B1113]">
                    <p class="text-xs text-stone-600 mt-1.5">Kata sandi baru harus berbeda dari kata sandi saat ini.</p>
                    @error('password')
                        <p class="text-xs text-red-600 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Confirm Password -->
                <div>
                    <label for="password_confirmation" class="block text-xs font-semibold uppercase tracking-wider text-stone-700 mb-1.5">
                        Konfirmasi Kata Sandi Baru
                    </label>
                    <input id="password_confirmation" type="password" name="password_confirmation" required class="w-full px-4 py-2.5 rounded-xl border border-stone-300 bg-white text-stone-900 text-sm focus:outline-none focus:ring-2 focus:ring-[#7B1113]">
                </div>

                <div class="pt-2">
                    <button type="submit" class="w-full py-2.5 px-4 rounded-xl bg-[#7B1113] hover:bg-[#600C0E] text-white font-semibold text-sm shadow-md transition-all">
                        Simpan Kata Sandi Baru
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
