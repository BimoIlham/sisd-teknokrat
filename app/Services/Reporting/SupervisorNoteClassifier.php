<?php

namespace App\Services\Reporting;

use Illuminate\Support\Str;

class SupervisorNoteClassifier
{
    /**
     * Initial phrase rules derived from the separate stage workbooks, not a trained model.
     *
     * @return array<string, array{title: string, description: string, topic: string, issue: string, suggestion: string, positive: string}>
     */
    public function categories(): array
    {
        return [
            'spada' => [
                'title' => 'Dokumen dan materi SPADA',
                'description' => 'Ketersediaan, kelengkapan, pembaruan, dan pengesahan perangkat serta materi pembelajaran.',
                'topic' => '\b(?:spada|lms|rps|kontrak kuliah|learning contract|lc)\b',
                'issue' => '(?:spada|lms)\s+(?:masih\s+)?(?:kosong|tidak lengkap|belum\s+(?:diisi|dilengkapi|diupdate|diperbarui|lengkap))|(?:materi|konten|slide|rps|kontrak kuliah|lc).{0,65}(?:belum\s+(?:diunggah|diupload|lengkap|tersedia|disahkan|ditandatangani)|tidak\s+(?:ada|lengkap)|masih kosong)|(?:tidak ada|belum ada|tidak terdapat|belum tersedia).{0,35}(?:materi|konten|slide|rps|kontrak kuliah|lc)\b|(?:rps|kontrak kuliah|lc).{0,20}(?:belum|tidak).{0,20}(?:disahkan|ditandatangani)|belum ada pengesahan|belum ada tanda tangan',
                'suggestion' => '(?:lengkapi|melengkapi|diisi|mengisi|mengelola|diunggah|mengunggah|diupload|mengupload|diupdate|diperbarui|update|dilengkapi).{0,45}(?:spada|lms)|(?:spada|lms).{0,40}(?:dilengkapi|diisi|diupdate|diperbarui)|(?:rps|kontrak kuliah|lc).{0,30}(?:diunggah|diupload|dilampirkan)',
                'positive' => '(?:spada|lms|rps|materi|konten).{0,35}(?:sudah|telah).{0,25}(?:lengkap|terisi|tersedia|diunggah)|(?:spada|lms)\s+(?:sudah\s+)?(?:sangat\s+)?(?:lengkap|baik)',
            ],
            'video' => [
                'title' => 'Video pembelajaran',
                'description' => 'Ketersediaan, jumlah, kesesuaian, dan tautan video pembelajaran.',
                'topic' => '\b(?:video|youtube|kanal|channel|chanel)\b',
                'issue' => 'video.{0,50}(?:belum\s+(?:ada|tersedia|diunggah|diupload|ditautkan|dibuat)|tidak\s+(?:ada|tersedia|sesuai)|terbatas|sedikit|hanya\s+(?:ada\s+)?[12]\b)|(?:tidak ada|belum ada|belum terdapat|belum tersedia).{0,30}video|(?:bukan|tidak sesuai).{0,30}(?:mata\s*kuliah|matakuliah)|video.{0,25}(?:orang lain|channel lain)',
                'suggestion' => '(?:tambah|tambahkan|menambah|penambahan|memperbanyak|perbanyak|menyiapkan|membuat|menautkan|ditautkan|shooting|diperkaya).{0,45}video|video.{0,50}(?:ditambahkan|ditautkan|diunggah|ditingkatkan)',
                'positive' => 'video.{0,35}(?:sudah|telah).{0,30}(?:tersedia|diunggah|ditautkan)|(?:tersedia|terdapat)\s+video',
            ],
            'classroom' => [
                'title' => 'Pengelolaan kelas dan partisipasi',
                'description' => 'Pengawasan kelas, posisi duduk, fokus, interaksi, dan keterlibatan mahasiswa.',
                'topic' => 'mahasiswa|manajemen kelas|pengelolaan kelas|interaksi|partisipasi|berkeliling|duduk|berdiri|smartphone|gadget|handphone|telepon genggam|\bhp\b',
                'issue' => '(?:mahasiswa|siswa).{0,60}(?:bermain|sibuk|tertidur|tidur|tidak fokus|tidak memperhatikan|pasif)|(?:pengelolaan kelas|manajemen kelas|interaksi).{0,30}(?:kurang|belum)|(?:hanya|terus|tidak beranjak).{0,20}(?:duduk|tempat duduk)|(?:barisan|baris|kursi).{0,15}depan.{0,15}kosong|(?:kurang|tidak).{0,25}(?:kontrol|terkontrol)|masih mahasiswa (?:yang|yg) sama',
                'suggestion' => '(?:meningkatkan|ditingkatkan|perkuat|memperkuat|mengoptimalkan|mengontrol|mengawasi|mengarahkan).{0,45}(?:kelas|mahasiswa|interaksi|partisipasi)|berkeliling|menjangkau|menghampiri|menempati|berdiri|diskusi kelompok kecil|kasih giliran|(?:interaksi|partisipasi|pengelolaan kelas|manajemen kelas).{0,35}(?:ditingkatkan|dibangun|diperkuat)|mahasiswa.{0,25}(?:diarahkan|diminta)|tidak hanya duduk',
                'positive' => '(?:mahasiswa|interaksi|kelas|pembelajaran).{0,35}(?:aktif|kondusif|antusias|interaktif|terbangun)|dosen.{0,25}(?:aktif berkeliling|mampu menguasai kelas)',
            ],
            'time' => [
                'title' => 'Ketepatan waktu dan durasi',
                'description' => 'Waktu mulai, waktu selesai, durasi, serta kehadiran dosen atau mahasiswa sesuai catatan.',
                'topic' => 'waktu|terlambat|\btelat\b|durasi|menit|kehadiran|tidak mengajar|ketidak\s*hadiran|tidak masuk',
                'issue' => '(?<!tidak )(?<!tidak lagi )terlambat|\btelat\b|(?:selesai|menutup|berakhir).{0,30}(?:sebelum|lebih cepat)|(?:dimulai|mulai).{0,20}(?:15|20|30) menit|(?:durasi|alokasi waktu).{0,25}(?:tidak penuh|kurang)|dosen.{0,30}(?:tidak mengajar|tidak masuk|ketidak\s*hadiran)|(?:melebihi|tidak sesuai).{0,25}waktu',
                'suggestion' => '(?:memanfaatkan|mengoptimalkan|memaksimalkan|menyesuaikan).{0,30}(?:waktu|durasi)|(?:hadir|datang|kuliah|kelas).{0,25}tepat waktu|disiplin.{0,15}waktu',
                'positive' => '(?:hadir|datang|memulai|dimulai|selesai|mengakhiri|kelas).{0,20}(?:tepat waktu|sesuai jadwal|on time)|tidak(?: lagi)? terlambat|durasi.{0,20}(?:sudah|tepat)',
            ],
            'english' => [
                'title' => 'Bahasa Inggris pada bahan ajar',
                'description' => 'Bahasa pada slide, materi, dan instruksi pembelajaran.',
                'topic' => 'bahasa inggris|bahasa indonesia|english|bilingual',
                'issue' => '(?:slide|materi|instruksi|bahan ajar).{0,45}(?:belum|tidak).{0,20}(?:berbahasa inggris|menggunakan bahasa inggris|dalam bahasa inggris|english)|(?:slide|materi|instruksi|bahan ajar).{0,25}(?:masih\s+)?(?:berbahasa indonesia|menggunakan bahasa indonesia|dalam bahasa indonesia)',
                'suggestion' => '(?:slide|materi|instruksi|bahan ajar|salindia).{0,50}(?:bahasa inggris|english|bilingual)|(?:gunakan|menggunakan|disajikan).{0,35}(?:bahasa inggris|english)',
                'positive' => '(?:slide|materi|instruksi).{0,40}(?:sudah|telah).{0,30}(?:bahasa inggris|english)|materi sudah tersedia dalam bahasa inggris',
            ],
            'structure' => [
                'title' => 'Pembukaan dan penutupan pembelajaran',
                'description' => 'Apersepsi, tujuan pembelajaran, rangkuman, refleksi, dan informasi pertemuan berikutnya.',
                'topic' => 'apersepsi|pembukaan|penutup|rangkuman|simpulan|kesimpulan|refleksi|tujuan pembelajaran|capaian pembelajaran|pertemuan berikutnya|pertemuan selanjutnya',
                'issue' => '(?:tidak ada|belum ada|belum adanya|tidak terdapat).{0,35}(?:pembukaan|salam|rangkuman|simpulan|review)|(?:pembelajaran|pemahaman).{0,25}belum.{0,30}(?:simpulan|diperiksa)',
                'suggestion' => '(?:menyampaikan|menambahkan|memperkuat|memperjelas|melibatkan|menutup|menyimpulkan|ditambahkan|ditampilkan|disampaikan).{0,45}(?:tujuan|capaian|pembelajaran|materi|mahasiswa)|(?:penutup|rangkuman|simpulan|tujuan|capaian).{0,35}(?:diperkuat|ditingkatkan|ditambahkan|melibatkan|ditampilkan)',
                'positive' => 'diawali|sudah menyampaikan|telah menyampaikan|sudah menutup|telah menyimpulkan|menyimpulkan materi dengan baik|(?:pembukaan|penutupan|rangkuman).{0,30}(?:baik|jelas|smooth)',
            ],
            'references' => [
                'title' => 'Referensi dan contoh penerapan',
                'description' => 'Acuan materi, referensi terbaru, studi kasus, serta penerapan di dunia kerja.',
                'topic' => 'referensi|references|sitasi|acuan|studi kasus|dunia kerja|dunia nyata|dudi|industri|contoh penerapan',
                'issue' => '(?:materi|slide|referensi).{0,35}(?:tidak|belum).{0,25}(?:referensi|sumber|acuan|terbaru)|(?:tidak ada|belum ada).{0,25}(?:referensi|acuan)',
                'suggestion' => '(?:tambahan|tambahkan|menambahkan|perbanyak|dilengkapi|melengkapi|memberikan|disematkan|ditambahkan|update).{0,55}(?:referensi|sitasi|acuan|contoh|dunia kerja|dunia nyata|dudi|industri)|(?:referensi|acuan).{0,35}(?:ditambahkan|disematkan|dilengkapi|terbaru)',
                'positive' => '(?:materi|contoh).{0,30}(?:relevan|sesuai)|(?:mengaitkan|menghubungkan).{0,35}(?:dunia nyata|dunia kerja)',
            ],
            'online' => [
                'title' => 'Aktivitas pembelajaran daring',
                'description' => 'Forum diskusi, respons, pertemuan sinkron, bukti Zoom, dan evaluasi daring.',
                'topic' => 'forum|sinkron|synchronous|zoom|virtual|pre-test|post-test|rubrik|evaluasi daring',
                'issue' => '(?:belum|tidak).{0,45}(?:forum|diskusi|sinkron|virtual|zoom|pre-test|post-test)|(?:forum|sinkron|synchronous|virtual|zoom).{0,30}(?:belum|tidak|hanya.{0,10}1)|post-test dan tugas masih digabung',
                'suggestion' => '(?:menambah|tambahkan|memanfaatkan|meningkatkan|memberikan|menyediakan|dilengkapi|memisahkan).{0,45}(?:forum|diskusi|sinkron|virtual|zoom|rubrik|post-test)|(?:forum|diskusi).{0,30}(?:direspon|direspons)|screenshot zoom',
                'positive' => '(?:forum|diskusi|sinkron|zoom).{0,25}(?:sudah|telah).{0,30}(?:digunakan|dilaksanakan|aktif)',
            ],
        ];
    }

