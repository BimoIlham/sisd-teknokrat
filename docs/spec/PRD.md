# PRD — Sistem Supervisi Mutu Perkuliahan

Versi dokumen: **2.0 — spesifikasi implementasi** · Tanggal: **22 September 2026**  
Pemilik proses: **Lembaga Penjaminan Mutu (LPM)** · Institusi: **Universitas Teknokrat Indonesia**  
Jenis produk: web internal · Bahasa antarmuka: Indonesia · Zona waktu awal: `Asia/Jakarta`.

## 1. Tujuan dan batas dokumen

Mendigitalisasi supervisi mutu perkuliahan: import PDF jadwal, pemilihan supervisor, penugasan berbasis constraint, koreksi manual, jadwal personal, instrumen Offline/Online, bukti, penilaian, serta analisis Tahap 1 dan Tahap 2.

Dokumen ini menyempurnakan lima dokumen asal. Konsep produk, dua role, teknologi utama, identitas visual maroon, dan warna kuning untuk supervisi dipertahankan. Penjelasan teknis yang ditambahkan merupakan keputusan implementasi; keputusan akademik yang belum disahkan tetap diberi status terbuka.

Paket asal hanya berisi lima Markdown. **Teks asli instrumen, label opsi tiap pertanyaan, contoh PDF jadwal, dan referensi visual LPM tidak ada dalam paket tersebut.** Jumlah 29/20 berasal dari spesifikasi awal dan harus dicocokkan dengan instrumen sumber sebelum publikasi. Jangan mengarang materi yang hilang.

### Otoritas dokumen

| Dokumen | Tanggung jawab |
|---|---|
| `PRD.md` | Scope, aturan bisnis, keputusan terbuka, penerimaan produk |
| `schema.md` | Struktur data, tipe, hubungan, constraint dan histori |
| `Arsitektur.md` | Komponen, kontrak layanan, transaksi, keamanan dan operasi |
| `Desain.md` | Identitas visual, halaman, interaksi, aksesibilitas dan PDF |
| `MVP.md` | Prioritas, urutan delivery, skenario uji dan kriteria rilis |

Instruksi eksplisit terbaru pemilik produk mengungguli baseline ini. Konflik antardokumen harus dicatat dan diselaraskan; jangan diam-diam menghilangkan requirement. Prompt implementasi mengatur cara kerja agent, bukan mengganti kebijakan akademik.

## 2. Scope dan batas MVP

**P0:** seluruh alur operasional inti, akses privat, audit tindakan kritis, import dengan review, scheduler, manual override, finalisasi, PDF jadwal supervisor, form dinamis, draft, bukti, scoring versioned, dashboard dasar dan perbandingan T1/T2.

**P1:** export Excel, PDF laporan hasil, bulk PDF, analisis temuan terstruktur, filter lanjutan, OCR otomatis ketika dibutuhkan format scan, serta alur amandemen hasil yang sudah dikerjakan.

**P2:** email/WhatsApp, SSO, aplikasi mobile, rekomendasi AI, tanda tangan digital dan portal publik. Tidak termasuk chatbot, integrasi Drive, pembayaran, atau stack pengganti yang tidak diminta.

MVP teknis dapat diuji memakai data demo berlabel. **MVP siap operasional** memerlukan instrumen dan kebijakan resmi; kedua status ini tidak boleh disamakan.

## 3. Pengguna dan otorisasi — REQ-AUTH

| Kemampuan | Superadmin/LPM | Supervisor |
|---|---|---|
| Mengelola akun, term, periode dan tahap | Ya | Profil sendiri saja |
| Import, koreksi dan commit jadwal | Ya | Tidak |
| Memilih target dan supervisor per periode | Ya | Tidak |
| Generate, override, lock dan finalisasi penugasan | Ya | Tidak |
| Mengelola/publish instrumen dan kebijakan | Ya, dengan audit | Tidak |
| Mengisi formulir | Tidak menyamar sebagai supervisor | Tugas final miliknya pada periode aktif |
| Membaca hasil dan bukti | Sesuai lingkup institusi | Hasil tugas yang ia kerjakan sendiri |
| Unduh PDF jadwal | Semua supervisor | PDF miliknya |
| Analitik institusi | Ya | Tidak |

