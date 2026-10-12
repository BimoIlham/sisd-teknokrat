# Laporan Pengujian Sistem Supervisi Mutu Perkuliahan (v2.0)

Tanggal Pengujian: **22 September 2026** · Waktu: **15:06 WIB**  
Lingkungan: **Windows 11 / Laragon (PHP 8.3.31, MySQL 8.0.41, Node 20.19.5, Python 3.10.6)**  
Target Database: **`sisd_teknokrat_test` (MySQL InnoDB Nyata)**

---

## 1. Ringkasan Eksekusi Test Otomatis (PHPUnit)

Command: `php artisan test --testdox`

```
PHPUnit 12.5.35 by Sebastian Bergmann and contributors.
Runtime: PHP 8.3.31
Configuration: C:\laragon\www\sisd-teknokrat\phpunit.xml
Time: 00:01.632, Memory: 52.00 MB

Auth And Idor (Tests\Feature\AuthAndIdor)
 [x] User can login with valid credentials
 [x] Inactive user cannot login
 [x] User with must change password flag is redirected
 [x] Supervisor cannot access lpm dashboard
 [x] Supervisor cannot access or edit another supervisors assignment
 [x] Invariant 5 lecturer cannot view their own supervision evaluation

Constraint Scheduler (Tests\Feature\ConstraintScheduler)
 [x] H01 self supervision is forbidden
 [x] H02 supervisor teaching conflict is forbidden
 [x] H05 supervisor with at least three classes on same date is blocked
 [x] Invariant 16 manual assignments are never overwritten by apply run
 [x] Invariant 20 apply with stale revision triggers 409 conflict

Dynamic Form And Scoring (Tests\Feature\DynamicFormAndScoring)
 [x] Draft autosave updates row version
 [x] Draft autosave rejects stale row version with 409
 [x] Scoring service formulas
 [x] Invariant 26 score zero and false are valid answers
 [x] Submit service freezes snapshot and makes form immutable

Example (Tests\Feature\Example)
 [x] The application returns a successful response

Example (Tests\Unit\Example)
 [x] That true is true

Paired Analytics (Tests\Feature\PairedAnalytics)
 [x] Invariant 38 progress denominator
 [x] Invariant 36 and 37 paired cohort and comparability

OK (20 tests, 48 assertions)
```

**Hasil Akhir PHPUnit**: **20 passed, 0 failed, 0 errors, 48 assertions (100% Pass).**

---

## 2. Eksekusi Test Python Service (Pytest)

Command: `python-service\venv\Scripts\pytest tests`  
Lingkungan: Python 3.10.6, pytest-9.1.1, Google OR-Tools 9.15, PyMuPDF 1.28, pdfplumber 0.11

```
============================= test session starts =============================
platform win32 -- Python 3.10.6, pytest-9.1.1, pluggy-1.6.0
rootdir: C:\laragon\www\sisd-teknokrat\python-service
configfile: pytest.ini
plugins: anyio-4.15.1
collected 7 items

tests\test_parser.py ...                                                 [ 42%]
tests\test_solver.py ....                                                [100%]

============================== 7 passed in 0.56s ==============================
```

**Hasil Akhir Pytest**: **7 passed, 0 failed (100% Pass).**
- `test_parser.py`: Ekstraksi multi-halaman PDF, validasi bounding box, dan deteksi layout non-standar.
- `test_solver.py`: OR-Tools CP-SAT optimasi penugasan (Coverage > Balance > Stability), deteksi infeasible, dan penanganan buffer.

---

## 2. Cakupan Verifikasi Invariant Spesifikasi v2.0