    /**
     * @return array<int, array{text: string, category: string|null, status: string, standard_texts: array<int, string>}>
     */
    public function classify(string $text, ?string $intent = null): array
    {
        $results = [];
        foreach ($this->fragments($text) as $fragment) {
            $normalized = $this->normalizeText($fragment['text']);
            $suggested = $fragment['context'] === 'suggestion'
                || $this->matches('sebaiknya|disarankan|direkomendasikan|diharapkan|hendaknya|mohon|jangan|saran\s*:|perlu|baiknya|coba|tambahkan|perbanyak', $normalized);
            $historical = $this->matches('sebelumnya|supervisi pertama|supervisi tahap 1|supervisi sebelumnya', $normalized);
            $ambiguousNegation = $this->matches('(?:tidak|belum|kurang).{0,15}(?:aktif|kondusif|antusias|interaktif|baik|tepat waktu)|tidak (?:lagi )?(?:bermain|tertidur|tidur|telat)|tidak ada (?:masalah|kendala|kekurangan)', $normalized);
            $matched = false;
            foreach ($this->categories() as $key => $category) {
                if (! $this->matches($category['topic'], $normalized)) {
                    continue;
                }
                $issue = $this->matches($category['issue'], $normalized);
                $suggestion = $suggested && $this->matches($category['suggestion'], $normalized);
                $positive = $this->matches($category['positive'], $normalized);
                if (! $issue && ! $suggestion && ! $positive && $intent === null) {
                    continue;
                }
                $matched = true;
                $status = $intent ?? match (true) {
                    $historical, $ambiguousNegation, $issue && $positive, $issue && $fragment['context'] === 'positive' => 'review',
                    $suggestion => 'suggestion',
                    $suggested => 'review',
                    $issue => 'improvement',
                    $positive && ! $suggested && $fragment['context'] !== 'improvement' => 'positive',
                    default => 'review',
                };
                $standardTexts = in_array($status, ['improvement', 'suggestion'], true)
                    ? $this->standardize($normalized, $key, $status)
                    : [];
                if (in_array($status, ['improvement', 'suggestion'], true) && $standardTexts === []) {
                    $status = 'review';
                }
                $results[] = [
                    'text' => $fragment['text'], 'category' => $key, 'status' => $status,
                    'standard_texts' => $standardTexts,
                ];
            }
            if (! $matched) {
                $genericPositive = $this->matches('^(?:sudah\s+)?(?:sangat\s+)?baik[.!]?$|sudah (?:sangat )?baik|berjalan dengan baik', $normalized)
                    && ! $suggested && $fragment['context'] !== 'improvement'
                    && ! $this->matches('namun|tetapi|belum|tidak|kurang', $normalized);
                $results[] = [
                    'text' => $fragment['text'], 'category' => null, 'status' => $intent === 'positive' || $genericPositive ? 'positive' : 'review',
                    'standard_texts' => [],
                ];
            }
        }

        return $results;
    }

