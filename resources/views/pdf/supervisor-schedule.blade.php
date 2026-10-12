<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Jadwal Personal Dosen - {{ $lecturer->name }}</title>
    <style>
        @page {
            margin: 1.5cm 1.2cm 1.5cm 1.2cm;
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 9pt;
            color: #1e293b;
            line-height: 1.3;
        }
        .header-table {
            width: 100%;
            border-bottom: 2px solid #7f1d1d;
            padding-bottom: 10px;
            margin-bottom: 14px;
        }
        .header-logo {
            width: 55px;
            height: 55px;
            background-color: #7f1d1d;
            color: #ffffff;
            font-size: 20pt;
            font-weight: bold;
            text-align: center;
            line-height: 55px;
            border-radius: 6px;
        }
        .header-title {
            padding-left: 12px;
        }
        .inst-name {
            font-size: 13pt;
            font-weight: bold;
            color: #7f1d1d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .doc-name {
            font-size: 11pt;
            font-weight: bold;
            color: #0f172a;
            margin-top: 2px;
        }
        .doc-sub {
            font-size: 8.5pt;
            color: #64748b;
        }
        .meta-table {
            width: 100%;
            margin-bottom: 14px;
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 8px 12px;
            border-radius: 4px;
        }
        .meta-table td {
            font-size: 8.5pt;
            padding: 2px 4px;
        }
        .legend-box {
            margin-bottom: 12px;
            font-size: 8pt;
        }
        .legend-item {
            display: inline-block;
            margin-right: 15px;
        }
        .legend-color-teaching {
            display: inline-block;
            width: 12px;
            height: 12px;
            background-color: #ffffff;
            border: 1px solid #94a3b8;
            vertical-align: middle;
            margin-right: 4px;
        }
        .legend-color-supervision {
            display: inline-block;
            width: 12px;
            height: 12px;
            background-color: #fef08a;
            border: 1px solid #ca8a04;
            vertical-align: middle;
            margin-right: 4px;
        }
        .week-title {
            font-size: 9.5pt;
            font-weight: bold;
            color: #7f1d1d;
            background-color: #f1f5f9;
            padding: 5px 8px;
            border-left: 3px solid #7f1d1d;
            margin-top: 12px;
            margin-bottom: 6px;
        }
        table.schedule-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        table.schedule-table th {
            background-color: #334155;
            color: #ffffff;
            font-size: 8pt;
            font-weight: 600;
            padding: 5px 6px;
            text-align: left;
            border: 1px solid #cbd5e1;
        }
        table.schedule-table td {
            padding: 5px 6px;
            font-size: 8pt;
            border: 1px solid #e2e8f0;
            vertical-align: top;
        }
        tr.row-supervision td {
            background-color: #fef9c3 !important; /* Yellow supervision slot */
            color: #713f12;
            font-weight: 500;
        }
        .badge-supervision {
            display: inline-block;
            background-color: #ca8a04;
            color: #ffffff;
            font-size: 6.5pt;
            font-weight: bold;
            padding: 1px 4px;
            border-radius: 3px;
            text-transform: uppercase;
        }
        .badge-teaching {
            display: inline-block;
            background-color: #e2e8f0;
            color: #475569;
            font-size: 6.5pt;
            font-weight: bold;
            padding: 1px 4px;
            border-radius: 3px;
        }
        .footer {
            position: fixed;
            bottom: -0.8cm;
            left: 0;
            right: 0;
            height: 25px;
            font-size: 7.5pt;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
            padding-top: 4px;
        }
    </style>