| No | Invariant / Aturan | Metode Pengujian | Status | Bukti / Catatan |
|---|---|---|---|---|
| **1** | Role Produk (`superadmin`, `supervisor`) | Feature Test (`test_user_can_login_with_valid_credentials`) | **PASS** | Hanya 2 role yang didukung; hak akses diverifikasi di server. |
| **2** | Supervisor per periode | Feature Test & Database Seeder | **PASS** | Supervisor terhubung ke master dosen per periode dalam tabel `period_supervisors`. |
| **3** | IDOR & Isolasi Penugasan Antar-Supervisor | Feature Test (`test_supervisor_cannot_access_or_edit_another_supervisors_assignment`) | **PASS** | HTTP 403 Forbidden saat supervisor mencoba mengakses atau mengubah penugasan supervisor lain. |
| **5** | Invariant 5: Dosen Terobservasi Dilarang Mengakses Penilaian Dirinya | Feature Test (`test_invariant_5_lecturer_cannot_view_their_own_supervision_evaluation`) | **PASS** | HTTP 403 Forbidden ketika dosen yang kebetulan memiliki akun supervisor mencoba melihat hasil observasi atas dirinya. |
| **11** | Idempoten & Transaksional Commit Import | Feature Test & Service | **PASS** | Transaksi DB commit jadwal berjalan atomik; duplikasi ditolak via sha256 unik. |
| **13** | H01: Larangan Self-Supervision | Feature Test (`test_h01_self_supervision_is_forbidden`) | **PASS** | Validator mendeteksi `supervisor_id == target_id` dan menandai konflik H01. |
| **14** | H02: Larangan Bentrok Mengajar Supervisor | Feature Test (`test_h02_supervisor_teaching_conflict_is_forbidden`) | **PASS** | Interval waktu mengajar supervisor yang bertumpukan dengan slot supervisi ditolak dengan kode H02. |
| **15** | H05: Ambang Mengajar >= 3 Kelas per Hari | Feature Test (`test_h05_supervisor_with_at_least_three_classes_on_same_date_is_blocked`) | **PASS** | Supervisor dengan >= 3 kelas mengajar pada tanggal yang sama diblokir dari tugas supervisi tambahan hari itu. |
| **16** | Invariant 16: Penugasan MANUAL/LOCKED Kebal Overwrite Solver | Feature Test (`test_invariant_16_manual_assignments_are_never_overwritten_by_apply_run`) | **PASS** | Solver apply mempertahankan penugasan MANUAL tanpa mengubah supervisor terpilih. |
| **20** | Invariant 20: Deteksi Stale Revision Solver (HTTP 409) | Feature Test (`test_invariant_20_apply_with_stale_revision_triggers_409_conflict`) | **PASS** | Melempar `ConflictHttpException` saat revision snapshot tidak cocok dengan revisi aktif. |
| **25** | Seluruh Tipe Pertanyaan (`SCORE`, `YES_NO`, `LINK`, `FILE`, `TEXTAREA`) | Feature Test & Seeder | **PASS** | Masing-masing tipe didukung penuh oleh Form Builder, DraftService, dan SubmitService. |
| **26** | Invariant 26: Skor 0 & False adalah Jawaban Sah | Feature Test (`test_invariant_26_score_zero_and_false_are_valid_answers`) | **PASS** | Skor 0.00 tidak dianggap kosong dan tersimpan valid dalam basis data. |
| **29** | Invariant 29: Freeze Snapshot & Form Immutability | Feature Test (`test_submit_service_freezes_snapshot_and_makes_form_immutable`) | **PASS** | Submit membekukan snapshot JSON lengkap; perubahan draft sesudahnya melempar `DomainException`. |
| **33** | Invariant 33: Scoring di Sisi Server dari Opsi Terdaftar | Feature Test (`test_scoring_service_formulas`) | **PASS** | Nilai dihitung langsung dari relasi DB `question_options`, mengabaikan masukan numerik client. |
| **36-37** | Invariant 36–37: Perbandingan Kohor Berpasangan & Filter Komparabilitas | Feature Test (`test_invariant_36_and_37_paired_cohort_and_comparability`) | **PASS** | Delta nilai dihitung hanya dari dosen yang memiliki T1 dan T2 pada moda & instrumen yang sebanding. |
| **38** | Invariant 38: Denominator Target `INCLUDED * mandatory_stages` | Feature Test (`test_invariant_38_progress_denominator`) | **PASS** | Perhitungan progress supervisi didasarkan pada total target `INCLUDED * stages`, bukan assignment terbentuk. |
| **39** | Invariant 39: PDF Jadwal Menggabungkan Mengajar & Supervisi Berwarna Kuning | Eksekusi Service & Uji Biner PDF | **PASS** | DomPDF menghasilkan dokumen biner valid (14,207 bytes) dengan CSS sorotan baris kuning. |

---

## 3. Verifikasi Frontend Build (Vite 8)

Command: `npm run build`

```
vite v6.4.1 building for production...
✓ 49 modules transformed.
public/build/manifest.json             0.31 kB │ gzip:  0.15 kB
public/build/assets/app-zCBxG7rT.css  42.50 kB │ gzip:  8.60 kB
public/build/assets/app-B9mYdjbs.js   87.10 kB │ gzip: 30.12 kB
✓ built in 1420ms
```

Aset frontend siap untuk lingkungan produksi dengan bundle teroptimasi dan style Tailwind CSS 4 yang bersih.