- Role tersimpan sebagai `superadmin` dan `supervisor`; menu tersembunyi bukan pengamanan.
- Dosen dan supervisor berasal dari **master dosen yang sama**. Supervisor ditetapkan **per periode**, tanpa batas 13 orang.
- Seorang dosen dapat mengajar, menjadi target, dan menjadi supervisor dosen lain.
- Akun supervisor harus terhubung ke satu identitas dosen. Role saja tidak memberi akses ke semua periode/tugas.
- Tidak ada registrasi publik. LPM membuat/menghubungkan akun menggunakan email yang telah diverifikasi identitasnya, bukan email tebakan dari PDF. Sediakan password sementara acak dan wajib ganti; jangan bergantung pada email untuk MVP.
- Akun nonaktif tidak dapat login. Mantan supervisor yang akunnya masih aktif dapat membaca riwayat tugasnya; tidak otomatis mendapat hak mengubah tugas periode lain.

## 4. Waktu, ruang lingkup dan data master — REQ-TIME

1. `academic_terms`: semester `GANJIL/GENAP`, tahun akademik, tanggal awal/akhir dan zona waktu.
2. Periode berada dalam satu term. Minimal Tahap 1 dan Tahap 2 mempunyai rentang tanggal eksplisit di dalam periode.
3. Jadwal mengajar adalah pola **mingguan**. Scheduler memilih **pertemuan bertanggal** (`teaching_occurrences`), bukan sekadar nama hari.
4. Hari menggunakan ISO: Senin=1 sampai Minggu=7. Tanggal/jam antarmuka dalam zona waktu term; timestamp pertemuan dan audit disimpan UTC.
5. Pertemuan dihasilkan dari pola, masa berlaku dan kalender tanggal tidak mengajar. LPM dapat membatalkan/menggeser pertemuan dengan audit. Perubahan yang memengaruhi penugasan harus divalidasi ulang.
6. Interval waktu adalah `[mulai, selesai)`. Pukul 09:00–10:00 tidak bentrok dengan 10:00–11:00 apabila buffer=0.
7. P0 menolak interval lintas tengah malam untuk review, bukan menebak tanggal selesai.
8. Fakultas/prodi perkuliahan berasal dari jadwal kelas. Afiliasi asal dosen boleh berbeda dan tidak menggantikan identitas kelas.
9. Target dipilih eksplisit per periode; default usulan adalah seluruh dosen aktif dengan jadwal valid. Dosen yang dikecualikan memerlukan alasan dan audit.
10. Baseline implementasi: **satu supervisi per target per tahap**. Kebutuhan multi-observasi memerlukan perluasan model, bukan penugasan ganda tanpa aturan.

## 5. Import PDF — REQ-IMPORT

Alur: unggah → validasi → proses background → normalisasi → preview → koreksi dan pencocokan identitas → persetujuan baris → commit atomik.

Data minimal jadwal yang sudah layak dijadwalkan: dosen teridentifikasi, fakultas, prodi, term, hari, jam mulai/selesai, mata kuliah, kelas, metode, dan masa berlaku. Ruang wajib untuk Offline; Online dapat memakai label platform/lokasi yang dikonfirmasi. Nama ruang/link yang hilang tidak dibuat otomatis.

Ketentuan:

- Mendukung unggah satu atau beberapa PDF, hasil dipisah per file dan halaman.
- Simpan file asli privat, SHA-256, versi parser, sumber halaman/baris dan payload asli.
- PDF bertulisan diproses dengan adapter layout; format tidak dikenal masuk review, bukan dianggap berhasil kosong.
- Scan tanpa teks diberi `OCR_REQUIRED`. P0 menyediakan koreksi/input baris manual atau meminta PDF bertulisan. OCR otomatis adalah P1 kecuali contoh input operasional mengharuskannya; jika demikian dukungan tersebut menjadi gate penerimaan parser.
- Password protection, file rusak, ukuran berlebih, timeout dan parser gagal memiliki pesan serta tindakan pemulihan yang jelas.
- Kolom tidak diketahui disimpan `null` dan ditandai. Confidence hanya indikator parser, bukan jaminan kebenaran.
- Kode dosen resmi dapat menjadi identitas unik. Nama ternormalisasi hanya membantu pencarian. Dua dosen bernama sama tidak boleh otomatis digabung; beda gelar/typo diberi kandidat pencocokan.
- Duplikat dikenali melalui hash file, fingerprint jadwal dan mapping identitas. Unggah/commit ulang tidak boleh menggandakan master atau pertemuan.
- Perubahan pada hasil review mereset persetujuan baris tersebut. Baris yang dilewati wajib beralasan; READY berarti semua baris telah diputuskan.
- Commit tidak membuat akun login otomatis. Perubahan jadwal yang sudah dirujuk penugasan final tidak boleh menimpa histori.

