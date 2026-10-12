# Status Implementasi & Checkpoint Proyek

Versi: **2.0** · Tanggal: **22 September 2026** · Status: **Selesai & Terverifikasi (P0)**

---

## Ringkasan Progres Fase

- [x] **F0: Audit Repositori & Spesifikasi Arsitektur**: 
  - Dokumen spesifikasi v2.0 dianalisis (`PRD.md`, `Arsitektur.md`, `schema.md`, `Desain.md`, `MVP.md`).
  - Dokumen operasional diinisialisasi: `DECISIONS.md`, `REQUIREMENT_TRACEABILITY.md`, `OPERATIONS.md`.
  - Database terisolasi MySQL 8 `sisd_teknokrat` dan `sisd_teknokrat_test` dikonfigurasi pada InnoDB.
  - Runtime diverifikasi: PHP 8.3.31, Composer 2.10.3, Python 3.10.6, Node 20.19.5, MySQL 8.0.41.
- [x] **F1: Pondasi Laravel 13, Autentikasi, Role & UI Shell**:
  - Dual-role terimplementasi (`superadmin` dan `supervisor`).
  - Spatie Permission terintegrasi dengan middleware `CheckMustChangePassword`.
  - Authorization Policies ketat (`SupervisionAssignmentPolicy`, `SupervisionPolicy`, `SupervisionEvidencePolicy`) untuk memblokir celah IDOR dan melindungi Invariant 5 (dosen tidak dapat menilai diri sendiri atau melihat hasil evaluasi dirinya).
  - Shell UI Blade dengan Tailwind CSS 4, Alpine.js 3, Lucide Icons, palet warna maroon (#7f1d1d), dan dark mode.
- [x] **F2: Model Data Akademik, Kalender & Penjadwalan Waktu**:
  - 7 Migration utama dieksekusi dengan foreign keys, unique active generated columns, index teroptimasi.
  - Relasi Eloquent dan model mapping dengan virtual accessors/mutators (`start_time`, `end_time`, `room`, `active_target_key`).
  - Seeders: `RoleAndPermissionSeeder`, `UserSeeder`, `DemoAcademicSeeder` (8 dosen, 3 supervisor per periode, 172 kelas terjadwal).
- [x] **F3: Python Service (FastAPI) & Pipeline Import PDF Nyata**:
  - Modul Python FastAPI (`python-service/`) dengan PyMuPDF (`fitz`) dan `pdfplumber` tabular parser.
  - `ScheduleImportService` dan `ScheduleCommitService` atomik dan idempoten (Invariant 11).
  - UI review tabel hasil import dengan deteksi ambigu, preview baris, approval per baris, dan komit jadwal.
- [x] **F4: Scheduler Berbasis Constraint (OR-Tools CP-SAT) & Proposal Engine**:
  - Solver CP-SAT (`python-service/cp_sat_solver.py`) dengan hierarki optimasi: Coverage > Balance > Stability.
  - `ConflictChecker.php` mengawal aturan H01–H08: cegah self-supervision, bentrok mengajar, ambang >=3 kelas/hari, dan urutan T1 sebelum T2.
  - Snapshot revision terproteksi dengan `scheduling_state` (Invariant 20: 409 Conflict pada stale state).
- [x] **F5: Workspace Penugasan, Manual Override, Finalisasi & PDF Personal**:
  - Workspace LPM untuk visualisasi penugasan, filter per tahap, visualisasi bentrok.
  - Penugasan manual berstatus `MANUAL` terkunci dari penimpaan scheduler (Invariant 16).
  - Finalisasi tahap (`StageFinalizationService`) memastikan kelengkapan target sebelum pembekuan dan mendukung pembukaan ulang yang aman (Invariant 19).
  - `SchedulePdfService` menggunakan DomPDF untuk menghasilkan berkas PDF jadwal personal dengan sorotan warna kuning institusional untuk slot supervisi (Invariant 39).
- [x] **F6: Dynamic Instrument Engine & Validasi Kondisional**:
  - Schema instrumen versioned & immutable: `form_templates`, `form_sections`, `questions`, `question_options`.
  - Fixture demo berlabel eksplisit: `DEMO_OFFLINE` (29 butir bernilai) dan `DEMO_ONLINE` (20 butir bernilai).
  - Dukungan seluruh jenis butir: `SCORE`, `YES_NO`, `LINK`, `FILE`, `TEXTAREA` (Invariant 25).
- [x] **F7: Pelaksanaan Supervisi, Bukti Privat, Submit Atomik & Scoring**:
  - `DraftService` dengan optimistic locking `row_version` (mencegah tab overwrite).
  - `EvidenceService` menangani unggah berkas privat pada `storage/app/private` dengan validasi MIME, ukuran maksimal, dan checksum sha256.
  - `SubmitService` memvalidasi kelengkapan butir, mengunci status `SUBMITTED`, dan membekukan snapshot JSON lengkap (Invariant 29).
  - `ScoringService` mengkalkulasi 3 formula PRD (`MEAN_0_4`, `LINEAR_1_4`, `FLOOR_1_4`) dari opsi database server (Invariant 33). Skor 0 dan boolean false adalah sah (Invariant 26).
- [x] **F8: Dashboard Analitik & Perbandingan Berpasangan T1 vs T2**:
  - Perhitungan progress berpenyebut: `target INCLUDED * mandatory_stages` (Invariant 38).
  - Rerata perubahan dihitung eksklusif dari pasangan kohor sebanding (`period_id + lecturer_id`) dengan moda pembelajaran dan instrumen yang identik (Invariant 36, 37).
- [x] **F9: Test Suite, Verifikasi Kualitas & Kesiapan Operasi**:
  - 20 Automated Feature Tests pada MySQL 8 InnoDB (`sisd_teknokrat_test`) lulus 100% (48 assertions).
  - Pengujian PDF manual via service terbukti sukses menghasilkan dokumen biner PDF valid.
  - Build frontend Vite 8 sukses terkompilasi (`public/build/`).

---

## Log Checkpoint Terkini

- **Direktori Kerja**: `c:\laragon\www\sisd-teknokrat`
- **Status Rangkaian Test**: 20 PASSED, 0 FAILED (100% PASS)
- **Status Kesiapan**: Siap untuk operasional P0 dan demonstrasi teknis LPM.
