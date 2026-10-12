<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Surat Tugas {{ $letter['supervisor_name'] }} - Tahap {{ $letter['stage_no'] }}</title>
    <style>
        @page { margin: 111.5pt 72pt 90pt 77pt; }
        body { margin: 0; font-family: "Times New Roman", Times, serif; font-size: 12pt; color: #000; line-height: 1.72; }
        .letterhead { position: fixed; left: -70.4pt; top: -94.7pt; width: 577.8pt; height: 813.6pt; transform: rotate(180deg); z-index: -1; }
        .title { text-align: center; font-weight: bold; line-height: 1.2; margin-bottom: 12pt; }
        p { margin: 0 0 9.5pt; text-align: justify; }
        .identity { margin: 0 0 13pt 14pt; border-collapse: collapse; width: 100%; }
        .identity td { padding: 0; line-height: 1.45; vertical-align: top; }
        .identity .label { width: 94pt; }
        .groups { margin: 0 0 10pt 18pt; }
        .group-title { font-weight: bold; margin-bottom: 1.5pt; page-break-after: avoid; }
        ol { margin: 0 0 12pt 37pt; padding: 0; }
        li { padding-left: 4pt; margin-bottom: 1.8pt; page-break-inside: avoid; }
        .signing { position: relative; margin-top: 29pt; margin-left: 211pt; width: 240pt; line-height: 1.2; page-break-inside: avoid; }
        .signature-space { height: 61pt; }
        .chair-signature { position: absolute; top: 30pt; left: 0; width: 110pt; height: auto; }
        .chair-name { font-weight: bold; }
    </style>
</head>
<body>
    <img class="letterhead" src="{{ $letterhead }}" alt="Kop Universitas Teknokrat Indonesia">
    <div class="title">SURAT TUGAS<br>Nomor : {{ $letter['number'] }}</div>
    <p>Dengan ini menugaskan kepada:</p>
    <table class="identity">
        <tr><td class="label">Nama</td><td>: {{ $letter['supervisor_name'] }}</td></tr>
        <tr><td class="label">Jabatan</td><td>: Tim Supervisor</td></tr>
    </table>
    <p>Untuk melaksanakan tugas supervisi mutu perkuliahan pada semester {{ $letter['semester'] }} T.A. {{ $letter['academic_year'] }} (Tahap {{ $letter['stage_no'] }}), yang akan dilaksanakan {{ $letter['starts_on'] }} s.d {{ $letter['ends_on'] }} terhadap dosen sebagai berikut:</p>
    <div class="groups">
        @foreach($letter['groups'] as $group)
            <div class="group-title">{{ chr(97 + $loop->index) }}.&nbsp; Dosen Perkuliahan {{ $group['label'] }}</div>
            <ol>
                @foreach($group['lecturers'] as $name)
                    <li>{{ $name }}</li>
                @endforeach
            </ol>
        @endforeach
    </div>
    <p>Demikian surat tugas ini untuk dapat dilaksanakan sebagaimana mestinya dan hasil Supervisi dapat dilaporkan paling lambat pada {{ $letter['ends_on'] }}.</p>
    <div class="signing">
        <div>Bandar Lampung, {{ $letter['issued_on'] }}</div>
        <div>Ketua LPM,</div>
        @if(!empty($signature))
            <img class="chair-signature" src="{{ $signature }}" alt="Tanda tangan Ketua LPM">
        @endif
        <div class="signature-space"></div>
        <div class="chair-name">{{ $letter['chair_name'] }}</div>
    </div>
</body>
</html>