## 6. Penjadwalan dan constraint — REQ-SCHED

Kandidat adalah `(target, supervisor, pertemuan bertanggal)` di dalam tahap. Durasi supervisi baseline mengikuti seluruh interval kelas target. Kebijakan durasi parsial belum diminta.

### Hard constraints yang selalu berlaku

| Kode | Aturan |
|---|---|
| H01 | Supervisor berbeda dari target dan terpilih aktif pada periode |
| H02 | Target termasuk cakupan periode; pertemuannya valid, aktif dan berada dalam tahap |
| H03 | Supervisor tidak mengajar pada interval supervisi beserta buffer yang berlaku |
| H04 | Supervisor tidak memiliki supervisi lain yang tumpang tindih, termasuk dari periode/tahap lain |
| H05 | Supervisor dengan **3 atau lebih pertemuan mengajar** pada tanggal tersebut tidak menerima supervisi |
| H06 | Penugasan MANUAL/LOCKED dan penugasan FINAL tidak ditimpa scheduler |
| H07 | Maksimal satu penugasan aktif per target per tahap |
| H08 | Kandidat mematuhi kebijakan scheduler yang sudah dipilih dan semua referensi data masih terkini |

H05 memperjelas frasa awal “memiliki 3 jadwal” menjadi ambang `>=3`, agar empat jadwal tidak justru diperbolehkan. Hitung pertemuan berbeda setelah deduplikasi, bukan jumlah baris PDF. Baseline ini ditampilkan kepada LPM untuk pengesahan operasional.

### Aturan yang dapat dikonfigurasi

- `buffer_minutes`: jarak minimum untuk pasangan aktivitas yang melibatkan supervisi, dihitung satu kali sebagai gap; default demo 0. Buffer tidak digunakan untuk menyatakan dua kelas mengajar existing tidak sah.
- `max_supervisions_per_day`, `max_supervisions_per_week`: `null` berarti tidak dibatasi tambahan; minggu Senin–Minggu lokal.
- `max_daily_span_minutes`: rentang dari awal aktivitas pertama hingga akhir aktivitas terakhir pada tanggal yang sama, termasuk jeda. **480 menit adalah usulan, belum kebijakan resmi.** Default demo `null`.
- Rentang harian dihitung atas mengajar + supervisi. Jika jadwal mengajar sendiri sudah melanggar ambang, beri diagnosis dan jangan menambah supervisi pada hari itu; jangan memindahkan kuliah.
- `cross_unit_policy`: `ANY`, `SAME_FACULTY`, atau `SAME_STUDY_PROGRAM`; demo `ANY`. Afiliasi supervisor menggunakan master dosen, afiliasi target menggunakan kelas yang diamati.
- `stage_supervisor_policy`: `ANY`, `SAME`, atau `DIFFERENT`; demo `ANY`.
- `min_stage_gap_days`: hari kalender antara tanggal observasi T1 dan T2; demo 0, tetapi T2 harus sesudah T1. Pada semua pilihan kebijakan, T2 memerlukan T1 target yang sudah terjadwal bertanggal; jika belum ada, tampilkan alasan belum terjadwal. T1 tidak harus submitted untuk menyusun T2.

Aturan tersimpan sebagai versi immutable setelah disahkan. Tidak ada ambang tersembunyi di kode.

### Objective dan hasil solver

Urutan optimasi: (1) memaksimalkan target terjadwal valid, (2) menyeimbangkan jumlah beban supervisor, (3) meminimalkan perubahan penugasan AUTO sebelumnya. Hard constraint tidak pernah dikorbankan demi pemerataan.

Solver boleh menghasilkan cakupan parsial. Tampilkan target belum terjadwal dan diagnosis: tidak ada kelas valid, tidak ada supervisor bebas, batas harian, terkunci, atau batas waktu solver. `UNKNOWN` tidak berarti terbukti tidak ada solusi.

