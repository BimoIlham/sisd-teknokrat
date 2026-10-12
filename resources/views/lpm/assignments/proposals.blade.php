    <div class="bg-white rounded-2xl border-2 border-emerald-500/40 shadow-sm overflow-hidden">
        <!-- Banner Header -->
        <div class="p-5 bg-gradient-to-r from-emerald-50/90 via-white to-emerald-50/40 border-b border-emerald-100 flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="flex items-start gap-3.5">
                <div class="w-10 h-10 rounded-xl bg-emerald-600 text-white flex items-center justify-center shrink-0 shadow-xs mt-0.5">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                </div>
                <div>
                    <div class="flex items-center gap-2.5 flex-wrap">
                        <h3 class="text-base font-bold text-slate-900">Usulan Hasil Penjadwalan Otomatis</h3>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold {{ $isProposalApplied ? 'bg-slate-100 text-slate-700' : 'bg-emerald-100 text-emerald-800 border border-emerald-200' }}">
                            {{ $isProposalApplied ? 'Telah Diterapkan' : 'Pratinjau Siap Diterapkan' }}, {{ $latestRun->target_coverage_rate }}% Terjadwal
                        </span>
                    </div>
                    <p class="text-xs text-slate-600 mt-1">
                        {{ $isProposalApplied 
                            ? 'Usulan jadwal berikut telah berhasil diterapkan ke sistem penugasan aktif tahap ini.' 
                            : 'Tinjau pembagian jadwal dosen dan supervisor hasil komputasi di bawah ini secara langsung sebelum diterapkan.' }}
                    </p>
                </div>
            </div>

            <!-- Header Action: Terapkan Sekarang -->
            <div class="flex items-center gap-2.5 shrink-0">
                @if(!$stage->is_finalized && in_array($latestRun->status, ['OPTIMAL', 'FEASIBLE']) && !$isProposalApplied)
                    <form id="apply-run-form-top" action="{{ route('lpm.assignments.apply_run', $latestRun) }}" method="POST">
                        @csrf
                        <input type="hidden" name="revision" value="{{ $state->revision }}">
                        <button 
                            type="button" 
                            onclick="window.confirmModal({
                                title: 'Terapkan Usulan Jadwal?',
                                text: 'Terapkan seluruh usulan jadwal hasil optimasi ke sistem penugasan aktif?',
                                icon: 'question',
                                confirmText: 'Ya, Terapkan Sekarang',
                                cancelText: 'Batal',
                                confirmColor: '#059669',
                                onConfirm: () => document.getElementById('apply-run-form-top').submit()
                            })"
                            class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-emerald-700 hover:bg-emerald-800 text-white font-bold text-xs shadow-sm transition-all hover:shadow cursor-pointer"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            <span>Terapkan Sekarang</span>
                        </button>
                    </form>
                @elseif($isProposalApplied)
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-emerald-100 text-emerald-800 font-semibold text-xs border border-emerald-200">
                        <svg class="w-4 h-4 text-emerald-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Aktif di Tahap Ini
                    </span>
                @endif
                <button 
                    type="button" 
                    @click="showProposalPreview = false" 
                    class="p-2 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100 transition-colors cursor-pointer"
                    title="Sembunyikan Pratinjau Usulan"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>

        <!-- Table Toolbar: Search Filter -->
        <div class="grid gap-2 border-b border-slate-200/80 bg-slate-50/70 p-3 text-xs lg:grid-cols-[minmax(0,24rem)_1fr] lg:items-center">
            <div class="filter-search">
                <svg class="filter-search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input 
                    type="text" 
                    id="proposal-search-input" x-model="proposalSearchQuery" @input.debounce.400ms="loadProposalPreview()" maxlength="150" aria-label="Cari usulan jadwal" 
                    placeholder="Cari dosen, supervisor, mata kuliah..." 
                    class="filter-control"
                >
            </div>
            <div class="flex items-center gap-3 text-slate-500">
                <span>Total: <strong>{{ $enrichedProposals->total() }}</strong> usulan jadwal observasi kelas</span>
            </div>
        </div>

        <!-- In-Page Table (Wide, clear, comfortable font & spacing) -->
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-slate-50 text-slate-600 font-semibold uppercase text-[11px] tracking-wider border-b border-slate-200">
                    <tr>
                        <th class="px-4 py-3 text-center w-12">No</th>
                        <th class="px-4 py-3">Dosen yang Disupervisi</th>
                        <th class="px-4 py-3">Program Studi</th>
                        <th class="px-4 py-3">Supervisor Diusulkan</th>
                        <th class="px-4 py-3">Jadwal Kelas Terpilih</th>
                        <th class="px-4 py-3 text-center">Tipe</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($enrichedProposals as $idx => $prop)
                        <tr 
                            
                            class="hover:bg-emerald-50/30 transition-colors"
                        >
                            <td class="px-4 py-3.5 text-center font-mono text-slate-400">
                                {{ $enrichedProposals->firstItem() + $loop->index }}
                            </td>
                            <td class="px-4 py-3.5">
                                <div class="font-bold text-slate-900 text-[13px]">
                                    {{ $prop['target_name'] }}
                                </div>
                                <span class="text-[11px] font-mono text-slate-500">NIDN: {{ $prop['target_nidn'] }}</span>
                            </td>
                            <td class="px-4 py-3.5 text-slate-700 font-medium">
                                {{ $prop['target_prodi'] }}
                            </td>
                            <td class="px-4 py-3.5">
                                <div class="font-bold text-slate-900 text-[13px]">
                                    {{ $prop['supervisor_name'] }}
                                </div>
                                <span class="text-[11px] font-mono text-slate-500">NIDN: {{ $prop['supervisor_nidn'] }}</span>
                            </td>
                            <td class="px-4 py-3.5">
                                <div class="font-semibold text-slate-900 text-xs">
                                    {{ $prop['course_name'] }}
                                </div>
                                <div class="text-[11px] text-slate-600 mt-1 flex items-center gap-2 flex-wrap">
                                    <span class="inline-flex items-center gap-1 font-medium text-slate-700">
                                        <svg class="w-3 h-3 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                        {{ $prop['date'] }}
                                    </span>

                                    <span class="inline-flex items-center gap-1 font-medium text-slate-700">
                                        <svg class="w-3 h-3 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        {{ $prop['time'] }}
                                    </span>

                                    <span class="inline-flex items-center gap-1 font-semibold text-slate-800 bg-slate-100 px-2 py-0.5 rounded">
                                        <svg class="w-3 h-3 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                                        Ruang {{ $prop['room'] }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-3.5 text-center">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-[10px] font-bold {{ $prop['assignment_type'] === 'MANUAL' ? 'bg-blue-100 text-blue-800' : 'bg-emerald-100 text-emerald-800' }}">
                                    {{ $prop['assignment_type'] === 'MANUAL' ? 'MANUAL' : 'OTOMATIS' }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">Tidak ada usulan yang sesuai dengan pencarian.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Table Footer with Action Button -->
        <div class="border-t border-slate-200 px-4 py-3" @click="const link = $event.target.closest('a'); if (link) { $event.preventDefault(); loadProposalPreview(link.href); }">
            {{ $enrichedProposals->links('vendor.pagination.tailwind', ['label' => 'usulan jadwal']) }}
        </div>
        <div class="p-4 bg-slate-50 border-t border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <span class="text-xs text-slate-600">
                Total Usulan: <strong>{{ $enrichedProposals->total() }} Jadwal</strong> ({{ $latestRun->target_coverage_rate }}% Terjadwal)
            </span>
            <div class="flex items-center gap-2.5">
                <button 
                    type="button" 
                    @click="showProposalPreview = false" 
                    class="px-4 py-2 rounded-xl border border-slate-300 bg-white hover:bg-slate-100 text-xs font-semibold text-slate-700 transition-colors cursor-pointer"
                >
                    Sembunyikan
                </button>
                @if(!$stage->is_finalized && in_array($latestRun->status, ['OPTIMAL', 'FEASIBLE']) && !$isProposalApplied)
                    <form id="apply-run-form-bottom" action="{{ route('lpm.assignments.apply_run', $latestRun) }}" method="POST">
                        @csrf
                        <input type="hidden" name="revision" value="{{ $state->revision }}">
                        <button 
                            type="button" 
                            onclick="window.confirmModal({
                                title: 'Terapkan Usulan Jadwal?',
                                text: 'Terapkan seluruh usulan jadwal hasil optimasi ke sistem penugasan aktif?',
                                icon: 'question',
                                confirmText: 'Ya, Terapkan Sekarang',
                                cancelText: 'Batal',
                                confirmColor: '#059669',
                                onConfirm: () => document.getElementById('apply-run-form-bottom').submit()
                            })"
                            class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-emerald-700 hover:bg-emerald-800 text-white font-bold text-xs shadow-sm transition-all hover:shadow cursor-pointer"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            <span>Terapkan Sekarang</span>
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>
