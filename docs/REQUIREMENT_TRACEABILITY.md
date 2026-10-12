# Matriks Keterlacakan Kebutuhan (Requirement Traceability Matrix)

Versi: **2.0** · Dokumen Sumber: `PRD.md`, `Arsitektur.md`, `schema.md`, `Desain.md`, `MVP.md`

| Kode REQ | Deskripsi Kebutuhan | Komponen Kode Implementasi | Bukti Uji / Test Suite | Status |
|---|---|---|---|---|
| **REQ-AUTH-01** | Dua role produk: `superadmin` (LPM) dan `supervisor` (Dosen). | `app/Models/User.php`, Spatie Permission, `app/Http/Middleware/*` | `tests/Feature/AuthAndIdorTest.php` | IMPLEMENTED |
| **REQ-AUTH-02** | Supervisor dipilih per periode; wajib terhubung ke master dosen. | `app/Models/PeriodSupervisor.php`, `app/Services/Identity/*` | `tests/Feature/AuthAndIdorTest.php` | IMPLEMENTED |
| **REQ-AUTH-03** | Pencegahan IDOR pada form, jadwal, bukti, dan PDF jadwal. | `app/Policies/SupervisionPolicy.php`, `app/Policies/DocumentPolicy.php` | `tests/Feature/AuthAndIdorTest.php` | IMPLEMENTED |
| **REQ-AUTH-04** | Tidak ada self-registration publik; pergantian password wajib akun baru. | `app/Http/Controllers/Auth/AuthController.php` | `tests/Feature/AuthAndIdorTest.php` | IMPLEMENTED |
| **REQ-TIME-01** | Konversi jadwal mingguan ke occurrence bertanggal aktual pada rentang tahap. | `app/Services/Academic/OccurrenceGeneratorService.php` | `tests/Feature/ScheduleImportTest.php` | IMPLEMENTED |
| **REQ-TIME-02** | Interval waktu UTC half-open `[starts_at, ends_at)` dan penanganan tanggal lokal WIB. | `app/Services/Scheduling/ConflictChecker.php` | `tests/Feature/ConstraintSchedulerTest.php` | IMPLEMENTED |
| **REQ-TIME-03** | Kalender libur mengecualikan pola normal secara otomatis. | `app/Models/AcademicNonTeachingDate.php` | `tests/Feature/ScheduleImportTest.php` | IMPLEMENTED |
| **REQ-IMPORT-01** | Pipeline upload privat, hashing SHA-256, dan ekstraksi PDF via Python. | `app/Services/ScheduleImport/ScheduleImportService.php`, `python-service` | `tests/Feature/ScheduleImportTest.php` | IMPLEMENTED |
| **REQ-IMPORT-02** | Staging preview, identifikasi dosen ambigu, koreksi baris, approval per baris. | `app/Http/Controllers/Lpm/ScheduleImportController.php` | `tests/Feature/ScheduleImportTest.php` | IMPLEMENTED |
| **REQ-IMPORT-03** | Commit atomik ke `teaching_schedules` dan `teaching_occurrences`, rollback saat gagal. | `app/Services/ScheduleImport/ScheduleCommitService.php` | `tests/Feature/ScheduleImportTest.php` | IMPLEMENTED |
| **REQ-SCHED-01** | Solver CP-SAT OR-Tools mematuhi hard constraints H01–H08. | `python-service/app/scheduler/cp_sat_solver.py` | `tests/Feature/ConstraintSchedulerTest.php`, Pytest | IMPLEMENTED |
| **REQ-SCHED-02** | Ambang mengajar `>=3` kelas per tanggal menolak penambahan supervisi (H05). | `app/Services/Scheduling/CandidateBuilder.php`, `cp_sat_solver.py` | `tests/Feature/ConstraintSchedulerTest.php` | IMPLEMENTED |
| **REQ-SCHED-03** | Penugasan MANUAL/LOCKED dan FINAL tidak tertimpa oleh solver (H06). | `app/Services/Scheduling/ApplyRunService.php` | `tests/Feature/ManualOverrideAndFinalizationTest.php` | IMPLEMENTED |
| **REQ-SCHED-04** | Global lock `scheduling_state` dan penolakan stale snapshot proposal (HTTP 409). | `app/Services/Scheduling/ApplyRunService.php` | `tests/Feature/ManualOverrideAndFinalizationTest.php` | IMPLEMENTED |
| **REQ-ASSIGN-01** | Manual override dengan alasan wajib dan transaksi displacement AUTO DRAFT. | `app/Services/Scheduling/ManualOverrideService.php` | `tests/Feature/ManualOverrideAndFinalizationTest.php` | IMPLEMENTED |
| **REQ-ASSIGN-02** | Gate finalisasi tahap: target lengkap, jadwal valid, akun aktif, instrumen terikat. | `app/Services/Scheduling/StageFinalizationService.php` | `tests/Feature/ManualOverrideAndFinalizationTest.php` | IMPLEMENTED |
| **REQ-ASSIGN-03** | Gate buka ulang tahap: hanya jika belum ada formulir DRAFT atau SUBMITTED. | `app/Services/Scheduling/StageFinalizationService.php` | `tests/Feature/ManualOverrideAndFinalizationTest.php` | IMPLEMENTED |
| **REQ-FORM-01** | Template dinamis, versioned, immutable saat `PUBLISHED` (Offline 29 & Online 20 item). | `app/Models/FormTemplate.php`, `app/Services/Instruments/*` | `tests/Feature/DynamicFormAndScoringTest.php` | IMPLEMENTED |
| **REQ-FORM-02** | Seluruh tipe soal: `SCORE`, `YES_NO`, `LINK`, `FILE`, `TEXTAREA`. | `app/Services/Supervision/DraftService.php` | `tests/Feature/DynamicFormAndScoringTest.php` | IMPLEMENTED |
| **REQ-FORM-03** | Skor `0` dan jawaban `NO`/false adalah jawaban valid, bukan nilai kosong. | `app/Services/Supervision/SubmitService.php` | `tests/Feature/DynamicFormAndScoringTest.php` | IMPLEMENTED |
| **REQ-FORM-04** | Evaluasi aturan kondisional nonnilai tanpa script bebas atau siklus. | `app/Services/Instruments/ConditionEvaluator.php` | `tests/Feature/DynamicFormAndScoringTest.php` | IMPLEMENTED |
| **REQ-SCORE-01** | Nilai opsi diambil dari server; mengabaikan numeric_score kiriman client. | `app/Services/Scoring/ScoringService.php` | `tests/Feature/DynamicFormAndScoringTest.php` | IMPLEMENTED |
| **REQ-SCORE-02** | Mendukung 3 strategi: `MEAN_0_4`, `LINEAR_1_4`, `FLOOR_1_4` dengan golden cases. | `app/Services/Scoring/ScoringService.php` | `tests/Feature/DynamicFormAndScoringTest.php` | IMPLEMENTED |
| **REQ-ANALYTICS-01** | Progress dihitung terhadap denominator target `INCLUDED` × tahap wajib. | `app/Services/Analytics/SupervisionAnalyticsService.php` | `tests/Feature/PairedAnalyticsTest.php` | IMPLEMENTED |
| **REQ-ANALYTICS-02** | Pasangan T1 vs T2 dicocokkan via `period_id + lecturer_id`, delta hanya cohort sebanding. | `app/Services/Analytics/SupervisionAnalyticsService.php` | `tests/Feature/PairedAnalyticsTest.php` | IMPLEMENTED |
| **REQ-ANALYTICS-03** | Missing score ditampilkan `—`, bukan angka 0. | `resources/views/lpm/analytics/*` | `tests/Feature/PairedAnalyticsTest.php` | IMPLEMENTED |
| **REQ-PDF-01** | Render PDF jadwal personal per minggu, slot supervisi kuning (`#facc15`), timezone WIB. | `app/Services/Reporting/SchedulePdfService.php` | `tests/Feature/SchedulePdfTest.php` | IMPLEMENTED |
| **REQ-NFR-01** | Penyimpanan bukti privat, validasi MIME/dimensi, proteksi CSRF, audit tindakan kritis. | `app/Services/Supervision/EvidenceService.php`, `app/Models/SystemAudit.php` | `tests/Feature/DynamicFormAndScoringTest.php` | IMPLEMENTED |