Hasil generate adalah proposal. LPM memeriksa lalu menerapkan proposal pada DRAFT. Snapshot yang kedaluwarsa ditolak, bukan menimpa perubahan baru. Jadwal lama tetap utuh apabila solver gagal atau hasil tidak diterapkan.

## 7. Override, penguncian dan finalisasi — REQ-ASSIGN

1. LPM memilih kandidat manual, melihat validasi dan daftar AUTO yang terdampak, memberi alasan, lalu menyimpan.
2. MANUAL selalu `is_locked=true`. Prioritas manual berlaku terhadap AUTO DRAFT yang tidak terkunci; **bukan izin melanggar hard constraint**.
3. Bentrok dengan mengajar, MANUAL lain, LOCKED lain atau FINAL: tolak perubahan dan jelaskan sebab. Tidak ada tombol paksa finalisasi konflik pada P0.
4. Jika kandidat valid kecuali bertabrakan dengan AUTO DRAFT, batalkan AUTO terdampak dalam transaksi yang sama, simpan manual, audit, dan jalankan kalkulasi ulang. Target yang belum memperoleh pengganti tetap terlihat.
5. Unlock manual merupakan tindakan eksplisit LPM yang mengubahnya menjadi AUTO, beralasan dan diaudit. Scheduler tidak boleh melakukannya sendiri.
6. Finalisasi per tahap membutuhkan semua target INCLUDED terjadwal tepat sekali, semua assignment VALID, tidak ada proses apply berjalan, akun supervisor siap, kebijakan disahkan, dan instrumen kedua metode yang dipakai sudah published.
7. Penugasan FINAL muncul sebagai tugas supervisor. PDF final mempunyai nomor revisi dan timestamp.
8. Buka ulang tahap memerlukan alasan. P0 hanya membolehkan jika semua formulir masih `ASSIGNED`/belum diisi. Bila ada `DRAFT` atau `SUBMITTED`, perubahan harus melalui fitur amandemen P1; tidak boleh menghapus pekerjaan/histori.
9. Sesudah buka ulang, tugas tidak dapat diedit supervisor sampai final kembali. PDF lama ditandai kedaluwarsa. Semua perubahan sumber yang memengaruhi reservasi waktu memakai penguncian transaksi yang sama.
10. Finalisasi T2 membutuhkan assignment T1 pasangannya sudah FINAL dan aturan lintas tahap valid. Buka ulang T1 ditolak bila masih ada T2 FINAL yang bergantung padanya; buka ulang T2 terlebih dahulu jika syarat buka ulang memungkinkan.
11. Aturan/cakupan yang berubah tidak boleh mengubah penugasan FINAL secara diam-diam. Perubahan rules pada tahap DRAFT menandai assignment terdampak untuk validasi ulang; penugasan manual tetap pada slotnya sampai LPM menyelesaikan konflik.

## 8. Instrumen dinamis dan formulir — REQ-FORM

Instrumen dibaca dari database: template → section → question → options. Jenis pertanyaan: `SCORE`, `YES_NO`, `LINK`, `FILE`, `TEXTAREA`.

### Offline

Target spesifikasi asal: **29 item bernilai**, pilihan angka sumber `0,1,2,3,4`.

Bagian: Pembukaan Pembelajaran; Penguasaan Materi Pembelajaran; Kegiatan Evaluasi; Metodologi Pembelajaran; Penggunaan Media Pembelajaran; Manajemen Kelas; Sikap Dosen Dalam Pembelajaran; Refleksi dan Menyimpulkan Materi; Upload Bukti & Evaluasi.

Elemen nonnilai sumber: Ya/Tidak slide/instruksi Bahasa Inggris di SPADA, link/foto bukti SPADA, link video pembelajaran, Ya/Tidak penggunaan Bahasa Inggris pada prodi terkait, bukti gambar, evaluasi/kritik/saran. Ketentuan AND/OR antara link dan foto serta prodi pemicu wajib mengikuti formulir resmi; belum dapat disimpulkan dari ringkasan ini.

### Online

Target spesifikasi asal: **20 item bernilai**.

