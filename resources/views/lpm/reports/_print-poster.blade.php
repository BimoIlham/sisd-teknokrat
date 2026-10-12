@php
    $posterStage = $reportScope === 'stage' ? (int) $selectedStage : ($hasStageTwoData ? 2 : 1);
    $posterRankings = [
        ['title' => 'Dosen terbaik', 'color' => '#2563a6', 'items' => $posterStage === 2 ? $top10T2 : $top10T1],
        ['title' => 'Dosen terendah', 'color' => '#d32927', 'items' => $posterStage === 2 ? $bottom10T2 : $bottom10T1],
    ];
    $posterFormat = fn (?float $score): string => $score === null ? 'Belum tersedia' : number_format($score, 2, ',', '.');
@endphp

<div id="executive-report-poster" class="report-poster" hidden>
    <div class="poster-header">
        <div class="poster-brand">
            <img src="{{ asset('images/logo-teknokrat.png') }}" width="52" height="52" alt="Logo Universitas Teknokrat Indonesia">
            <strong>UNIVERSITAS<br><span>TEKNOKRAT</span><br>INDONESIA</strong>
        </div>
        <div class="poster-title">
            <h1>LAPORAN HASIL SUPERVISI PEMBELAJARAN</h1>
            <h2>{{ $reportLabel }}</h2>
            <p>Meningkatkan Kualitas Pembelajaran, Mewujudkan UTI Disiplin, Bermutu, Kreatif, Inovatif</p>
        </div>
        <div class="poster-panel poster-data">
            <h3>RINGKASAN DATA</h3>
            <dl>
                <div><dt>Dosen sasaran</dt><dd>{{ $summary['total_supervised'] }}</dd></div>
                <div><dt>Hasil supervisi terkirim</dt><dd>{{ $summary['submitted_count'] }}</dd></div>
                @if($reportScope === 'semester')
                    <div><dt>Hasil Tahap 2</dt><dd>{{ $summary['count_t2'] }}</dd></div>
                    <div><dt>Rata-rata Tahap 1</dt><dd>{{ $posterFormat($summary['avg_t1']) }}</dd></div>
                    <div><dt>Rata-rata Tahap 2</dt><dd>{{ $posterFormat($summary['avg_t2']) }}</dd></div>
                    <div><dt>Selisih rata-rata</dt><dd>{{ $hasComparisonData ? ($summary['delta_avg'] > 0 ? '+' : '').$posterFormat($summary['delta_avg']) : 'Belum tersedia' }}</dd></div>
                @else
                    <div><dt>Rata-rata skor</dt><dd>{{ $posterFormat($summary['average_score']) }}</dd></div>
                @endif
            </dl>
        </div>
    </div>

    <div class="poster-body">
        <div class="poster-left">
            <div class="poster-rankings {{ $reportScope === 'semester' ? 'poster-rankings-three' : '' }}">
                @foreach($posterRankings as $ranking)
                    <div class="poster-panel poster-ranking" style="--panel-color: {{ $ranking['color'] }}">
                        <h3>{{ count($ranking['items']) }} {{ $ranking['title'] }} Tahap {{ $posterStage }}</h3>
                        <table>
                            <thead><tr><th class="poster-rank">#</th><th>Nama dosen</th><th class="poster-score">Skor T{{ $posterStage }}</th></tr></thead>
                            <tbody>
                                @forelse($ranking['items'] as $item)
                                    <tr><td><span class="poster-number">{{ $item['rank'] }}</span></td><td>{{ $item['name'] }}</td><td class="poster-score">{{ $posterFormat((float) $item['score']) }}</td></tr>
                                @empty
                                    <tr><td colspan="3" class="poster-empty">Belum ada hasil supervisi pada tahap ini.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                        <p class="poster-panel-footer">Skala skor: 0,00–4,00</p>
                    </div>
                @endforeach
                @if($reportScope === 'semester')
                    <div class="poster-panel poster-ranking" style="--panel-color: #2e842e">
                        <h3>{{ count($topImprovement) }} Dosen dengan peningkatan terbesar<br><small>Tahap 1 → Tahap 2</small></h3>
                        <table>
                            <thead><tr><th class="poster-rank">#</th><th>Nama dosen</th><th>T1</th><th>T2</th><th>Naik</th></tr></thead>
                            <tbody>
                                @forelse($topImprovement as $item)
                                    <tr><td><span class="poster-number">{{ $item['rank'] }}</span></td><td>{{ $item['name'] }}</td><td>{{ $posterFormat((float) $item['t1']) }}</td><td>{{ $posterFormat((float) $item['t2']) }}</td><td class="poster-score">+{{ $posterFormat((float) $item['delta']) }}</td></tr>
                                @empty
                                    <tr><td colspan="5" class="poster-empty">{{ $hasComparisonData ? 'Belum ada dosen dengan peningkatan skor pada hasil berpasangan.' : 'Menunggu hasil Tahap 1 dan Tahap 2 yang berpasangan.' }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                        <p class="poster-panel-footer">Perbandingan hasil dosen yang sama pada kedua tahap.</p>
                    </div>
                @endif
            </div>

            <div class="poster-followup">
                <div class="poster-panel poster-clusters">
                    <h3>KLASTER DOSEN BERDASARKAN JENIS TEMUAN<small>Satu dosen dapat masuk lebih dari satu kategori.</small></h3>
                    <div class="poster-cluster-content">
                        <svg class="poster-donut" viewBox="0 0 100 100" role="img" aria-label="Distribusi jumlah dosen per kategori temuan">
                            <circle cx="50" cy="50" r="38" stroke="#e8edf5" stroke-width="15" fill="none" />
                            @php $posterChartOffset = 0; @endphp
                            @foreach($clusters as $cluster)
                                @php $posterSegment = $totalCategoryOccurrences > 0 ? $cluster['count'] / $totalCategoryOccurrences * $chartCircumference : 0; @endphp
                                <circle cx="50" cy="50" r="38" stroke="{{ $cluster['color'] }}" stroke-width="15" fill="none" stroke-dasharray="{{ $posterSegment }} {{ $chartCircumference - $posterSegment }}" stroke-dashoffset="{{ -$posterChartOffset }}" transform="rotate(-90 50 50)" />
                                @php $posterChartOffset += $posterSegment; @endphp
                            @endforeach
                            <g fill="#142c61"><circle cx="50" cy="40" r="6"/><circle cx="36" cy="44" r="4"/><circle cx="64" cy="44" r="4"/><path d="M41 65V57a9 9 0 0118 0v8zM28 62v-9a7 7 0 0110-6v15zM62 62V47a7 7 0 0110 6v9z"/></g>
                        </svg>
                        <ul class="poster-legend">
                            @forelse($clusters as $cluster)
                                <li><i style="background: {{ $cluster['color'] }}"></i><span>{{ $cluster['title'] }}</span><strong>{{ $cluster['count'] }} dosen</strong></li>
                            @empty
                                <li>Belum ada kategori temuan umum.</li>
                            @endforelse
                        </ul>
                    </div>
                    <p class="poster-total">Total kemunculan kategori: <strong>{{ $totalCategoryOccurrences }}</strong></p>
                </div>
                <div class="poster-panel poster-recommendations">
                    <h3>REKOMENDASI TINDAK LANJUT</h3>
                    <div class="poster-recommendation-content">
                        <ol>
                            @forelse($clusters as $cluster)
                                <li style="--category-color: {{ $cluster['color'] }}"><span class="poster-number">{{ $loop->iteration }}</span><div><h4>{{ $cluster['action'] }}</h4><p>{{ $cluster['recommendation'] }}</p></div></li>
                            @empty
                                <li>Tinjau detail supervisi untuk menentukan kebutuhan pendampingan.</li>
                            @endforelse
                        </ol>
                        <div class="poster-focus">
                            <strong>FOKUS PEMBINAAN</strong>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="10" cy="14" r="8"/><circle cx="10" cy="14" r="4"/><path d="M10 14L22 2m-6 0h6v6"/></svg>
                            <p>{{ $clusters->isNotEmpty() ? 'Awali pendampingan pada '.lcfirst($clusters->first()['action']).'.' : 'Identifikasi kebutuhan melalui detail hasil supervisi.' }}</p>
                            <p>Sesuaikan pendampingan dengan kebutuhan setiap dosen.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="poster-right">
            <div class="poster-panel poster-findings" style="--panel-color: #392064">
                <h3>{{ $commonFindings->count() }} TEMUAN UMUM DALAM SUPERVISI</h3>
                @forelse($commonFindings as $finding)
                    <div class="poster-finding" style="--category-color: {{ $categoryPresentation[$finding['key']]['color'] }}">
                        <span class="poster-number">{{ $loop->iteration }}</span>
                        <div class="poster-finding-points"><h4>{{ $finding['title'] }}</h4><ul>@foreach($finding['points'] as $point)<li>{{ $point }}</li>@endforeach</ul></div>
                        <div class="poster-finding-names"><strong>Dosen terkait ({{ $finding['lecturer_count'] }})</strong><p>{{ implode(', ', $finding['lecturer_names']) }}.</p></div>
                    </div>
                @empty
                    <p class="poster-empty">Belum ada kategori temuan umum pada pilihan laporan ini.</p>
                @endforelse
            </div>
            <div class="poster-panel poster-executive">
                <h3>RINGKASAN EKSEKUTIF</h3>
                <div class="poster-executive-text">
                    @if($hasSubmissions)
                        <p>Laporan ini merangkum {{ $summary['submitted_count'] }} hasil supervisi dan {{ $notesCount }} catatan tertulis, dengan rata-rata skor {{ $posterFormat($summary['average_score']) }}.</p>
                        @if($reportScope === 'semester' && $hasComparisonData)
                            <p>Rata-rata Tahap 1 sebesar {{ $posterFormat($summary['avg_t1']) }} dan Tahap 2 sebesar {{ $posterFormat($summary['avg_t2']) }}. Selisih rata-rata kedua tahap adalah {{ $summary['delta_avg'] > 0 ? '+' : '' }}{{ $posterFormat($summary['delta_avg']) }} poin.</p>
                        @endif
                        @if($clusters->isNotEmpty())
                            <p>Tindak lanjut diarahkan pada {{ $clusters->pluck('action')->map(fn (string $action): string => lcfirst($action))->join(', ', ' dan ') }}.</p>
                        @endif
                    @else
                        <p>Belum ada hasil supervisi yang dikirim pada periode dan tahap yang dipilih.</p>
                    @endif
                </div>
                <div class="poster-pillars"><div><b>↗</b>Pemahaman mahasiswa</div><div><b>◎</b>Standar SPMI Universitas</div><div><b>◇</b>Kompetensi lulusan</div><div><b>▥</b>Budaya mutu UTI</div></div>
            </div>
        </div>
    </div>
    <div class="poster-footer"><strong>UTI Disiplin, Bermutu, Kreatif, Inovatif</strong><span>Sumber: hasil supervisi pembelajaran SISD · {{ $reportLabel }}</span></div>
</div>

<style>.report-poster { display: none; }</style>
<style id="executive-report-print-styles" media="print">
    @page { size: A4 landscape; margin: 7mm; }
    html, body { width: 100% !important; min-width: 0 !important; min-height: 0 !important; margin: 0 !important; padding: 0 !important; }
    body { display: block !important; background: white !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    body > .skip-link, body > [x-show="sidebarOpen"], aside, header, nav, #executive-report { display: none !important; }
    body > div.flex-1 { display: block !important; min-width: 0 !important; min-height: 0 !important; background: transparent !important; }
    main { display: block !important; padding: 0 !important; margin: 0 !important; max-width: none !important; width: 100% !important; }
    main > :not(.report-print-sheet) { display: none !important; }
    .report-print-sheet { display: block !important; position: relative !important; width: 283mm !important; height: 194mm !important; padding: 0 !important; margin: 0 !important; overflow: hidden !important; break-inside: avoid; }
    .report-poster { --panel-color: #142c61; display: flex !important; flex-direction: column; gap: 8px; position: absolute; top: 0; left: 0; width: var(--report-print-width, 283mm); min-height: 192mm; box-sizing: border-box; padding: 6px; border: 1px solid #b7c8e3; border-radius: 9px; background: white; color: #162440; font-size: 10px; line-height: 1.35; transform: scale(var(--report-print-scale, 1)); transform-origin: top left; }
    .report-poster *, .report-poster *::before, .report-poster *::after { box-sizing: border-box; }
    .report-poster h1, .report-poster h2, .report-poster h3, .report-poster h4, .report-poster p, .report-poster ul, .report-poster ol, .report-poster dl { margin: 0; }
    .poster-header { display: grid; grid-template-columns: 150px minmax(0, 1fr) 215px; gap: 12px; align-items: start; }
    .poster-brand { display: flex; align-items: center; gap: 5px; font-size: 13px; line-height: 1.1; color: #171717; }
    .poster-brand img { width: 52px; height: 52px; object-fit: contain; }
    .poster-brand span { color: #b31217; }
    .poster-title { text-align: center; padding-top: 3px; }
    .poster-title h1 { font-size: 21px; line-height: 1.12; font-weight: 800; color: #142c61; }
    .poster-title h2 { margin-top: 5px; font-size: 15px; line-height: 1.2; font-weight: 700; text-transform: uppercase; }
    .poster-title p { margin-top: 8px; border-radius: 15px; padding: 4px 8px; background: #142c61; color: white; font-size: 9px; font-weight: 600; }
    .poster-title small { display: block; margin-top: 4px; color: #9b331b; font-size: 9px; }
    .poster-panel { min-width: 0; border: 1px solid #bdcce3; border-radius: 8px; overflow: hidden; background: white; }
    .poster-panel > h3 { padding: 5px 8px; text-align: center; background: var(--panel-color); color: white; font-size: 11px; line-height: 1.2; font-weight: 700; text-transform: uppercase; }
    .poster-panel h3 small { display: block; font-size: 8px; text-transform: none; font-weight: 500; margin-top: 2px; }
    .poster-data dl { padding: 4px 8px; font-size: 9px; }
    .poster-data dl > div { display: flex; justify-content: space-between; gap: 5px; padding: 1px 0; }
    .poster-data dd { margin: 0; font-weight: 700; text-align: right; }
    .poster-body { display: grid; grid-template-columns: 2.05fr 1fr; gap: 8px; flex: 1; min-height: 0; }
    .poster-left { display: grid; grid-template-rows: 1.1fr 1fr; gap: 9px; min-width: 0; }
    .poster-rankings { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6px; }
    .poster-rankings-three { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .poster-ranking { display: flex; flex-direction: column; border-color: color-mix(in srgb, var(--panel-color) 40%, white); }
    .poster-ranking table { width: 100%; table-layout: fixed; border-collapse: collapse; font-size: 9px; }
    .poster-ranking th { background: color-mix(in srgb, var(--panel-color) 10%, white); color: var(--panel-color); padding: 5px 3px; text-align: left; font-size: 8px; }
    .poster-ranking td { padding: 5px 3px; border-bottom: 1px solid #e0e8f2; overflow-wrap: anywhere; }
    .poster-ranking tbody tr:nth-child(even) { background: #f5f8fc; }
    .poster-rank { width: 24px; }
    .poster-score { width: 44px; text-align: center !important; font-weight: 700; color: var(--panel-color); }
    .poster-rankings-three .poster-ranking:last-child th:nth-child(n+3) { width: 29px; }
    .poster-number { display: inline-flex; align-items: center; justify-content: center; width: 19px; height: 19px; border-radius: 50%; background: var(--category-color, var(--panel-color)); color: white; font-weight: 700; flex-shrink: 0; }
    .poster-panel-footer { padding: 5px 8px; font-size: 8px; background: #f5f8fc; margin-top: auto !important; }
    .poster-empty { padding: 12px !important; font-weight: 400; color: #58657a; }
    .poster-followup { display: grid; grid-template-columns: 1fr 1.55fr; gap: 8px; }
    .poster-clusters { display: flex; flex-direction: column; }
    .poster-cluster-content { display: flex; gap: 6px; align-items: center; padding: 12px 8px; flex: 1; }
    .poster-donut { width: 110px; height: 110px; flex-shrink: 0; }
    .poster-legend { list-style: none; padding: 0; flex: 1; font-size: 8px; }
    .poster-legend li { display: flex; align-items: center; gap: 4px; padding: 6px 0; border-bottom: 1px solid #e8edf4; }
    .poster-legend i { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
    .poster-legend span { flex: 1; }
    .poster-legend strong { white-space: nowrap; }
    .poster-total { margin: 0 8px 10px !important; padding: 7px; background: #edf3fb; border: 1px solid #d0ddee; border-radius: 5px; text-align: center; font-size: 9px; }
    .poster-recommendations { display: flex; flex-direction: column; }
    .poster-recommendation-content { display: grid; grid-template-columns: minmax(0, 1fr) 100px; gap: 7px; padding: 8px; flex: 1; }
    .poster-recommendation-content ol { list-style: none; padding: 0; }
    .poster-recommendation-content li { display: flex; gap: 6px; padding: 6px 0; border-bottom: 1px solid #e2eaf4; font-size: 9px; }
    .poster-recommendation-content h4 { color: var(--category-color); font-size: 10px; font-weight: 700; margin-bottom: 2px; }
    .poster-focus { border: 1px solid #9eb2d3; border-radius: 7px; padding: 8px; background: #f5f8fd; text-align: center; font-size: 9px; color: #142c61; }
    .poster-focus svg { display: block; width: 38px; height: 38px; margin: 12px auto; }
    .poster-focus p + p { margin-top: 8px; }
    .poster-right { display: flex; flex-direction: column; gap: 7px; min-width: 0; }
    .poster-findings { display: flex; flex-direction: column; flex: 1; }
    .poster-finding { display: grid; grid-template-columns: 25px minmax(0, 1.15fr) minmax(0, 1fr); gap: 6px; padding: 9px 7px; border-bottom: 1px solid #dce4f0; flex: 1; font-size: 9px; }
    .poster-finding h4 { color: var(--category-color); font-size: 10px; font-weight: 700; margin-bottom: 4px; }
    .poster-finding ul { padding-left: 11px; list-style: disc; }
    .poster-finding li + li { margin-top: 3px; }
    .poster-finding-names { border-left: 1px solid #dce4f0; padding-left: 6px; overflow-wrap: anywhere; }
    .poster-finding-names strong { display: block; color: var(--category-color); margin-bottom: 4px; }
    .poster-executive-text { padding: 7px 8px; font-size: 9px; }
    .poster-executive-text p + p { margin-top: 5px; }
    .poster-pillars { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); margin: 0 7px 7px; border: 1px solid #c5d4e9; border-radius: 5px; font-size: 8px; text-align: center; }
    .poster-pillars > div { padding: 4px; border-right: 1px solid #dde5f0; }
    .poster-pillars > div:last-child { border: 0; }
    .poster-pillars b { display: block; color: #2563a6; font-size: 22px; line-height: 1.2; }
    .poster-footer { display: flex; justify-content: space-between; align-items: center; gap: 12px; background: #142c61; color: white; padding: 5px 8px; border-radius: 3px; font-size: 9px; }
    .poster-footer span { max-width: 55%; text-align: right; font-size: 8px; }
</style>