    private function normalizeText(string $text): string
    {
        $words = [
            'yg' => 'yang', 'utk' => 'untuk', 'tdk' => 'tidak', 'gak' => 'tidak', 'nggak' => 'tidak',
            'ga' => 'tidak', 'gk' => 'tidak', 'blm' => 'belum', 'sdh' => 'sudah', 'udh' => 'sudah',
            'udah' => 'sudah', 'dgn' => 'dengan', 'bhs' => 'bahasa', 'mhs' => 'mahasiswa',
            'pakai' => 'menggunakan', 'pake' => 'menggunakan', 'tambahin' => 'tambahkan',
            'slidenya' => 'slide', 'gadgenya' => 'gadget', 'managemen' => 'manajemen', 'telat' => 'terlambat',
            'videonya' => 'video', 'spadanya' => 'spada', 'materinya' => 'materi',
            'mahasiswanya' => 'mahasiswa', 'dosennya' => 'dosen',
        ];
        $normalized = preg_replace_callback('/\b('.implode('|', array_keys($words)).')\b/u',
            fn (array $match): string => $words[$match[1]], Str::lower($text)) ?? Str::lower($text);

        return preg_replace(['/\bdi[ -]+upload\b/u', '/\bdi[ -]+update\b/u', '/\bdi[ -]+lengkapi\b/u'],
            ['diunggah', 'diperbarui', 'dilengkapi'], $normalized) ?? $normalized;
    }