Bagian: Perencanaan; Proses Pembelajaran/Asinkronus; Evaluasi; Upload Bukti & Evaluasi.

Elemen nonnilai: link pembuktian slide Bahasa Inggris, Ya/Tidak penggunaan Bahasa Inggris, upload bukti, evaluasi/kritik/saran. Mapping label/angka yang tidak konsisten pada sumber harus diputuskan LPM, bukan diperbaiki menurut tebakan agent.

### Aturan bersama

- Identitas tugas memuat dosen target, tanggal, jam, fakultas, prodi, kelas, mata kuliah, metode dan materi.
- Fakultas/prodi read-only dari snapshot kelas. Tanggal/jam mengikuti assignment; perubahan pelaksanaan membutuhkan penjadwalan ulang oleh LPM.
- Mata kuliah default dari jadwal. Koreksi nama manual diperbolehkan dengan alasan dan menyimpan nama asal; bukan cara mengganti kelas, target, waktu atau metode tanpa validasi.
- Materi diisi supervisor dan wajib saat submit. Evaluasi/kritik/saran disimpan satu kali melalui jawaban TEXTAREA, bukan dua sumber data berbeda.
- Setiap question memiliki `code`, `is_required`, `is_scored`, `sort_order`, dan aturan kondisi tervalidasi. Pada P0 semua item bernilai wajib dan selalu aktif; kondisi digunakan pada elemen nonnilai sesuai sumber.
- Pilihan `0` dan jawaban `Tidak/false` adalah jawaban valid, tidak boleh dianggap kosong.
- Required pada pertanyaan yang kondisinya tidak aktif tidak menghalangi submit. Kondisi tidak boleh menggunakan kode yang dieksekusi bebas atau membentuk siklus.
- Draft boleh belum lengkap; simpan eksplisit serta autosave yang menampilkan waktu hasil simpan server. Gagal jaringan tidak boleh memberi notifikasi “tersimpan”.
- Submit memvalidasi server-side: kepemilikan, stage FINALIZED, periode ACTIVE, template, semua jawaban aktif, bukti dan policy. Double-click/retry tidak menggandakan hasil.
- Form published dan hasil submitted immutable. Perubahan instrumen membuat versi baru. Perhitungan lama tidak dijalankan ulang otomatis.
- Demo menggunakan kode `DEMO_*` dan banner “Simulasi — bukan instrumen resmi”; tidak boleh menjadi data laporan operasional.

## 9. Scoring — REQ-SCORE

Hanya jawaban `is_scored=true` yang dihitung. Skor opsi diambil server dari versi instrumen; angka kiriman browser tidak dipercaya. Tidak menambahkan bobot atau kategori kelulusan yang tidak ada pada sumber.

`S = jumlah skor valid`, `N = jumlah pertanyaan bernilai aktif`. Pada instrumen baseline, N adalah 29 atau 20 setelah instrumen dikonfirmasi. `N=0` adalah error; kosong tidak diperlakukan sebagai nol.

| Strategy tersedia | Formula | Rentang bila skor sumber 0–4 |
|---|---|---|
| `MEAN_0_4` | `S / N` | 0–4 |
| `LINEAR_1_4` | `1 + 3 × S / (4 × N)` | 1–4 |
| `FLOOR_1_4` | `max(1, S / N)` | 1–4; beberapa nilai bawah menyatu |

Ketiganya adalah pilihan implementasi untuk keputusan LPM, bukan formula resmi yang sudah dipilih. Demo boleh menggunakan `MEAN_0_4` berlabel. Produksi membutuhkan policy APPROVED yang menetapkan formula dan pemetaan opsi, termasuk perlakuan nol.

Simpan `raw_score`, `scored_question_count`, `final_score` presisi 6 desimal, ID/versi policy, template, dan snapshot jawaban/identitas. Tampilan 2 desimal, pembulatan half-up di lapisan tampilan. Tidak membulatkan setiap jawaban sebelum agregasi. Tanpa batas kategori resmi, tampilkan angka tanpa label “Baik/Sangat Baik” hasil tebakan.

## 10. Hasil, analitik dan perbandingan — REQ-ANALYTICS

