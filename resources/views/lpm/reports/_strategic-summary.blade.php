
    <section class="report-strategic grid grid-cols-1 lg:grid-cols-3 gap-6 items-stretch">

        <!-- CARD 1: KLASTER DOSEN BERDASARKAN JENIS TEMUAN -->
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm flex flex-col overflow-hidden">
            <div class="px-5 py-3.5 bg-slate-50/70">
                <h2 class="text-sm font-semibold text-slate-900 leading-tight">
                    Klaster Dosen Berdasarkan Jenis Temuan
                </h2>
                <p class="text-[11px] text-slate-500 mt-0.5">(1 dosen dapat masuk lebih dari 1 kategori)</p>
            </div>
            
            <div class="p-5 flex-1 flex flex-col justify-between items-center text-center space-y-4">
                <!-- Donut Chart Representation -->
                <div class="relative w-32 h-32 flex items-center justify-center">
                    <svg aria-hidden="true" class="w-full h-full transform -rotate-90" viewBox="0 0 100 100">
                        <circle cx="50" cy="50" r="38" stroke="#f1f5f9" stroke-width="14" fill="transparent" />
                        @php
                            $circumference = $chartCircumference;
                            $chartOffset = 0;
                        @endphp
                        @foreach($clusters as $cluster)
                            @php
                                $segmentLength = $totalCategoryOccurrences > 0
                                    ? ($cluster['count'] / $totalCategoryOccurrences) * $circumference
                                    : 0;
                            @endphp
                            <circle
                                cx="50"
                                cy="50"
                                r="38"
                                stroke="{{ $cluster['color'] }}"
                                stroke-width="14"
                                fill="transparent"
                                stroke-dasharray="{{ $segmentLength }} {{ $circumference - $segmentLength }}"
                                stroke-dashoffset="{{ -$chartOffset }}"
                            />
                            @php $chartOffset += $segmentLength; @endphp
                        @endforeach
                    </svg>
                    <div class="absolute inset-0 flex flex-col items-center justify-center">
                        <svg class="w-5 h-5 text-[#163973]" fill="currentColor" viewBox="0 0 20 20"><path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v3h8v-3zM6 8a2 2 0 11-4 0 2 2 0 014 0zM16 18v-3a5.972 5.972 0 00-.75-2.906A3.005 3.005 0 0119 15v3h-3zM4.75 12.094A5.973 5.973 0 004 15v3H1v-3a3 3 0 013.75-2.906z"/></svg>
                        <span class="text-[9px] font-semibold text-slate-500 mt-0.5">Klaster</span>
                    </div>
                </div>

                <!-- Legend List -->
                <div class="w-full text-left text-xs space-y-2">
                    @foreach($clusters as $cl)
                        <div class="flex items-center justify-between text-xs py-0.5">
                            <div class="flex items-center gap-2 truncate">
                                <span class="w-2.5 h-2.5 rounded-full shrink-0" style="background-color: {{ $cl['color'] }};"></span>
                                <span class="text-slate-700 truncate font-medium">{{ $cl['title'] }}</span>
                            </div>
                            <span class="font-semibold text-slate-900 ml-2 shrink-0">{{ $cl['count'] }} dosen</span>
                        </div>
                    @endforeach
                </div>

                <div class="w-full bg-slate-50 rounded-xl py-2 px-3 text-xs font-semibold text-slate-700">
                    Total kemunculan kategori: {{ $totalCategoryOccurrences }}
                </div>
            </div>
        </div>

        <!-- CARD 2: REKOMENDASI TINDAK LANJUT -->
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm flex flex-col overflow-hidden">
            <div class="px-5 py-3.5 bg-slate-50/70">
                <h2 class="text-sm font-semibold text-slate-900 leading-tight">
                    Rekomendasi Tindak Lanjut
                </h2>
                <p class="text-[11px] text-slate-500 mt-0.5">Langkah strategis peningkatan mutu berkelanjutan</p>
            </div>

            <div class="p-5 flex-1 flex flex-col justify-between space-y-4">
                <div class="space-y-3">
                    @foreach($clusters as $rec)
                        <div class="flex items-start gap-2.5">
                            <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-slate-100 text-slate-700 font-semibold text-xs shrink-0 mt-0.5">
                                {{ $loop->iteration }}
                            </span>
                            <div>
                                <h3 class="font-semibold text-xs sm:text-sm text-slate-900 leading-tight">{{ $rec['action'] }}</h3>
                                <p class="text-xs text-slate-600 leading-relaxed mt-0.5">{{ $rec['recommendation'] }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>

                <!-- Callout Box: Fokus Pembinaan Prioritas -->
                <div class="bg-blue-50/70 rounded-xl p-3 text-xs text-slate-700 flex items-center gap-3">
                    <span class="w-8 h-8 rounded-lg bg-[#163973] text-white flex items-center justify-center shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </span>
                    <p class="text-xs text-slate-700 leading-snug">
                        <span class="font-medium">Fokus pembinaan:</span> {{ $clusters->isNotEmpty() ? $clusters->first()['action'].'. Tinjau kebutuhan setiap dosen pada detail supervisi.' : 'Tinjau detail supervisi untuk menentukan kebutuhan pendampingan.' }}
                    </p>
                </div>
            </div>
        </div>

        <!-- CARD 3: RINGKASAN EKSEKUTIF & 4 PILAR -->
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm flex flex-col overflow-hidden">
            <div class="px-5 py-3.5 bg-slate-50/70">
                <h2 class="text-sm font-semibold text-slate-900 leading-tight">
                    Ringkasan Eksekutif
                </h2>
                <p class="text-[11px] text-slate-500 mt-0.5">Sintesis capaian dan arah pengembangan pembelajaran</p>
            </div>

            <div class="p-5 flex-1 flex flex-col justify-between space-y-4">
                <div class="text-xs leading-relaxed text-slate-700 text-left space-y-3">
                    <p>Laporan ini merangkum {{ $summary['submitted_count'] }} hasil supervisi dan {{ $notesCount }} catatan tertulis. Rata-rata skor keseluruhan adalah {{ number_format($summary['average_score'], 2, ',', '.') }}.</p>
                    @if($reportScope === 'semester' && $hasComparisonData)
                        <p>Rata-rata skor Tahap 1 sebesar {{ number_format($summary['avg_t1'], 2, ',', '.') }} dan Tahap 2 sebesar {{ number_format($summary['avg_t2'], 2, ',', '.') }}, dengan selisih {{ $summary['delta_avg'] > 0 ? '+' : '' }}{{ number_format($summary['delta_avg'], 2, ',', '.') }} poin.</p>
                    @endif
                    @if($clusters->isNotEmpty())
                        <p>Tindak lanjut diarahkan pada {{ $clusters->pluck('action')->map(fn (string $action): string => lcfirst($action))->join(', ', ' dan ') }}.</p>
                    @else
                        <p>Belum ada kategori temuan umum. Kebutuhan tindak lanjut dapat ditinjau pada detail supervisi.</p>
                    @endif
                </div>

                <!-- 4 Outcome Pillars -->
                <div class="pt-3 grid grid-cols-2 gap-2.5 text-xs text-slate-700">
                    <div class="flex items-center gap-2 p-2 rounded-lg bg-slate-50">
                        <span class="w-2 h-2 rounded-full bg-blue-600 shrink-0"></span>
                        <span class="leading-tight font-medium text-[11px]">Mendorong pemahaman mahasiswa</span>
                    </div>
                    <div class="flex items-center gap-2 p-2 rounded-lg bg-slate-50">
                        <span class="w-2 h-2 rounded-full bg-indigo-600 shrink-0"></span>
                        <span class="leading-tight font-medium text-[11px]">Mendukung standar SPMI Universitas</span>
                    </div>
                    <div class="flex items-center gap-2 p-2 rounded-lg bg-slate-50">
                        <span class="w-2 h-2 rounded-full bg-emerald-600 shrink-0"></span>
                        <span class="leading-tight font-medium text-[11px]">Mendukung kompetensi lulusan</span>
                    </div>
                    <div class="flex items-center gap-2 p-2 rounded-lg bg-slate-50">
                        <span class="w-2 h-2 rounded-full bg-amber-600 shrink-0"></span>
                        <span class="leading-tight font-medium text-[11px]">UTI Disiplin, Bermutu, Kreatif, Inovatif</span>
                    </div>
                </div>
            </div>
        </div>

    </section>
