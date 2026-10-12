# Desain UI/UX — Sistem Supervisi Mutu Perkuliahan

Versi **2.0** · 22 September 2026 · Selaras dengan aturan/status di `PRD.md`.

## 1. Arah visual

Profesional, institusional, berfokus pada data, padat tetapi mudah dibaca. Adaptasi karakter LPM sesuai spesifikasi awal; belum ada file referensi visual LPM dalam paket, sehingga jangan mengklaim meniru tampilan pixel-perfect.

Identitas institusi: Universitas Teknokrat Indonesia. Nama aplikasi **Sistem Supervisi Mutu Perkuliahan**. Logo resmi hanya dipakai jika tersedia; gunakan wordmark teks sementara, bukan logo buatan yang diklaim resmi.

Bahasa antarmuka Indonesia. Kode seperti `NEEDS_RECALCULATION`, nama library atau detail solver tidak menjadi label utama pengguna; petakan ke bahasa yang mudah dipahami. Pemberitahuan demo menampilkan “Simulasi — bukan data atau instrumen resmi”.

## 2. Warna dan token

### Palet brand yang dipertahankan

| Nama | Hex | Penggunaan |
|---|---|---|
| Maroon Ancestral | `#3a0808` | Sidebar/surface brand paling gelap |
| Maroon Obsidian | `#520d0d` | Hover/area brand gelap |
| Maroon Deep | `#6b1414` | Aksen kuat |
| Maroon Brand | `#7f1d1d` | Tombol primer dan elemen aktif |
| Maroon Vivid | `#841f1f` | Variasi aksen |
| Maroon Core | `#9a2828` | Penekanan sekunder |
| Maroon Rose | `#b84747` | Aksen dekoratif/garis |
| Maroon Mist | `#e8a5a5` | Surface tint/border lembut |
| Maroon Whisper | `#fbecec` | Background badge aktif |

| Semantik | Warna awal |
|---|---|
| Success | `#16a34a` |
| Warning | `#d97706` |
| Danger | `#dc2626` |
| Caution | `#ea580c` |
| Info | `#3b82f6` |
| Neutral | `#64748b` |

Warna semantik di atas adalah base token, bukan jaminan contrast untuk semua teks. Gunakan foreground/background pair yang diuji; teks kecil pada background terang memakai shade gelap yang memenuhi kontras.

```css
:root {
  --brand: #7f1d1d;
  --page-bg: #f8fafc;
  --surface: #ffffff;
  --text: #0f172a;
  --text-muted: #475569;
  --border: #e2e8f0;
  --schedule-teaching: #ffffff;
  --schedule-empty: #e5e7eb;
  --schedule-supervision: #facc15;
  --schedule-conflict: #fee2e2;
  --schedule-ink: #111827;
}
[data-theme="dark"] {
  --page-bg: #0b1120;
  --surface: #111827;
  --text: #f1f5f9;
  --text-muted: #cbd5e1;
  --border: #334155;
  --schedule-teaching: #1e293b;
  --schedule-empty: #334155;
}
```

Supervisi tetap kuning dengan teks gelap pada kedua tema. Teaching/empty memakai teks surface yang sesuai tema. Konflik merah lembut dengan label/border ikon; MANUAL/LOCKED badge maroon. Print selalu light theme. Warna tidak boleh menjadi satu-satunya pembeda.

## 3. Tipografi, grid dan komponen dasar

Font utama **Plus Jakarta Sans**, self-host jika tersedia. Fallback `'Instrument Sans', ui-sans-serif, system-ui, sans-serif`. Gunakan font yang terpasang/di-embed pada PDF, jangan bergantung pada unduhan font saat cetak.

| Konteks | Ukuran | Weight |
|---|---:|---:|
| Judul halaman | 20 px | 700 |
| Judul section | 15 px | 600 |
| Header tabel | 12 px | 700 |
| Body | 14 px | 400 |
| Sekunder | 13 px | 400/500 |
| Caption | 12 px | 400/500 |
| Angka statistik | 28 px | 700 |
| Judul login | 24 px | 800 |