- Unit hasil: satu target pada satu tahap dalam satu periode; hanya hasil `SUBMITTED` dari assignment yang berlaku masuk statistik resmi.
- Progress keseluruhan = jumlah hasil submitted / (jumlah target INCLUDED × jumlah tahap yang diwajibkan). Target belum terjadwal tetap masuk penyebut. Penyebut nol ditampilkan “Belum ada target”.
- Tampilkan terpisah: belum ditugaskan, tugas final belum diisi, draft dan submitted. Jangan menghitung jumlah assignment saja sebagai seluruh target.
- Rata-rata T1/T2 menggunakan hasil tersedia pada tahap masing-masing, dilengkapi jumlah sampel. Jangan mencampur skala/policy/instrumen tidak sebanding dalam satu rerata tanpa label pemisahan.
- Pasangan T1/T2 dicocokkan menggunakan `period_id + lecturer_id`, bukan nama atau nomor baris.
- Delta dosen = nilai T2 − T1; default hanya comparable bila metode, template versi dan policy sama. Versi berbeda/jenis metode berbeda ditampilkan “Tidak dapat dibandingkan” sampai tersedia kebijakan penyetaraan resmi.
- **Rata-rata perubahan** = rerata delta pada dosen yang memiliki dua hasil comparable. Bukan selisih rerata dua kelompok yang beranggotakan dosen berbeda.
- Data belum ada ditampilkan `—`, bukan 0. T1 saja, T2 saja, nol asli, naik, turun dan tetap harus dibedakan. Tren menggunakan selisih presisi tersimpan, bukan angka tampilan yang sudah dibulatkan.
- Untuk delta per fakultas/prodi, pasangan harus berada pada unit kelas yang sama di kedua tahap; perubahan unit ditandai dan tidak dicampur ke delta unit.
- Analisis “evaluasi supervisor” berarti beban/penyelesaian tugas dan catatan relevan, bukan penilaian kinerja supervisor dengan formula buatan.
- Temuan/kritik/saran dasar dapat dibaca dan difilter. Pengelompokan otomatis berbasis AI bukan P0.

## 11. PDF dan laporan — REQ-PDF

PDF jadwal personal dihasilkan dari database snapshot, bukan mengedit PDF input. Memuat identitas institusi, fakultas sesuai cakupan, nama supervisor, term, tahap, rentang tanggal, nomor revisi dan legenda.

Jadwal mengajar putih; slot kosong abu-abu; supervisi kuning. Kartu supervisi memuat tanggal, jam, target, kelas, mata kuliah, ruang/platform dan metode. Warna disertai label teks.

Gunakan landscape dengan halaman **per minggu kalender** pada rentang tahap, sehingga assignment satu tanggal tidak terlihat seolah berulang setiap minggu. Hari Minggu muncul bila ada aktivitas. Header tabel diulang saat pergantian halaman, teks tidak terpotong, font tidak diperkecil tanpa batas. Ukuran awal A4 landscape; sumber tidak menetapkan ukuran lain.

Semua output privat, aksesnya mengikuti policy. Dokumen lama memiliki revisi dan status kedaluwarsa; jangan menyediakan file versi lama sebagai jadwal final terbaru. Export hasil PDF/Excel adalah P1 dan memakai definisi statistik yang sama.

## 12. Status kanonis — REQ-STATE

| Entitas | Status |
|---|---|
| Import | `UPLOADED`, `PROCESSING`, `NEEDS_REVIEW`, `READY`, `COMMITTING`, `COMMITTED`, `FAILED` |
| Baris import | `VALID`, `INVALID`, `AMBIGUOUS`, `SKIPPED` |
| Periode | `DRAFT`, `ACTIVE`, `CLOSED` |
| Tahap | `DRAFT`, `FINALIZED`, `CLOSED` |
| Penugasan | `DRAFT`, `FINAL`, `CANCELLED` |
| Konflik penugasan | `VALID`, `CONFLICT`, `NEEDS_RECALCULATION` |
| Form supervisi | `ASSIGNED`, `DRAFT`, `SUBMITTED` |
| Template | `DRAFT`, `PUBLISHED`, `RETIRED` |
| Policy scoring/rules | `DRAFT`, `APPROVED`, `RETIRED` |
| Schedule run | `QUEUED`, `RUNNING`, `COMPLETED`, `FAILED`, `STALE`, `APPLIED` |
| Dokumen | `QUEUED`, `PROCESSING`, `READY`, `FAILED`, `STALE` |