    /**
     * Standard statements describe only recognized observations or suggestions.
     * Unrecognized wording stays available for review instead of receiving a guessed paraphrase.
     *
     * @return array<int, string>
     */
    private function standardize(string $text, string $category, string $status): array
    {
        $rules = [
            'spada' => [
                'improvement' => [
                    '(?:spada|lms).{0,20}(?:kosong|belum\s+diisi)' => 'Konten pembelajaran pada SPADA belum diisi.',
                    '(?:spada|lms).{0,20}belum\s+(?:dilengkapi|diupdate|diperbarui)' => 'Konten pembelajaran pada SPADA belum dilengkapi atau diperbarui.',
                    '(?:spada|lms).{0,20}(?:belum|tidak)\s+lengkap' => 'Konten pembelajaran pada SPADA belum lengkap.',
                    '(?:materi|konten|slide).{0,65}(?:belum\s+(?:diunggah|diupload|tersedia)|tidak\s+ada|masih kosong)|(?:tidak ada|belum ada|tidak terdapat|belum tersedia).{0,35}(?:materi|konten|slide)' => 'Materi pembelajaran belum tersedia pada SPADA.',
                    '(?:materi|konten|slide).{0,65}(?:belum\s+lengkap|tidak\s+lengkap)' => 'Materi pembelajaran pada SPADA belum lengkap.',
                    'rps.{0,40}(?:belum\s+(?:diunggah|diupload|tersedia)|tidak\s+ada)|(?:tidak ada|belum ada|belum tersedia).{0,25}rps' => 'Rencana Pembelajaran Semester (RPS) belum tersedia.',
                    '(?:kontrak kuliah|\blc\b).{0,40}(?:belum\s+(?:diunggah|diupload|tersedia)|tidak\s+ada)|(?:tidak ada|belum ada|belum tersedia).{0,25}(?:kontrak kuliah|\blc\b)' => 'Kontrak perkuliahan belum tersedia.',
                    '(?:rps|kontrak kuliah|\blc\b).{0,45}(?:belum\s+lengkap|tidak\s+lengkap)' => 'Dokumen perencanaan pembelajaran belum lengkap.',
                    'belum ada pengesahan|belum ada tanda tangan|(?:rps|kontrak kuliah|\blc\b).{0,40}(?:belum|tidak).{0,20}(?:disahkan|ditandatangani)' => 'Pengesahan dokumen pembelajaran belum lengkap.',
                ],
                'suggestion' => [
                    '(?:spada|lms)' => 'Disarankan untuk melengkapi atau memperbarui konten pembelajaran pada SPADA.',
                    '\brps\b' => 'Disarankan untuk mengunggah Rencana Pembelajaran Semester (RPS).',
                    'kontrak kuliah|\blc\b' => 'Disarankan untuk mengunggah kontrak perkuliahan.',
                ],
            ],
            'video' => [
                'improvement' => [
                    '(?:tidak ada|belum ada|belum terdapat|belum tersedia).{0,30}video|video.{0,40}(?:belum\s+(?:ada|tersedia|dibuat)|tidak\s+(?:ada|tersedia))' => 'Video pembelajaran belum tersedia.',
                    'video.{0,40}belum\s+(?:diunggah|diupload|ditautkan)' => 'Video pembelajaran belum diunggah atau ditautkan.',
                    'video.{0,40}(?:terbatas|sedikit|hanya\s+(?:ada\s+)?[12]\b)' => 'Jumlah video pembelajaran yang tersedia masih terbatas.',
                    '(?:bukan|tidak sesuai).{0,30}(?:mata\s*kuliah|matakuliah)|video.{0,40}tidak\s+sesuai' => 'Video pembelajaran belum sesuai dengan mata kuliah.',
                    'video.{0,25}(?:orang lain|channel lain)' => 'Terdapat penggunaan video pembelajaran dari kanal pihak lain.',
                ],
                'suggestion' => [
                    '(?:menautkan|ditautkan|diunggah)' => 'Disarankan untuk mengunggah atau menautkan video pembelajaran.',
                    '(?:tambah|tambahkan|menambah|penambahan|memperbanyak|perbanyak|menyiapkan|membuat|shooting|diperkaya|ditingkatkan)' => 'Disarankan untuk menambah atau mengembangkan video pembelajaran.',
                ],
            ],
            'classroom' => [
                'improvement' => [
                    '(?:mahasiswa|siswa).{0,60}(?:bermain|sibuk|tidak fokus|tidak memperhatikan)' => 'Perhatian sebagian mahasiswa belum terpusat pada kegiatan pembelajaran.',
                    '(?:mahasiswa|siswa).{0,60}(?:tertidur|tidur)' => 'Terdapat mahasiswa yang tertidur saat pembelajaran.',
                    '(?:mahasiswa|siswa).{0,60}pasif|interaksi.{0,30}(?:kurang|belum)' => 'Partisipasi atau interaksi mahasiswa dalam pembelajaran masih terbatas.',
                    'masih mahasiswa yang sama' => 'Partisipasi dalam diskusi masih didominasi oleh mahasiswa yang sama.',
                    '(?:pengelolaan kelas|manajemen kelas).{0,30}(?:kurang|belum)|(?:kurang|tidak).{0,25}(?:kontrol|terkontrol)' => 'Pengawasan dan pengelolaan kelas belum optimal.',
                    '(?:hanya|terus|tidak beranjak).{0,20}(?:duduk|tempat duduk)' => 'Mobilitas dosen untuk menjangkau mahasiswa selama pembelajaran masih terbatas.',
                    '(?:barisan|baris|kursi).{0,15}depan.{0,15}kosong' => 'Sebagian kursi di bagian depan kelas tidak ditempati.',
                ],
                'suggestion' => [
                    'berkeliling|menjangkau|menghampiri|berdiri|tidak hanya duduk' => 'Disarankan agar dosen lebih aktif menjangkau mahasiswa di seluruh bagian kelas.',
                    'diskusi kelompok kecil' => 'Disarankan untuk menerapkan diskusi dalam kelompok kecil.',
                    'kasih giliran' => 'Disarankan untuk memberikan kesempatan berpartisipasi secara bergiliran.',
                    '(?:meningkatkan|ditingkatkan|perkuat|memperkuat|mengoptimalkan|dibangun|diperkuat).{0,45}(?:interaksi|partisipasi)|(?:interaksi|partisipasi).{0,35}(?:ditingkatkan|dibangun|diperkuat)' => 'Disarankan untuk meningkatkan interaksi dan partisipasi mahasiswa.',
                    'mengontrol|mengawasi|mengarahkan|mahasiswa.{0,25}(?:diarahkan|diminta)|menempati|manajemen kelas|pengelolaan kelas' => 'Disarankan untuk memperkuat pengawasan dan pengaturan kelas.',
                ],
            ],
            'time' => [
                'improvement' => [
                    'terlambat' => 'Terdapat keterlambatan kehadiran atau dimulainya pembelajaran.',
                    '(?:selesai|menutup|berakhir).{0,30}(?:sebelum|lebih cepat)' => 'Pembelajaran berakhir sebelum alokasi waktu terpenuhi.',
                    '(?:dimulai|mulai).{0,20}(?:15|20|30) menit|(?:durasi|alokasi waktu).{0,25}(?:tidak penuh|kurang)|(?:melebihi|tidak sesuai).{0,25}waktu' => 'Durasi atau waktu pelaksanaan pembelajaran belum sesuai dengan alokasi yang ditetapkan.',
                    'dosen.{0,30}(?:tidak mengajar|tidak masuk|ketidak\s*hadiran)' => 'Terdapat catatan mengenai ketidakhadiran dosen dalam pembelajaran.',
                ],
                'suggestion' => [
                    '(?:hadir|datang|kuliah|kelas).{0,25}tepat waktu|disiplin.{0,15}waktu' => 'Disarankan untuk meningkatkan ketepatan waktu kehadiran dan pelaksanaan pembelajaran.',
                    'memanfaatkan|mengoptimalkan|memaksimalkan|menyesuaikan' => 'Disarankan untuk mengoptimalkan penggunaan waktu pembelajaran.',
                ],
            ],
            'english' => [
                'improvement' => [
                    'bahasa indonesia|bahasa inggris|english' => 'Penggunaan bahasa Inggris pada bahan ajar atau instruksi pembelajaran belum terpenuhi.',
                ],
                'suggestion' => [
                    'bilingual' => 'Disarankan untuk menggunakan bahan ajar atau instruksi pembelajaran dalam dua bahasa.',
                    'bahasa inggris|english' => 'Disarankan untuk menggunakan bahasa Inggris pada bahan ajar atau instruksi pembelajaran.',
                ],
            ],
            'structure' => [
                'improvement' => [
                    '(?:tidak ada|belum ada|belum adanya|tidak terdapat).{0,35}(?:pembukaan|salam)' => 'Pembukaan pembelajaran belum dilaksanakan atau dicatat.',
                    '(?:tidak ada|belum ada|belum adanya|tidak terdapat).{0,35}(?:rangkuman|simpulan|review)|pembelajaran.{0,25}belum.{0,30}simpulan' => 'Rangkuman atau simpulan pembelajaran belum disampaikan.',
                    'pemahaman.{0,25}belum.{0,30}diperiksa' => 'Pemahaman mahasiswa belum diperiksa pada akhir pembelajaran.',
                ],
                'suggestion' => [
                    'tujuan|capaian' => 'Disarankan untuk memperjelas penyampaian tujuan atau capaian pembelajaran.',
                    'penutup|rangkuman|simpulan|menutup|menyimpulkan' => 'Disarankan untuk memperkuat rangkuman atau refleksi pada penutupan pembelajaran.',
                    'pertemuan berikutnya|pertemuan selanjutnya' => 'Disarankan untuk menyampaikan informasi mengenai pertemuan berikutnya.',
                ],
            ],
            'references' => [
                'improvement' => [
                    'referensi|references|acuan|sitasi' => 'Referensi atau acuan pembelajaran belum dicantumkan atau diperbarui.',
                ],
                'suggestion' => [
                    'referensi|references|sitasi|acuan' => 'Disarankan untuk melengkapi atau memperbarui referensi pembelajaran.',
                    'contoh|studi kasus|dunia kerja|dunia nyata|dudi|industri' => 'Disarankan untuk menambah contoh penerapan atau studi kasus yang relevan.',
                ],
            ],
            'online' => [
                'improvement' => [
                    '(?:belum|tidak).{0,30}(?:forum|diskusi)|forum.{0,30}(?:belum|tidak)' => 'Forum diskusi daring belum tersedia atau belum dimanfaatkan.',
                    '(?:belum|tidak).{0,30}(?:sinkron|virtual|zoom)|(?:sinkron|synchronous|virtual|zoom).{0,30}(?:belum|tidak|hanya)' => 'Pelaksanaan atau bukti pertemuan daring sinkron belum memadai.',
                    '(?:belum|tidak).{0,30}(?:pre-test|post-test)' => 'Evaluasi sebelum atau sesudah pembelajaran daring belum dilaksanakan.',
                    'post-test dan tugas masih digabung' => 'Evaluasi setelah pembelajaran dan tugas belum dipisahkan.',
                ],
                'suggestion' => [
                    'forum|diskusi' => 'Disarankan untuk memanfaatkan forum diskusi daring dan memberikan tanggapan kepada mahasiswa.',
                    'sinkron|virtual|zoom' => 'Disarankan untuk melengkapi pelaksanaan atau dokumentasi pertemuan daring sinkron.',
                    'rubrik' => 'Disarankan untuk menyediakan rubrik penilaian pada kegiatan daring.',
                    'post-test' => 'Disarankan untuk memperjelas pelaksanaan evaluasi setelah pembelajaran daring.',
                ],
            ],
        ];
        $statements = [];
        foreach ($rules[$category][$status] ?? [] as $pattern => $statement) {
            if ($this->matches($pattern, $text)) {
                $statements[] = $statement;
            }
        }

        return array_values(array_unique($statements));
    }