Sidebar 260 px atau 72 px collapsed; header sticky 64 px. Konten memuat breadcrumb, judul, konteks periode/tahap, aksi utama, data. Padding 16/24/32 px pada mobile/tablet/desktop; jarak section 20–24 px. Card radius 12 px, border halus, padding 20 px. Tombol desktop tinggi visual 36 px radius 8 px; pada mobile area sentuh minimal 44 px.

Komponen reusable: AppShell, PeriodStageSelector, StatCard, DataTable, StatusBadge, EmptyState, InlineAlert, ConfirmDialog, UploadDropzone, ImportReviewRow, ScheduleGrid, AssignmentEditor, FormSection, ScoreField, EvidenceField, DraftStatus dan Pagination.

Satu primary action dominan per konteks. Tabel paginasi server-side, sorting/filter ber-URL query, numeric right-aligned, header sticky dan kolom aksi ringkas. Jangan mengandalkan hover untuk aksi penting. Select menyimpan ID stabil, bukan nama.

## 4. Navigasi dan halaman

| Role | Area/menu | Halaman utama |
|---|---|---|
| LPM | Dashboard | Summary dan progress periode |
| LPM | Jadwal | Upload, Hasil Import, Data Jadwal, Kalender, Jadwal Supervisi |
| LPM | Supervisor | Pilih Supervisor, Target Supervisi, Penugasan, Konflik |
| LPM | Supervisi | Tahap 1, Tahap 2, Hasil Supervisi |
| LPM | Analisis | Ringkasan, Fakultas, Program Studi, Dosen, Temuan |
| LPM | Instrumen | Offline, Online, Versi dan Kebijakan Nilai |
| LPM | Laporan | PDF jadwal; laporan hasil/Excel saat P1 tersedia |
| LPM | Pengaturan | Akun, term, periode, aturan, identitas institusi |
| Supervisor | Dashboard | Tugas dan status miliknya |
| Supervisor | Jadwal Saya | Mengajar dan supervisi bertanggal |
| Supervisor | Tugas Supervisi | Daftar tugas final, isi form |
| Supervisor | Riwayat | Hasil tugas yang ia kerjakan |
| Supervisor | Profil | Identitas dan ganti password |

P1 yang belum dibuat tidak diberi tombol seolah berfungsi. Navigasi adaptif tetap tunduk pada policy server. Filter periode/tahap selalu terlihat pada halaman yang dapat mencampur data.

## 5. Login dan kesiapan akun

Form sederhana, label eksplisit, show/hide password yang accessible, pesan gagal yang tidak membocorkan apakah email terdaftar. Tidak menampilkan self-registration. Login pertama akun sementara diarahkan mengganti password. LPM melihat penanda “Akun belum tersedia” pada pilihan supervisor agar finalisasi tidak gagal tanpa penjelasan.

## 6. Upload dan review import

Tahapan terlihat: **Unggah → Diproses → Periksa data → Simpan jadwal**. Daftar file menampilkan nama, ukuran, status, jumlah baris, isu dan tindakan retry. Progres menunjukkan fase/jumlah file yang benar-benar diketahui; bukan persentase acak.

Preview table: Nama Dosen, Fakultas, Prodi, Hari, Jam, Kelas, Mata Kuliah, Ruang/Platform, Metode, Status. Term/semester dipilih pada konteks import. Tiap row dapat membuka panel PDF halaman sumber dan teks hasil baca bila renderer tersedia.

- Field missing diberi label “Belum terbaca”, tidak diisi dummy.
- Nama ambigu menampilkan kandidat dosen beserta kode/afiliasi dan opsi “Buat identitas baru” yang ditinjau LPM.
- Error pada jam menunjukkan contoh format yang diterima. Skor confidence bersifat sekunder; keputusan review tetap jelas.
- Koreksi mereset approval; “Lewati baris” meminta alasan.
- Tombol “Simpan jadwal” aktif setelah semua baris diputuskan. Ringkasan sebelum commit menyebut dibuat/digunakan ulang/dilewati.
- Commit ulang menampilkan hasil yang sama, bukan sukses menggandakan data.
- Scan: “PDF ini belum memiliki teks yang dapat dibaca. Gunakan PDF bertulisan atau lengkapi baris melalui koreksi manual.” Bila OCR aktif, tampilkan fase OCR secara jujur.

