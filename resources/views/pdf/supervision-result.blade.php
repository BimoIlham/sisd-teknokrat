<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Hasil Supervisi - {{ $lecturer?->name ?? 'Dosen' }}</title>
    <style>
        @page {
            margin: 1.05cm 1.15cm 1.35cm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: DejaVu Sans, sans-serif;
            font-size: 8pt;
            line-height: 1.35;
            color: #1f2937;
        }

        table {
            border-spacing: 0;
        }

        .document-header {
            width: 100%;
            border-bottom: 2px solid #1f2937;
            padding-bottom: 7px;
            margin-bottom: 13px;
        }

        .document-header td {
            vertical-align: middle;
        }

        .logo-cell {
            width: 65px;
        }

        .logo {
            width: 55px;
            height: 55px;
        }

        .header-kicker {
            font-size: 10pt;
            font-weight: bold;
            letter-spacing: .45px;
        }

        .header-title {
            margin-top: 1px;
            font-size: 13.5pt;
            font-weight: bold;
            color: #111827;
            line-height: 1.15;
        }

        .header-subtitle {
            margin-top: 4px;
            font-size: 7.5pt;
            color: #6b7280;
        }

        .overview {
            width: 100%;
            margin-bottom: 13px;
        }

        .overview > tbody > tr > td {
            vertical-align: top;
        }

        .overview-info {
            width: 69%;
            padding-right: 16px;
        }

        .overview-score {
            width: 31%;
        }

        .overview-comparison .overview-info {
            width: 60%;
        }

        .overview-comparison .overview-score {
            width: 40%;
        }

        .stage-score-table {
            width: 100%;
            table-layout: fixed;
        }

        .stage-score-table td {
            vertical-align: top;
        }

        .overview-comparison .score-panel {
            padding: 8px 5px;
        }

        .overview-comparison .score-value {
            font-size: 23pt;
        }

        .overview-comparison .predicate {
            padding: 5px;
            font-size: 7pt;
        }

        .assessment-comparison {
            table-layout: fixed;
        }

        .assessment-comparison .number-column {
            width: 4%;
        }

        .assessment-comparison .indicator-column {
            width: 34%;
        }

        .assessment-comparison .answer-column {
            width: 24%;
        }

        .assessment-comparison .score-column {
            width: 7%;
        }

        .assessment-comparison th,
        .assessment-comparison td {
            padding: 5px 4px;
        }

        .block-title {
            margin: 0 0 6px;
            font-size: 8.5pt;
            font-weight: bold;
            color: #111827;
        }

        .info-table {
            width: 100%;
        }

        .info-table td {
            padding: 3.2px 5px;
            vertical-align: top;
        }

        .info-table tr:nth-child(even) td {
            background: #f4f4f5;
        }

        .info-label {
            width: 31%;
            font-weight: bold;
            color: #374151;
        }

        .info-separator {
            width: 10px;
            padding-right: 0 !important;
            padding-left: 0 !important;
        }

        .info-value {
            color: #111827;
        }

        .score-panel {
            min-height: 190px;
            padding: 8px 10px;
            border-left: 1px solid #d1d5db;
            text-align: center;
        }

        .score-label {
            font-size: 7.5pt;
            font-weight: bold;
            color: #6b7280;
            letter-spacing: .7px;
        }

        .score-value {
            margin-top: 4px;
            font-size: 35pt;
            line-height: 1;
            font-weight: bold;
            color: #111827;
        }

        .score-scale {
            margin-top: 5px;
            font-size: 9pt;
            font-weight: bold;
            color: #7b1113;
        }

        .score-percentage {
            margin-top: 3px;
            font-size: 8pt;
            color: #6b7280;
        }

        .predicate {
            display: inline-block;
            margin-top: 10px;
            padding: 5px 13px;
            border-radius: 3px;
            background: #7b1113;
            color: #ffffff;
            font-size: 8.5pt;
            font-weight: bold;
        }

        .status-line {
            margin-top: 7px;
            font-size: 7pt;
            color: #6b7280;
        }

        .summary-box {
            margin-bottom: 13px;
            padding: 9px 10px;
            border: 1px solid #d1d5db;
            border-radius: 3px;
            background: #f8fafc;
            color: #374151;
        }

        .summary-title {
            margin-bottom: 6px;
            font-size: 8pt;
            font-weight: bold;
            color: #111827;
        }

        .summary-metrics,
        .summary-distribution {
            width: 100%;
            border-collapse: collapse;
        }

        .summary-metrics td {
            width: 50%;
            padding: 3px 8px 7px 0;
            vertical-align: top;
        }

        .summary-value {
            font-size: 11pt;
            font-weight: bold;
            color: #111827;
        }

        .summary-label {
            font-size: 7pt;
            color: #4b5563;
        }

        .summary-detail-title {
            padding-top: 6px;
            border-top: 1px solid #e5e7eb;
            font-size: 7pt;
            color: #4b5563;
        }

        .summary-distribution td {
            width: 20%;
            padding-top: 4px;
            font-size: 7pt;
            color: #4b5563;
        }

        .summary-distribution strong {
            color: #111827;
        }

        .section-heading {
            width: 100%;
            margin-top: 11px;
            margin-bottom: 4px;
            padding-bottom: 4px;
            border-bottom: 1.5px solid #7b1113;
            page-break-after: avoid;
        }

        .assessment-section {
            page-break-inside: avoid;
        }

        .section-heading td {
            vertical-align: bottom;
        }

        .section-name {
            font-size: 8.5pt;
            font-weight: bold;
            color: #111827;
        }

        .section-score {
            text-align: right;
            font-size: 7.2pt;
            color: #4b5563;
        }

        .assessment-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
            font-size: 7.4pt;
        }

        .assessment-table tr {
            page-break-inside: avoid;
        }

        .assessment-table th {
            padding: 5px 6px;
            border: 1px solid #9ca3af;
            background: #e5e7eb;
            color: #1f2937;
            font-size: 7pt;
            font-weight: bold;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: .2px;
        }

        .assessment-table td {
            padding: 5px 6px;
            border: 1px solid #d1d5db;
            vertical-align: top;
            overflow-wrap: anywhere;
        }

        .assessment-table tbody tr:nth-child(even) td {
            background: #fafafa;
        }

        .number-column {
            width: 27px;
            text-align: center !important;
        }

        .answer-column {
            width: 25%;
        }

        .score-column {
            width: 42px;
            text-align: center !important;
        }

        .score-number {
            font-weight: bold;
            color: #7b1113;
        }

        .not-scored {
            color: #9ca3af;
        }

        .method-box {
            margin-top: 11px;
            padding: 7px 10px;
            border-left: 3px solid #7b1113;
            background: #f9fafb;
            page-break-inside: avoid;
        }

        .method-title {
            font-size: 7.5pt;
            font-weight: bold;
            color: #111827;
        }

        .method-text {
            margin-top: 2px;
            font-size: 7.2pt;
            color: #4b5563;
        }

        .signatures {
            width: 100%;
            margin-top: 10px;
            page-break-inside: avoid;
        }

        .signatures td {
            width: 50%;
            padding: 0 10px;
            text-align: center;
            vertical-align: top;
        }

        .signature-caption {
            color: #6b7280;
            font-size: 7pt;
        }

        .signature-role {
            margin-top: 2px;
            font-size: 7.5pt;
            font-weight: bold;
        }

        .signature-space {
            height: 60px;
        }

        .signature-name {
            padding-top: 3px;
            border-top: 1px solid #374151;
            font-size: 7.5pt;
            font-weight: bold;
        }

        .signature-id {
            margin-top: 2px;
            color: #6b7280;
            font-size: 6.8pt;
        }

        .page-footer {
            position: fixed;
            right: 0;
            bottom: -0.82cm;
            left: 0;
            padding-top: 4px;
            border-top: 1px solid #d1d5db;
            color: #9ca3af;
            font-size: 6.5pt;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="page-footer">
        Dokumen dihasilkan otomatis oleh Sistem Informasi Supervisi Mutu Perkuliahan (SISD) &mdash; {{ $generatedAt }}
    </div>

    <table class="document-header">
        <tr>
            <td class="logo-cell">
                @if($logoDataUri)
                    <img class="logo" src="{{ $logoDataUri }}" alt="Logo Universitas Teknokrat Indonesia">
                @endif
            </td>
            <td>
                <div class="header-kicker">BERITA ACARA</div>
                <div class="header-title">LAPORAN HASIL SUPERVISI PEMBELAJARAN</div>
                <div class="header-subtitle">Lembaga Penjaminan Mutu &mdash; Universitas Teknokrat Indonesia</div>
            </td>
        </tr>
    </table>

    @if(($legacySnapshot ?? false) || collect($stageScores)->contains(fn ($score) => $score['legacySnapshot'] ?? false))
        <p style="font-size: 8pt; color: #6b7280;">Data legacy: snapshot instrumen historis belum lengkap. Redaksi instrumen menggunakan master yang tersedia saat laporan dibuat.</p>
    @endif

    <table class="overview{{ $isStageTwo ? ' overview-comparison' : '' }}">
        <tr>
            <td class="overview-info">
                <div class="block-title">Informasi Supervisi</div>
                <table class="info-table">
                    <tr>
                        <td class="info-label">Dosen yang Disupervisi</td>
                        <td class="info-separator">:</td>
                        <td class="info-value"><strong>{{ $lecturer?->name ?? '-' }}</strong></td>
                    </tr>
                    <tr>
                        <td class="info-label">NIDN / Identitas</td>
                        <td class="info-separator">:</td>
                        <td class="info-value">{{ $lecturer?->nidn ?? '-' }}</td>
                    </tr>
                    <tr>
                        <td class="info-label">Program Studi</td>
                        <td class="info-separator">:</td>
                        <td class="info-value">{{ $lecturer?->studyProgram?->name ?? '-' }}</td>
                    </tr>
                    <tr>
                        <td class="info-label">Fakultas</td>
                        <td class="info-separator">:</td>
                        <td class="info-value">{{ $lecturer?->faculty?->name ?? $lecturer?->studyProgram?->faculty?->name ?? '-' }}</td>
                    </tr>
                    <tr>
                        <td class="info-label">Supervisor</td>
                        <td class="info-separator">:</td>
                        <td class="info-value"><strong>{{ $supervisor?->name ?? '-' }}</strong> ({{ $supervisor?->nidn ?? '-' }})</td>
                    </tr>
                    <tr>
                        <td class="info-label">Semester / Tahap</td>
                        <td class="info-separator">:</td>
                        <td class="info-value">{{ $period?->semester_display ?? '-' }} / Tahap {{ $stage?->stage_no ?? '-' }}</td>
                    </tr>
                    <tr>
                        <td class="info-label">Mata Kuliah / Kelas</td>
                        <td class="info-separator">:</td>
                        <td class="info-value">{{ $course?->code ? $course->code.' - ' : '' }}{{ $course?->name ?? '-' }} / {{ $schedule?->class_name ?? '-' }}</td>
                    </tr>
                    <tr>
                        <td class="info-label">Pelaksanaan</td>
                        <td class="info-separator">:</td>
                        <td class="info-value">
                            @if($performedAt)
                                {{ $performedAt->translatedFormat('d F Y') }}
                                &nbsp;|&nbsp;
                                {{ $performedAt->format('H:i:s') }} WIB
                            @else
                                Belum tercatat
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td class="info-label">Moda / Ruang</td>
                        <td class="info-separator">:</td>
                        <td class="info-value">{{ $deliveryModeLabel }} / {{ $schedule?->room ?? '-' }}</td>
                    </tr>
                </table>
            </td>
            <td class="overview-score">
                <table class="stage-score-table">
                    <tr>
                        @foreach($stageScores as $stageNumber => $scoreData)
                            <td>
                                <div class="score-panel">
                                    <div class="score-label">NILAI TAHAP {{ $stageNumber }}</div>
                                    <div class="score-value">{{ $scoreData['finalScore'] ?? '-' }}</div>
                                    @if($scoreData)
                                        <div class="score-scale">/ {{ $scoreData['maximumScore'] }}</div>
                                        <div class="score-percentage">
                                            {{ $scoreData['finalScorePercentage'] !== null ? number_format($scoreData['finalScorePercentage'], 1) : '-' }}% ketercapaian
                                        </div>
                                        <div class="predicate">{{ $scoreData['predicateGrade'] }} &mdash; {{ $scoreData['predicate'] }}</div>
                                        <div class="status-line">Status: <strong>{{ $scoreData['statusLabel'] }}</strong></div>
                                    @else
                                        <div class="status-line">Belum dinilai</div>
                                    @endif
                                </div>
                            </td>
                        @endforeach
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="summary-box">
        <div class="summary-title">Ringkasan jawaban Tahap {{ $stage?->stage_no ?? 1 }}</div>
        <table class="summary-metrics">
            <tr>
                <td>
                    <span class="summary-value">{{ $answeredCount }} dari {{ $totalQuestionCount }}</span><br>
                    <span class="summary-label">Pertanyaan terisi</span>
                </td>
                <td>
                    <span class="summary-value">{{ $scoredCount }}</span><br>
                    <span class="summary-label">Pertanyaan yang masuk perhitungan nilai tahap ini</span>
                </td>
            </tr>
        </table>
        <div class="summary-detail-title">Jumlah pertanyaan menurut nilai yang diberikan:</div>
        <table class="summary-distribution">
            <tr>
                @foreach([4, 3, 2, 1, 0] as $score)
                    <td>Nilai {{ $score }}: <strong>{{ $scoreDistribution[$score] }} pertanyaan</strong></td>
                @endforeach
            </tr>
        </table>
    </div>

    @foreach(($isStageTwo ? $comparisonSections : $structuredSections) as $sectionIndex => $section)
        @if(count($section['questions']) > 0)
            <div class="assessment-section">
                <table class="section-heading">
                    <tr>
                        <td class="section-name">{{ $sectionIndex + 1 }}. {{ $section['title'] }}</td>
                        <td class="section-score">
                            @if($isStageTwo)
                                Rata-rata aspek:
                                @foreach($section['averages'] as $stageNumber => $average)
                                    T{{ $stageNumber }} <strong>{{ $average !== null ? number_format($average, 2) : '-' }}</strong>{{ ! $loop->last ? ' / ' : '' }}
                                @endforeach
                            @elseif($section['avg_score'] !== null)
                                Rata-rata aspek: <strong>{{ number_format($section['avg_score'], 2) }} / 4.00</strong>
                                ({{ number_format($section['score_percentage'], 1) }}%)
                            @else
                                Aspek non-penilaian
                            @endif
                        </td>
                    </tr>
                </table>

                <table class="assessment-table{{ $isStageTwo ? ' assessment-comparison' : '' }}">
                    <thead>
                        <tr>
                            <th class="number-column">No</th>
                            <th class="indicator-column">Indikator Penilaian</th>
                            @if($isStageTwo)
                                <th class="answer-column">Hasil Pengamatan T1</th>
                                <th class="answer-column">Hasil Pengamatan T2</th>
                                <th class="score-column">Skor T1</th>
                                <th class="score-column">Skor T2</th>
                            @else
                                <th class="answer-column">Hasil Pengamatan</th>
                                <th class="score-column">Skor</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($section['questions'] as $questionIndex => $question)
                            @php
                                $observations = $isStageTwo ? $question['observations'] : [1 => $question];
                                $displayStages = $isStageTwo ? [1, 2] : [1];
                            @endphp
                            <tr>
                                <td class="number-column"><strong>{{ $questionIndex + 1 }}</strong></td>
                                <td>{{ $question['text'] }}</td>
                                @foreach($displayStages as $stageNumber)
                                    <td class="answer-column">
                                        @if(isset($observations[$stageNumber]))
                                            @if(($observations[$stageNumber]['evaluation_notes'] ?? null) !== null)
                                                @include('supervisor.supervisions._evaluation-notes', ['evaluationNotes' => $observations[$stageNumber]['evaluation_notes']])
                                            @else
                                                {{ $observations[$stageNumber]['answer_display'] }}
                                            @endif
                                            @if($observations[$stageNumber]['notes'])
                                                <br><strong>Catatan:</strong> {{ $observations[$stageNumber]['notes'] }}
                                            @endif
                                        @elseif($stageNumber === 1 && $stageScores[1] === null)
                                            Belum dinilai
                                        @else
                                            &mdash;
                                        @endif
                                    </td>
                                @endforeach
                                @foreach($displayStages as $stageNumber)
                                    <td class="score-column">
                                        @if(($observations[$stageNumber]['is_scored'] ?? false) && $observations[$stageNumber]['score'] !== null)
                                            <span class="score-number">{{ number_format((float) $observations[$stageNumber]['score'], 1) }}</span>
                                        @else
                                            <span class="not-scored">&mdash;</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endforeach

    <div class="method-box">
        <div class="method-title">Metode Perhitungan Nilai</div>
        <div class="method-text">
            {{ $policyName }} &mdash; {{ $policyFormula }}.
            Nilai Tahap {{ $stage?->stage_no ?? 1 }} ditampilkan pada skala {{ $maximumScore }} dan dihitung hanya dari {{ $scoredCount }} butir yang memiliki bobot penilaian.
        </div>
    </div>

    <table class="signatures">
        <tr>
            <td>
                <div class="signature-caption">Dosen yang Disupervisi,</div>
                <div class="signature-role">Dosen Pengampu</div>
                <div class="signature-space"></div>
                <div class="signature-name">{{ $lecturer?->name ?? '................................' }}</div>
                <div class="signature-id">NIDN: {{ $lecturer?->nidn ?? '-' }}</div>
            </td>
            <td>
                <div class="signature-caption">Penilai,</div>
                <div class="signature-role">Dosen Supervisor</div>
                <div class="signature-space"></div>
                <div class="signature-name">{{ $supervisor?->name ?? '................................' }}</div>
                <div class="signature-id">NIDN: {{ $supervisor?->nidn ?? '-' }}</div>
            </td>
        </tr>
    </table>
</body>
</html>
