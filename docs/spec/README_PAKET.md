# Paket Implementasi — Sistem Supervisi Mutu Perkuliahan

Versi **2.0** · 22 September 2026.

## Cara menggunakan

1. Ekstrak seluruh isi ZIP ke satu folder. Simpan lima spesifikasi bersama, misalnya pada `docs/spec/` di repository proyek.
2. Berikan **kelima file** kepada AI agent yang mempunyai akses repository dan terminal.
3. Salin seluruh isi `ULTIMATE_IMPLEMENTATION_PROMPT.md` sebagai perintah implementasi. Jika agent dapat membaca file, arahkan agent menjalankan prompt tersebut bersama lima spesifikasi.
4. Agent harus membaca semua dokumen, mengaudit project yang ada, kemudian mengimplementasikan P0 beserta pengujiannya. Jangan hanya memberikan prompt tanpa dokumen pendukung.
5. Jika tersedia, tambahkan instrumen asli Offline/Online serta contoh PDF jadwal sebelum verifikasi operasional. Implementasi engine dapat dimulai sambil bahan tersebut dilengkapi.

Nama proyek **Sistem Supervisi Mutu Perkuliahan**. Paket ini adalah dokumen perencanaan dan instruksi implementasi; belum berisi source aplikasi yang selesai dibangun.

## Isi paket

| File | Fungsi |
|---|---|
| [PRD.md](PRD.md) | Scope, role, aturan produk, acceptance dan keputusan terbuka |
| [Arsitektur.md](Arsitektur.md) | Stack, kontrak parser/solver, transaksi, keamanan dan operasi |
| [schema.md](schema.md) | Model database lengkap, key, constraint, state dan snapshot |
| [Desain.md](Desain.md) | UI/UX, identitas visual, semua state penting dan PDF |
| [MVP.md](MVP.md) | Fase implementasi, keterlacakan requirement, tests dan gate rilis |
| [ULTIMATE_IMPLEMENTATION_PROMPT.md](ULTIMATE_IMPLEMENTATION_PROMPT.md) | Prompt lengkap untuk agent pelaksana |
| [README_PAKET.md](README_PAKET.md) | Cara penggunaan dan ringkasan revisi |

## Penyempurnaan dari lima dokumen awal

| Temuan awal | Penyempurnaan v2 |
|---|---|
| Assignment menunjuk pola hari, belum tanggal pelaksanaan | Occurrence bertanggal, timezone, kalender/exception, interval dan PDF per minggu |
| Scope target dan frekuensi observasi belum tegas | Target INCLUDED/EXCLUDED dan baseline satu target per tahap, dengan pengesahan operasional |
| Nama dosen berisiko dipakai sebagai identitas unik | External identity + review mapping, nama tidak unique |
| Import berhenti pada READY | Approval, COMMITTING/COMMITTED, transaksi, deduplikasi, retry dan source provenance |
| Manual prioritas belum menjelaskan konflik keras | Manual tetap locked; displacement hanya AUTO DRAFT; hard conflict tidak dapat dipaksa |
| Hasil generate berisiko menimpa perubahan terbaru | Proposal/apply terpisah, revision/hash, global lock dan stale rejection |
| Solver belum mempunyai kontrak dan perilaku gagal jelas | Payload versioned, status asli solver, partial coverage dan diagnosis yang jujur |
| Aturan “3 jadwal”/“8 jam” masih ambigu | >=3 pertemuan per tanggal sebagai baseline; definisi span terukur tetapi aktivasi tetap perlu disahkan |
| Tidak ada constraint aktif target/tahap yang kuat | Generated unique active key, validator lintas tabel dan uji concurrency |
| Versioning instrumen belum cukup kuat | Composite code/version, immutable published templates dan binding per tahap/mode |
| Teks 29/20 pertanyaan tidak disertakan | D01 source verification; fixture demo tidak boleh menjadi instrumen resmi |
| Skala 1–4 bertentangan dengan sumber skor 0–4 | Tiga strategy eksplisit, tanpa memilih formula resmi atau menambah bobot |
| Histori dapat berubah sesudah master/instrumen diedit | Identity/submission snapshot, policy version dan hasil immutable |
| Form IN_PROGRESS dan DRAFT berpotensi tumpang tindih | Status disederhanakan ASSIGNED/DRAFT/SUBMITTED |
| Evaluasi disimpan terpisah dan berpotensi duplikasi | Satu jawaban TEXTAREA dengan kode stabil |
| T1/T2 dapat membandingkan kelompok yang berbeda | Pasangan lecturer+period, comparable checks, sample count dan missing bukan nol |
| Progress hanya dari assignment berisiko menutupi target | Denominator target INCLUDED × tahap wajib |
| Akses berdasarkan role belum membatasi data milik siapa | Role+membership+ownership pada form, file, job dan dashboard |
| Prioritas audit belum konsisten | Audit kritis menjadi P0; audit/analitik tambahan tetap bertahap |
| Acceptance masih berupa checklist fitur | Matriks REQ, golden arithmetic cases, integration, E2E dan bukti QA |
| Belum ada prompt implementasi lengkap | Prompt dengan mandat, invariant, urutan, checkpoint dan pelaporan jujur |

## Hal yang tetap membutuhkan sumber atau keputusan pemilik proses

Tidak ada pilihan akademik yang dianggap telah disahkan hanya karena dokumen diperbaiki. Register lengkap D01–D07 ada pada PRD.

- **Instrumen resmi:** teks semua pertanyaan, label opsi, pemetaan nilai, required/conditional dan syarat bukti. Jumlah 29/20 saja belum cukup membangun instrumen resmi.
- **Formula nilai:** perlakuan skor 0 dan pilihan skala. Tiga formula yang disediakan menghasilkan perilaku berbeda.
- **Rules operasional:** rentang 8 jam, buffer, T1/T2, gap, kapasitas, lintas unit, cakupan target dan ambang mengajar. Pilihan aturan dinonaktifkan juga dapat disahkan secara eksplisit.
- **Data institusi:** PDF jadwal asli, kalender, pemetaan akun, logo dan lingkungan deployment.

Hal ini menahan bagian operasional terkait, bukan seluruh coding. Agent diarahkan membangun serta menguji bagian yang dapat dijalankan, dan melabeli demo secara jelas.

## Batas verifikasi paket

Penyempurnaan dibuat setelah membaca kelima Markdown dalam ZIP yang diberikan. Tidak menggunakan file proyek lain sebagai pengganti bahan sumber. Referensi teknis primer untuk framework, Tailwind, solver dan renderer dicantumkan di Arsitektur.

Validasi paket meliputi kelengkapan file, struktur Markdown/JSON contoh, tautan internal, keselarasan istilah/status, rumus dan contoh angka serta integritas ZIP. **Belum ada aplikasi yang dikompilasi, database yang dimigrasikan atau E2E aplikasi yang dijalankan dalam pekerjaan penyusunan dokumen ini.** Verifikasi tersebut menjadi tanggung jawab implementasi agent sesuai MVP.