`IN_PROGRESS` form pada spesifikasi lama digabung menjadi `DRAFT`, agar tidak ada dua status dengan arti sama. Status solver (`OPTIMAL` dll.) adalah atribut terpisah dari status job/run.

Transisi penting: import READY → COMMITTING → COMMITTED; assignment DRAFT → FINAL atau CANCELLED; form ASSIGNED → DRAFT → SUBMITTED. Membuka/melihat form saja tidak membuat jawaban atau nilai. Kegagalan commit me-rollback seluruh perubahan database dan mencatat error yang dapat dicoba ulang.

## 13. Nonfungsional — REQ-NFR

- Session auth, RBAC dan resource policy server-side; CSRF dan pembatasan percobaan login.
- File privat, batas MIME/ukuran, validasi isi aktual, nama file acak dan unduhan berotorisasi.
- Parsing, solver dan PDF melalui queue; timeout, retry terbatas dan status kegagalan terlihat.
- Idempotency untuk commit/generate-apply/finalize/submit; optimistic version pada review, override dan draft.
- Audit kritis adalah P0: perubahan cakupan, supervisor, jadwal, rules, instrumen, manual override, finalisasi, buka ulang, submit dan perubahan akun.
- Antarmuka responsif, dapat dioperasikan dengan keyboard, pesan error spesifik, dan warna bukan satu-satunya pembeda.
- Backup database **dan** file privat dengan prosedur restore teruji; secret tidak dimasukkan repository/log.
- Target uji awal, bukan hasil benchmark: 300 dosen, 30 supervisor, 2.000 pola jadwal, 2 tahap; layar paginasi p95 ≤2 detik di lingkungan yang dicatat. Solver batas waktu awal 60 detik, menampilkan hasil feasible/parsial secara jujur. Target diukur dan disesuaikan berdasarkan data institusi.

## 14. Keputusan terbuka dan gate operasional

| ID | Yang belum tersedia/disahkan | Sambil menunggu | Gate yang tertahan |
|---|---|---|---|
| D01 | Teks lengkap instrumen, opsi, kondisi dan bukti Offline/Online | Bangun engine dan fixture DEMO | Publish instrumen resmi dan penggunaan operasional |
| D02 | Formula nilai, perlakuan 0 dan mapping opsi | Strategy versioned, preview perbandingan | Scoring/hasil resmi |
| D03 | Aktivasi/definisi rentang 8 jam, buffer | Konfigurasi span di atas; demo dinonaktifkan | Pengesahan rules operasional |
| D04 | Kebijakan supervisor T1/T2, gap, batas harian/mingguan, lintas unit | Demo ANY, gap 0, limit null; T2 setelah T1 | Pengesahan rules operasional |
| D05 | Cakupan dosen, satu observasi per tahap, ambang >=3 | Baseline eksplisit dan UI review | Aktivasi cakupan/rules operasional |
| D06 | Contoh PDF jadwal yang benar-benar dipakai, termasuk scan bila ada | Fixture sintetis dan koreksi manual | Klaim dukungan format institusi |
| D07 | Data akun, kalender akademik, logo resmi dan lingkungan server | Konfigurasi/demo terpisah | Setup operasional terkait |

LPM dapat menyetujui nilai “tanpa batas tambahan”/“aturan dinonaktifkan” secara eksplisit. Tidak wajib membuat batas baru. Persetujuan disimpan dengan pengguna, waktu dan versi; agent tidak boleh mengaku persetujuan sudah diberikan. Ketidaklengkapan ini tidak menghentikan pembangunan modul yang independen.

## 15. Penerimaan produk

Requirement dianggap selesai bila memiliki implementasi dan bukti uji pada `MVP.md`: auth per sumber daya; import dua layout + review + idempotency; penugasan bertanggal valid; manual tidak tertimpa; finalisasi terjaga; PDF kuning terbaca; form lengkap sesuai sumber; draft/bukti privat; submit atomik; nilai reproducible; statistik pasangan benar; serta alur end-to-end dua tahap.

Dokumen ini adalah spesifikasi, **bukan bukti bahwa aplikasinya sudah dibuat atau dites**. Laporan agent wajib membedakan selesai, gagal, belum diuji dan menunggu bahan/keputusan.