</head>
<body>
    <div class="footer">
        <table style="width: 100%;">
            <tr>
                <td style="text-align: left;">Sistem Supervisi Mutu Perkuliahan • Lembaga Penjaminan Mutu (LPM) UTI</td>
                <td style="text-align: right;">Dicetak pada: {{ $generatedAt }} WIB</td>
            </tr>
        </table>
    </div>

    <!-- Header -->
    <table class="header-table">
        <tr>
            <td style="width: 60px; vertical-align: middle;">
                <div class="header-logo">UTI</div>
            </td>
            <td class="header-title" style="vertical-align: middle;">
                <div class="inst-name">Universitas Teknokrat Indonesia</div>
                <div class="doc-name">Jadwal Terpadu Mengajar & Penugasan Supervisi Mutu Perkuliahan</div>
                <div class="doc-sub">Lembaga Penjaminan Mutu (LPM) • {{ $period->semester_display }} • Tahap 1 dan Tahap 2</div>
            </td>
        </tr>
    </table>

    <!-- Lecturer Meta -->
    <table class="meta-table">
        <tr>
            <td style="width: 18%; font-weight: bold;">Nama Dosen</td>
            <td style="width: 32%;">: {{ $lecturer->name }}</td>
            <td style="width: 18%; font-weight: bold;">NIDN / NIP</td>
            <td style="width: 32%;">: {{ $lecturer->nidn ?? '-' }}</td>
        </tr>
        <tr>
            <td style="font-weight: bold;">Program Studi</td>
            <td>: {{ $lecturer->studyProgram?->name ?? 'Dosen Universitas' }}</td>
            <td style="font-weight: bold;">Beban Supervisi</td>
            <td>: <strong>{{ $totalSupervisions }}</strong> Sesi Supervisi Terjadwal</td>
        </tr>
    </table>

    <!-- Legend -->
    <div class="legend-box">
        <span class="legend-item">
            <span class="legend-color-teaching"></span>
            <strong>Putih:</strong> Jadwal Mengajar Pribadi ({{ $totalTeaching }} pertemuan)
        </span>
        <span class="legend-item">
            <span class="legend-color-supervision"></span>
            <strong>Kuning:</strong> Tugas Supervisi Kelas Dosen Lain ({{ $totalSupervisions }} sesi)
        </span>
    </div>

    <!-- Events by Week -->
    @forelse($groupedEvents as $weekLabel => $events)
        <div class="week-title">Minggu: {{ $weekLabel }}</div>
        
        <table class="schedule-table">
            <thead>
                <tr>
                    <th style="width: 11%;">Hari / Tanggal</th>
                    <th style="width: 12%;">Waktu (WIB)</th>
                    <th style="width: 9%;">Tipe</th>
                    <th style="width: 25%;">Mata Kuliah / Kelas</th>
                    <th style="width: 12%;">Ruang / Mode</th>
                    <th style="width: 31%;">Keterangan / Dosen Terkait</th>
                </tr>
            </thead>
            <tbody>
                @foreach($events as $event)
                    <tr class="{{ $event['is_supervision'] ? 'row-supervision' : '' }}">
                        <td>
                            <strong>{{ $event['day_name'] }}</strong><br>
                            <span style="color: #64748b; font-size: 7.5pt;">{{ $event['date']->format('d/m/Y') }}</span>
                        </td>
                        <td>{{ substr($event['start_time'], 0, 5) }} - {{ substr($event['end_time'], 0, 5) }}</td>
                        <td>
                            @if($event['is_supervision'])
                                <span class="badge-supervision">Supervisi</span>
                            @else
                                <span class="badge-teaching">Mengajar</span>
                            @endif
                        </td>
                        <td>
                            <strong>{{ $event['course_name'] }}</strong><br>
                            <span style="font-size: 7.5pt; color: #475569;">{{ $event['course_code'] }} • Kls {{ $event['class_name'] }}</span>
                        </td>
                        <td>
                            <strong>{{ $event['room'] ?? 'Daring' }}</strong><br>
                            <span style="font-size: 7.5pt; color: #64748b;">{{ $event['delivery_mode'] }}</span>
                        </td>
                        <td>
                            @if($event['is_supervision'])
                                <strong>Menilai Dosen:</strong> {{ $event['target_lecturer'] }}<br>
                                <span style="font-size: 7.5pt; color: #854d0e;">Tahap: {{ $event['stage_name'] }}</span>
                            @else
                                <span style="color: #64748b;">Mengajar Mandiri</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @empty
        <div style="text-align: center; padding: 25px; border: 1px dashed #cbd5e1; color: #64748b;">
            Tidak ada jadwal mengajar atau tugas supervisi pada periode ini.
        </div>
    @endforelse

</body>
</html>