## 7. Scope dan pemilihan supervisor

Search dosen, filter fakultas/prodi, checkbox multi-select, total terpilih tanpa batas 13. Tampilkan jumlah mengajar dan status akun agar pilihan dapat dinilai. Halaman Target Supervisi membedakan INCLUDED/EXCLUDED; excluded memerlukan alasan, tetap terlihat melalui filter audit.

Perubahan pada periode final memberikan penjelasan dampak dan alur yang diperbolehkan, bukan diam-diam memindahkan hasil. Banner keputusan belum disahkan mengarah ke pengaturan rule/instrumen yang tepat.

## 8. Assignment workspace

Desktop dua kolom: daftar target/filter di kiri; detail kandidat, kalender dan validasi di kanan. Mobile menggunakan daftar lalu panel detail stacked. Jangan memaksa tabel jadwal lebar menyusut hingga teks tidak terbaca.

Header memuat periode, tahap, tanggal, status finalisasi dan nomor revisi. Statistik kecil: target, terjadwal, belum terjadwal, konflik, manual terkunci. Aksi: “Susun otomatis”, “Tinjau hasil”, “Terapkan hasil”, “Finalisasi tahap”.

Proposal solver terpisah dari jadwal aktif. Preview menampilkan penugasan baru, berubah, AUTO dibatalkan dan target belum mendapat supervisor. Jika stale, tampilkan “Data jadwal telah berubah. Susun ulang menggunakan data terbaru.”

| Kode | Label pengguna |
|---|---|
| AUTO | Otomatis |
| MANUAL | Manual |
| LOCKED | Dikunci |
| VALID | Valid |
| CONFLICT | Bentrok |
| NEEDS_RECALCULATION | Perlu dihitung ulang |
| DRAFT | Draf |
| FINAL | Final |
| CANCELLED | Dibatalkan |

Detail konflik menyebut supervisor, tanggal, jam dan aktivitas penyebab, misalnya “Supervisor mengajar pada Senin, 5 Oktober, 09.00–10.40” atau “Terdapat 3 pertemuan mengajar pada tanggal ini.”

Manual override menampilkan kandidat tanggal/jam yang nyata, alasan perubahan wajib dan daftar AUTO terdampak sebelum simpan. Konflik hard tidak dapat dipaksa. Jika belum terjadwal, tampilkan penyebab dan langkah relevan: koreksi kelas, ubah supervisor atau tinjau kebijakan. Jangan otomatis melonggarkan rules.

## 9. Finalisasi dan buka ulang

Dialog finalisasi merangkum cakupan tahap, total tugas, kelengkapan supervisor, instrumen dan kebijakan. Setiap blocker dapat diklik ke masalah sumber. Label aksi spesifik “Finalisasi Tahap 1”, bukan “OK”.

Sesudah final: banner “Jadwal final revisi X”; tugas muncul ke supervisor. Buka ulang menampilkan dampak PDF lama dan membutuhkan alasan. Jika sudah ada draft/submission, jelaskan bahwa hasil tidak bisa diubah melalui buka ulang P0; jangan menampilkan tombol yang akan menghapus isian.

## 10. Jadwal personal dan kalender

Default **minggu bertanggal**, dengan kontrol sebelumnya/berikutnya dan pilihan tahap. Grid waktu × hari Senin–Sabtu; Minggu muncul bila ada aktivitas. Pada mobile sediakan agenda harian yang memuat informasi lengkap.

Mengajar: kelas, mata kuliah, ruang, metode. Supervisi kuning: label SUPERVISI, nama target, kelas, mata kuliah, ruang/platform, tanggal dan jam. Slot kosong abu-abu. Jika item padat, buka detail panel; tooltip bukan satu-satunya cara membaca data.