    /**
     * Keep original fragment wording; headings only provide context.
     *
     * @return array<int, array{text: string, context: string|null}>
     */
    private function fragments(string $text): array
    {
        $parts = preg_split('/(\(\+\)|\(-\)|(?:kelebihan(?: dosen mengajar)?|hal baik(?: yang ditemukan)?|point positif)\s*:|(?:kekurangan(?: dosen mengajar)?|kelemahan(?: dosen mengajar)?|kritik|saran(?: perbaikan)?|yang perlu ditingkatkan|hal yang dapat ditingkatkan)\s*:)/iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $context = null;
        $fragments = [];
        foreach ($parts as $part) {
            if ($this->matches('^(?:\(\+\)|(?:kelebihan(?: dosen mengajar)?|hal baik(?: yang ditemukan)?|point positif)\s*:)$', trim($part))) {
                $context = 'positive';

                continue;
            }
            if ($this->matches('^(?:\(-\)|(?:kekurangan(?: dosen mengajar)?|kelemahan(?: dosen mengajar)?|kritik)\s*:)$', trim($part))) {
                $context = 'improvement';

                continue;
            }
            if ($this->matches('^(?:saran(?: perbaikan)?|yang perlu ditingkatkan|hal yang dapat ditingkatkan)\s*:$', trim($part))) {
                $context = 'suggestion';

                continue;
            }
            $sentences = preg_split('/(?:[;\r\n]+|(?<=[.!?])\s+|\s+(?:namun|tetapi|tapi|akan tetapi|hanya saja)\s+|,\s*(?=(?:namun|tetapi|tapi)\b)|\s+(?=\d+[.)]\s+))/iu', $part) ?: [];
            foreach ($sentences as $sentence) {
                $sentence = trim(preg_replace('/^(?:\s*[-\x{2022}]\s*|\s*\d+[.)]\s*|\s*(?:namun|tetapi|tapi)\s*,?\s*)/iu', '', $sentence) ?? $sentence);
                if ($sentence === '' || $sentence === '-' || $sentence === '--') {
                    continue;
                }
                $fragments[] = ['text' => $sentence, 'context' => $context];
            }
        }

        return $fragments;
    }

    private function matches(string $pattern, string $text): bool
    {
        return preg_match('~'.$pattern.'~iu', $text) === 1;
    }
}
