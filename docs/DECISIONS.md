# Architectural Decision Records (ADR) & Register Keputusan (D01–D07)

Versi: **2.0** · Tanggal: **22 September 2026** · Status: **Aktif**

---

## 1. Register Keputusan Bisnis & Akademik Terbuka (D01–D07)

| ID | Topik | Status | Penanganan Teknis Implementasi P0 | Gate yang Ditahan |
|---|---|---|---|---|
| **D01** | Teks lengkap instrumen, opsi, kondisi dan bukti Offline/Online | TERBUKA (Belum disahkan LPM) | Membangun engine dinamis berbasis DB (`form_templates`, `form_sections`, `questions`, `question_options`) dan menyediakan fixture `DEMO_OFFLINE_V1` (29 item bernilai, 9 bagian) serta `DEMO_ONLINE_V1` (20 item bernilai, 4 bagian) berlabel demonstrasi eksplisit. | Publikasi instrumen operasional resmi LPM. |
| **D02** | Formula nilai, perlakuan 0 dan mapping opsi | TERBUKA (Belum disahkan LPM) | Menyediakan registry 3 strategi kalkulasi di server: `MEAN_0_4`, `LINEAR_1_4`, `FLOOR_1_4`. Nilai 0 diperlakukan sebagai skor valid. Presisi 6 desimal tersimpan. Demo default: `MEAN_0_4`. | Pengesahan kebijakan scoring resmi LPM. |
| **D03** | Rentang waktu harian 8 jam (480 menit) & buffer interval | TERBUKA (Usulan) | Konfigurasi span menit disimpan dalam snapshot `scheduling_rules`. Pada demo default diset `null` (dinonaktifkan) dan buffer diset `0`. Ambang mengajar `>=3` kelas pada hari yang sama diaktifkan sebagai hard constraint H05 baseline. | Pengesahan batas span harian oleh LPM. |
| **D04** | Kebijakan supervisor T1/T2, gap hari, batas harian/mingguan, lintas unit | TERBUKA | `scheduling_rules` mendukung `cross_unit_policy` (`ANY`/`SAME_FACULTY`/`SAME_STUDY_PROGRAM`), `stage_supervisor_policy` (`ANY`/`SAME`/`DIFFERENT`), `min_stage_gap_days` (default 0), dan T2 wajib bertanggal sesudah T1. | Pengesahan aturan operasional penugasan. |
| **D05** | Cakupan dosen target per periode, frekuensi observasi | TERBUKA | Baseline: 1 observasi per target per tahap. Target `INCLUDED`/`EXCLUDED` dipilih eksplisit per periode dengan alasan wajib bagi yang dikecualikan. | Pengesahan cakupan dosen operasional. |
| **D06** | Contoh PDF jadwal institusi (termasuk format scan bila ada) | TERBUKA | Disediakan 2 layout adapter PDF tabular bertulisan (`tabular-layout-a`, `tabular-layout-b`) dengan ekstraksi bounding box, preview interaktif, dan penanganan status `OCR_REQUIRED` yang jujur tanpa memalsukan hasil. | Klaim dukungan penuh terhadap seluruh variasi layout PDF BAAK. |
| **D07** | Data akun, kalender akademik, logo resmi & kredensial server | TERBUKA | Autentikasi mandiri berbasis database tanpa self-registration, akun supervisor terhubung ke master dosen, kalender terisolasi per term, dan wordmark institusi. | Setup deployment production institusi. |

---

## 2. Architectural Decision Records (ADR)

### ADR-01: Arsitektur Modular Monolith (Laravel 13 + Python Service)
- **Konteks**: Diperlukan sistem web manajemen supervisi yang memiliki integritas transaksi ACID tinggi, penanganan RBAC, UI responsif, serta kemampuan ekstraksi PDF dan constraint programming (OR-Tools CP-SAT).
- **Keputusan**: Menggunakan Laravel 13.x sebagai pengelola database tunggal, auth, business logic, dan rendering Blade. Python FastAPI berjalan secara terisolasi pada internal loopback (`127.0.0.1:8100`) sebagai compute engine khusus parsing PDF dan pemecahan constraint matematika. Python tidak memiliki akses menulis langsung ke MySQL.
- **Konsekuensi**: Transaksi database tetap terkonsentrasi di Laravel, mencegah split-brain atau desinkronisasi data.

### ADR-02: Transaksi Global dan Pencegahan Race Condition Penjadwalan
- **Konteks**: Penugasan supervisor dapat mengalami bentrok antar-tahap, antar-periode, atau akibat aksi simultan dua operator LPM.
- **Keputusan**: Tabel `scheduling_state` (singleton `id=1`) digunakan untuk mengunci mutasi jadwal (`FOR UPDATE`) dalam transaksi singkat dan menaikkan `revision`. Penerapan (apply) proposal solver membandingkan `input_revision` snapshot terhadap revision terkini; jika tidak cocok, perubahan ditolak dengan HTTP 409 (STALE).
- **Konsekuensi**: Proposal solver yang usang tidak akan pernah menimpa perubahan manual atau perubahan jadwal yang terjadi selama solver berjalan.

### ADR-03: Generated Column untuk Unique Active Assignment
- **Konteks**: Target hanya boleh memiliki maksimal 1 assignment aktif (`DRAFT` atau `FINAL`) pada satu tahap, namun riwayat assignment yang dibatalkan (`CANCELLED`) harus tetap dapat tersimpan lebih dari satu.
- **Keputusan**: Menambahkan virtual/stored generated column `active_target_key = CASE WHEN status <> 'CANCELLED' THEN target_lecturer_id ELSE NULL END` dan membuat unique index pada `(supervision_stage_id, active_target_key)`.
- **Konsekuensi**: Database MySQL menjamin secara fisik bahwa tidak ada dua penugasan aktif untuk dosen target yang sama dalam tahap yang sama, sementara penugasan yang dibatalkan dapat diarsipkan tanpa batasan.

### ADR-04: Form Instrument Data-Driven dan Versioned Immutable
- **Konteks**: Format instrumen supervisi dapat berkembang antar-tahun/semester, tetapi pengisian supervisi yang telah di-submit tidak boleh berubah nilainya secara retroaktif saat instrumen diperbarui.
- **Keputusan**: Struktur template, section, question, dan options disimpan dalam database. Status template `PUBLISHED` bersifat immutable (read-only). Hasil supervisi `SUBMITTED` menyimpan snapshot teks pertanyaan, opsi jawaban, label, dan versi policy saat submit.
- **Konsekuensi**: Audit histori nilai terjamin 100% konsisten meskipun instrumen master di masa depan diperbarui.

### ADR-05: Scoring Engine Sisi Server & Presisi Desimal
- **Konteks**: Nilai supervisi tidak boleh dimanipulasi oleh input browser dan harus mendukung pengujian terhadap 3 formula PRD.
- **Keputusan**: Nilai numerik opsi selalu dibaca dari relasi `question_options` di server. Perhitungan menggunakan format floating/decimal presisi tinggi (disimpan `DECIMAL(8,6)`), sedangkan pembulatan tampilan (half-up 2 desimal) hanya dilakukan pada lapisan presentasi. Skor `0` dan jawaban `NO` diperlakukan sebagai jawaban sah, bukan nilai hilang/kosong.
- **Konsekuensi**: Integritas nilai bebas dari eksploitasi client-side dan memenuhi standar uji presisi golden arithmetic cases.