Gunakan waktu aktual, bukan membulatkan 09.10 menjadi 09.00 agar pas grid. Jika menggunakan slot visual, hitung posisi secara proporsional atau pakai daftar interval. Tampilkan konteks timezone melalui label singkat “WIB” ketika Asia/Jakarta.

## 11. Form supervisi panjang

Bagian atas: context card target, fakultas/prodi, mata kuliah, kelas, tanggal/jam, metode dan materi. Fakultas/prodi/tanggal/jam/metode read-only dari tugas. “Koreksi nama mata kuliah” membuka field override beserta alasan, tetap menampilkan nama jadwal asli.

Form dibagi section card sesuai instrumen, dengan daftar section/progres. Offline memakai sembilan bagian dan Online empat bagian dari PRD. Teks pertanyaan dan label opsi berasal dari database versi yang terikat, bukan copy statis Blade.

Sticky progress: “18 dari 29 pertanyaan nilai terisi”, “Bukti belum lengkap”, dan “Draf tersimpan 14.32”. Jumlah sesuai template nyata; draft demo diberi banner. Pertanyaan kondisional tersembunyi tidak masuk hitungan kewajiban aktif.

- SCORE memakai radio group/fieldset dan legend pertanyaan; opsi tersusun 0–4/urutan resmi. Gunakan label spesifik dari sumber, bukan satu label “Baik” untuk semua soal.
- Jawaban terpilih memiliki outline/check, tidak hanya warna. Tidak ada pilihan default otomatis.
- Nonnilai menampilkan “Wajib · Tidak memengaruhi nilai” ketika applicable.
- YES_NO: Tidak/false terlihat sebagai jawaban sudah dipilih.
- LINK: format URL dan contoh nonpersonal, tidak mengambil isi URL otomatis.
- FILE: tampilkan tipe/ukuran yang diizinkan, preview, status validasi dan hapus saat draft.
- Materi serta evaluasi memiliki counter batas karakter hanya jika ada batas validasi; hindari editor kaya yang tidak dibutuhkan.

Sticky action bar memuat Simpan Draf dan Kirim Hasil. Autosave tidak menutupi tombol simpan manual. Sebelum submit, ringkasan field kurang dengan tautan/focus ke field; input yang sudah diisi tidak hilang. Submission berhasil membuka halaman hasil read-only dengan waktu pengiriman dan versi instrumen.

Konflik dua tab: “Form ini telah diperbarui di tab/perangkat lain. Tinjau versi terbaru sebelum menyimpan.” Jangan menimpa jawaban pengguna tanpa kesempatan membandingkan. Gagal jaringan menampilkan “Belum tersimpan” dan aksi coba lagi; tidak memberi toast sukses optimistis.

## 12. Dashboard dan hasil

### LPM

Urutan informasi: ringkasan → progress → T1/T2 → fakultas/prodi → temuan → detail. Kartu: total target, supervisor aktif, submitted, progress periode, rerata T1, rerata T2, rerata perubahan berpasangan. Setiap nilai menampilkan lingkup periode, jumlah sampel dan unit skala bila relevan.

Progress membedakan belum ditugaskan, final belum diisi, draft dan submitted. Jangan menyembunyikan target belum terjadwal dari penyebut. Chart selalu memiliki label dan tabel ekuivalen; grafik tidak menggunakan 0 untuk hasil yang belum tersedia.

Tabel hasil: Dosen | Fakultas | Prodi | Metode T1/T2 | T1 | T2 | Selisih | Tren | Status. Nilai null `—`, skor nol `0,00`, beda instrumen “Tidak dapat dibandingkan”. Detail menjelaskan alasan dan versi; tidak menampilkan “turun” hanya karena T2 belum ada.

### Supervisor

Kartu tugas hari ini, belum selesai, draft dan selesai; hanya miliknya. Jadwal gabungan mengajar/supervisi, upcoming tasks dan riwayat. Supervisi atas dirinya sendiri tidak otomatis ditampilkan sebagai hasil yang boleh dibaca.

### Filter dan empty state

Filter periode/tahap/fakultas/prodi/dosen/metode dapat direset dan bertahan melalui URL. Empty state membedakan belum ada data, tidak ada hasil filter, tidak berhak, serta proses belum selesai.

Contoh: “Belum ada jadwal. Unggah PDF jadwal untuk memulai.” Contoh statistik: “Belum ada pasangan T1 dan T2 yang dapat dibandingkan.” Jangan mengisi chart dengan data demo pada lingkungan operasional.

## 13. Instrumen dan keputusan LPM

Daftar versi template memuat status, mode, jumlah item, sumber dan waktu publish. Preview seperti form supervisor. Publish blocker menyebut teks/opsi/kondisi belum lengkap secara spesifik. Versi published hanya dapat diduplikasi sebagai versi baru.

Halaman kebijakan nilai membandingkan contoh hasil tiga strategy menggunakan data simulasi berlabel; menyebut perlakuan skor 0. Pengesahan mencatat aktor/waktu/versi. Halaman rules menjelaskan disabled/null sebagai “Tidak diberlakukan”, sehingga nilai 0 tidak disalahartikan sebagai tanpa batas.

## 14. PDF standar

Ukuran awal **A4 landscape**, margin awal 10–12 mm, font isi ideal 9–10 pt dan jangan di bawah 8 pt. Jika tabel terlalu padat, tambah halaman, ulang header/identitas singkat dan batasi minggu per halaman; jangan memotong nama atau slot.

Header: UNIVERSITAS TEKNOKRAT INDONESIA; fakultas/cakupan; JADWAL DOSEN MENGAJAR & SUPERVISI; semester/tahun akademik; nama supervisor; tahap dan tanggal minggu; revisi.

Isi menampilkan grid/list interval bertanggal. Teaching putih, kosong abu-abu, supervisi kuning, teks hitam gelap. Legenda wajib, tiap slot supervisi juga bertuliskan SUPERVISI. Footer nomor halaman, waktu terbit dan revisi; tidak menambahkan tanda tangan/stempel resmi yang tidak diberikan.

Uji dengan nama panjang, dua tugas satu hari, jam tidak bulat, aktivitas Minggu, banyak minggu, tidak ada supervisi, dan printing grayscale. Detail assignment lengkap dapat ditambahkan sebagai tabel di halaman berikut jika grid tidak cukup, tanpa menghilangkan informasi yang diminta.

## 15. Tema, responsivitas dan aksesibilitas

- Dark mode melalui CSS variables dan Alpine; preferensi tema saja boleh di localStorage. Terapkan tema sebelum paint untuk menghindari flash; hormati prefers-color-scheme saat belum dipilih.
- Breakpoint verifikasi: 360 px, 768 px dan 1440 px. Sidebar menjadi drawer pada mobile; fokus terperangkap ketika drawer/modal terbuka dan kembali ke pemicu saat ditutup.
- Tabel besar memiliki area scroll berlabel atau card/agenda; jangan menyembunyikan kolom penting tanpa akses detail.
- Target kontras desain: teks normal minimal 4,5:1, teks besar 3:1; periksa pasangan warna aktual. Ini target implementasi yang diuji, bukan klaim sertifikasi aksesibilitas.
- Label terhubung input, error melalui aria-describedby, error summary dapat difokuskan, ikon memiliki accessible name jika berfungsi sendiri.
- Radio dapat dinavigasi keyboard, focus ring jelas, toast penting memakai live region, animasi menghormati reduced motion.
- Status loading/error/success/empty/forbidden disediakan pada setiap alur penting. Hindari spinner tanpa penjelasan saat job gagal.

## 16. Checklist review visual

Verifikasi login, dashboard dua role, review import, assignment valid/konflik/parsial/stale, form dua mode dengan conditional fields, hasil kosong/nol/tidak comparable, serta PDF satu dan banyak halaman. Ambil screenshot dari aplikasi nyata pada light/dark dan ukuran utama; bukti screenshot bukan pengganti uji fungsi atau policy.

Tidak menambahkan halaman promosi, animasi AI, grafik dekoratif, skor kinerja buatan atau fitur baru di luar scope. Kualitas dinilai dari keterbacaan jadwal, kejelasan konflik dan keberhasilan pengguna menyelesaikan formulir.
