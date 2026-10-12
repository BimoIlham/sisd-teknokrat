# MVP dan Rencana Implementasi — Sistem Supervisi Mutu Perkuliahan

Versi **2.0** · 22 September 2026 · Spesifikasi produk: `PRD.md`.

## 1. Hasil yang harus dicapai

Satu alur utuh: LPM membuat periode dan tahap, mengimpor PDF, meninjau/mengoreksi jadwal, menetapkan target/supervisor, menghasilkan dan mengubah penugasan, memfinalisasi, menerbitkan PDF personal; supervisor mengisi instrumen, bukti dan evaluasi; sistem menyimpan nilai serta membandingkan T1/T2.

Tidak cukup menyediakan tampilan demo, CRUD kosong, response mock, placeholder parser atau scheduler yang sebenarnya random. Mock hanya boleh dipakai dalam test yang jelas; alur aplikasi harus memakai integrasi sebenarnya.

### Dua tingkat kesiapan

| Tingkat | Syarat |
|---|---|
| MVP teknis terverifikasi | Seluruh P0 berjalan end-to-end pada data demo berlabel, integrasi nyata dan pengujian lulus |
| MVP siap operasional | Seluruh gate teknis + sumber instrumen/PDF, kebijakan LPM, akun, kalender dan konfigurasi server resmi tersedia dan diverifikasi |

Dengan paket dokumen saat ini, gate sumber/keputusan belum otomatis terpenuhi. Agent tetap menyelesaikan modul yang dapat dibangun dan melaporkan blokir secara spesifik; tidak boleh mengklaim produksi siap hanya karena demo berjalan.

## 2. Prioritas yang mengikat

| Prioritas | Lingkup |
|---|---|
| P0 | Auth/policies, master term/periode/tahap, kalender/pertemuan bertanggal, import PDF + review + commit, scope target, supervisor, solver/apply, manual lock/conflict, finalisasi, PDF jadwal, instrumen dinamis, draft/evidence/submit, scoring versioned, dashboard/T1-T2, audit kritis, idempotency dan concurrency |
| P1 | Excel, PDF laporan hasil, bulk PDF, analisis temuan terstruktur, filter lanjutan, OCR otomatis jika bukan kebutuhan format awal, alur amandemen hasil setelah mulai diisi |
| P2 | Email/WhatsApp, SSO, mobile app, AI recommendations, digital signature, portal publik |

Audit kritis dipindahkan dari kategori “nanti” menjadi P0 karena dibutuhkan manual override, histori nilai dan finalisasi. Jangan menunda keamanan file atau policy sampai akhir.

## 3. Inventaris bahan awal dan keputusan

Tersedia: lima spesifikasi Markdown, arah brand, jumlah item Offline/Online, daftar elemen form tingkat bagian, stack dan alur bisnis.

Belum tersedia dalam ZIP asal:

1. Teks resmi seluruh 29/20 item, opsi, keterangan dan kondisi required.
2. PDF jadwal asli minimal dua layout representatif; scan jika termasuk sumber operasional.
3. Pengesahan formula, nol, mapping, aturan rentang harian, T1/T2, gap, limit, lintas unit, cakupan dan ambang mengajar.
4. Akun, kalender akademik, logo, lingkungan target dan credential deployment.

Gunakan register D01–D07 di PRD. Jangan membuat pertanyaan akademik palsu agar checklist tampak lengkap. Demonstrasi engine boleh memakai fixture kecil dan fixture 29/20 bertanda DEMO dengan teks netral sintetis, dipisahkan total dari seed produksi.

## 4. Tahapan kerja dan acceptance gate

### F0 — Audit repository dan keputusan teknis

Tindakan: baca seluruh lima file dan instruksi repository, periksa git status, package/lockfiles, runtime dan struktur existing. Tentukan reuse/gap, source assets yang ada, dependency yang kompatibel, DB target serta cara menjalankan proyek. Catat keputusan teknis dan blocker bisnis terpisah.

Output: `docs/IMPLEMENTATION_PLAN.md`, `docs/DECISIONS.md`, `docs/REQUIREMENT_TRACEABILITY.md`. Dokumen tersebut dibuat agent ketika mengimplementasikan, bukan dianggap sudah ada dalam paket ini.

Gate: tidak ada overwrite pekerjaan pengguna, scope P0 jelas, lockfile approach jelas, data demo dan data resmi terpisah. Pertanyaan nonblocking digabung dalam register sambil implementasi berlanjut.

### F1 — Foundation, autentikasi dan shell UI

Bangun Laravel, MySQL, Blade/Vite/Tailwind/Alpine, login/logout, forced password change, roles, policies, akun aktif/nonaktif, shell dua role, komponen dasar dan tema. Tidak ada public registration.

Gate: login kedua role bekerja; akses silang ditolak server; akun nonaktif ditolak; asset build berhasil; halaman utama usable pada desktop/mobile. Role supervisor tanpa mapping dosen tidak dapat membuka tugas.

### F2 — Model akademik dan waktu

Bangun migrations sesuai schema, term, master, periode/tahap, kalender, scope target, supervisor, aturan versioned, occurrence generator dan global scheduling_state. Implement date conversion dan overlap service sejak awal.

Gate: satu dosen dapat mengajar/menjadi supervisor/target tanpa duplikasi master; membership per periode; weekday/UTC/local konsisten; generator idempotent; exception kalender dipertahankan; unique/FK/CHECK diuji pada MySQL yang dipakai.

### F3 — Import end-to-end

Private upload → queue → Python parser nyata → normalisasi/issue → preview → koreksi/matching → approval → commit transaction → master dan occurrence. Sertakan sample fixture yang boleh didistribusikan tanpa data pribadi.

Gate: dua layout fixture bertulisan, multi-file, field hilang, ambiguous name, duplicate/retry, encrypted/corrupt/no-text ditangani. Commit ulang tidak menggandakan data dan commit gagal tidak meninggalkan master setengah jadi. Tulis jelas fixture sintetis vs PDF institusi; dukungan institusi belum lulus tanpa file asli.

### F4 — Scheduler dan proposal

Implement CandidateBuilder, Python OR-Tools, kontrak v1, hard constraints H01–H08, rules nullable, objective coverage/balance/stability, diagnostics dan run UI. Solver benar-benar dipanggil; tidak memilih random/round-robin tanpa constraint.

Gate: semua hard constraints lulus termasuk lintas periode, full/partial/no-candidate/UNKNOWN/model invalid. Locked tetap. Generate gagal mempertahankan jadwal lama. Stale proposal ditolak saat apply. Target belum terjadwal terlihat beserta alasan.

### F5 — Manual override, finalisasi dan PDF personal

Manual priority terhadap AUTO DRAFT, transaksi displacement, lock/unlock beralasan, audit, recalc, finalize/reopen gates, account readiness, stage instruments binding, PDF private per minggu/revisi.

Gate: manual tetap sesudah rerun; self/teaching/locked/final conflict tidak dapat dipaksa; dua operator bersamaan tidak membuat double-booking; final hanya saat lengkap/valid; reopen tidak menghapus draft/submission. PDF kuning, teks/identitas/tanggal benar, multi-page terbaca dan download lintas akun ditolak.

Binding untuk pengujian tahap ini dapat memakai instrumen demo yang terisolasi; finalisasi operasional menunggu published source yang resmi.

### F6 — Form engine dan instrumen

Bangun template/section/questions/options, conditional validator, editor/preview/publish version, binding tahap, layout Offline/Online dan identity context. Import seed resmi hanya ketika sumber tersedia dan dibandingkan item per item.

Gate: type SCORE/YES_NO/LINK/FILE/TEXTAREA berjalan; question/option lintas template ditolak; kondisi tidak aktif tidak memblokir required; graph invalid ditolak; publish mengunci versi. Count resmi 29/20 dicocokkan bersama teks dan mapping, bukan hanya jumlahnya.

### F7 — Draft, evidence, submit dan scoring

Draft manual+autosave, row_version, file privat tervalidasi, upload/link/evaluasi, submit atomik/idempotent, snapshot, tiga strategy nilai, status immutable serta preview demo. Tahap ini tidak menambahkan bobot akademik.

Gate: nilai 0 dan NO valid; required nonnilai tetap wajib; file invalid/gagal upload tidak dianggap bukti lengkap; double submit satu hasil; draft dua tab tidak silent overwrite; policy resmi wajib untuk produksi; versi baru tidak mengubah hasil lama.

### F8 — Dashboard, hasil dan pasangan T1/T2

Dashboard supervisor milik sendiri, LPM overview, tabel per dosen/unit/tahap/metode, progres berdasarkan scope, detail evidence dan evaluasi, paired delta, missing values dan perbedaan instrumen.

Gate: belum ditugaskan masuk denominator; draft bukan submitted; T1 saja/T2 saja tidak menjadi 0; rerata perubahan hanya cohort berpasangan/comparable; unit berbeda ditandai; dashboard dan hasil memakai query/mapping yang sama.

### F9 — Integrasi, QA dan kesiapan operasi

Jalankan suite meaningful, E2E integrasi, visual review light/dark/mobile, render PDF, ukur fixture beban awal, uji backup/restore dan startup ulang worker. Tulis README instalasi, `.env.example`, runbook dan laporan hasil nyata.

Gate: semua P0 teknis lulus, migrasi baru dapat diinstal, existing data tidak hilang, lockfiles tersedia, tidak ada credential hardcoded, tidak ada tombol P0 palsu, kegagalan layanan dapat dipulihkan. Gate produksi yang belum terpenuhi tercatat dengan pemilik keputusan.

### F10 — P1 setelah P0 stabil

Implement Excel/PDF hasil, bulk generation, analitik lanjutan dan OCR bila disepakati. Tidak memakai P1 sebagai alasan melewatkan P0. Jika sumber PDF asli berupa scan, dukungan baca/koreksi yang memenuhi alur operasional harus dipastikan sebelum menyatakan import siap pakai.

## 5. Matriks keterlacakan

| Requirement PRD | Delivery | Bukti minimum |
|---|---|---|
| REQ-AUTH | F1, F5, F7 | Feature test dua role/ownership dan unduhan privat |
| REQ-TIME | F2, F4 | Uji tanggal, timezone, kalender, occurrence dan batas interval |
| REQ-IMPORT | F3 | Dua fixture layout, review/commit/retry/rollback |
| REQ-SCHED | F4 | Test OR-Tools H01–H08 + integration client |
| REQ-ASSIGN | F5 | Manual lock, concurrency, stale apply, final/reopen |
| REQ-FORM | F6–F7 | Kondisi, seluruh tipe, source verification, draft |
| REQ-SCORE | F7 | Golden numeric cases dan snapshot version |
| REQ-ANALYTICS | F8 | Cohort pairs, denominator, missing/incomparable |
| REQ-PDF | F5/F9 | PDF nyata dirender, multi-week, akses terotorisasi |
| REQ-STATE | Semua | Transisi sah dan request transisi invalid ditolak |
| REQ-NFR | F1–F9 | Security, queue recovery, backup/restore dan perf evidence |

Agent memperluas tiap baris dengan path kode/test, hasil PASS/FAIL/BLOCKED/NOT_RUN, tanggal dan bukti output. Banyaknya test tidak menggantikan ketercakupan aturan.

## 6. Skenario uji bernilai tinggi

### A. Identity, auth dan import

| ID | Skenario | Hasil yang diharapkan |
|---|---|---|
| A01 | Supervisor membuka ID tugas/PDF/bukti orang lain | 403/404 yang konsisten, tanpa data bocor |
| A02 | Supervisor tidak dipilih pada periode baru | Tidak bisa mengubah tugas periode baru |
| A03 | Akun nonaktif dengan sesi lama | Sesi tidak dapat mengakses resource |
| A04 | Dua nama sama tanpa kode resmi | AMBIGUOUS; tidak otomatis merge |
| A05 | Nama bergelar dan tanpa gelar dengan kode sama valid | Mapping ke identitas yang sama setelah review |
| A06 | File/jadwal diunggah dan di-commit ulang | Tidak ada master/occurrence ganda |
| A07 | Jam akhir kosong, ruangan Offline kosong, prodi tidak cocok | Review, bukan auto-fill tebakan |
| A08 | Commit gagal di tengah transaksi | Tidak ada data setengah committed |
| A09 | PDF rusak/password/scan/tidak didukung | Code jelas dan pemulihan; tidak READY kosong |
| A10 | Baris approved kemudian diedit | Approval reset dan wajib review ulang |

### B. Waktu dan scheduler

| ID | Skenario | Hasil yang diharapkan |
|---|---|---|
| B01 | Supervisor sama dengan target | Ditolak |
| B02 | Supervisor mengajar 09.00–10.00, supervisi 09.30 | Ditolak |
| B03 | Aktivitas selesai 10.00, berikut mulai 10.00, buffer 0 | Tidak overlap |
| B04 | Aktivitas selesai 10.00, berikut mulai 10.05, buffer 10 menit | Ditolak; mulai 10.10 diperbolehkan |
| B05 | 2, 3, dan 4 pertemuan mengajar di tanggal sama | Ambang >=3 menolak kasus 3 dan 4 |
| B06 | Pertemuan dibatalkan/libur/jadwal di luar tahap | Tidak menjadi kandidat |
| B07 | Dua supervisi tumpang tindih lintas periode | Ditolak juga lintas periode |
| B08 | Aturan span 480 aktif: aktivitas 08.00 sampai 16.00 | Batas 480 valid; lewat satu menit invalid |
| B09 | Jadwal mengajar sendiri melebihi span | Tidak tambah supervisi; diagnosis terlihat |
| B10 | Manual dan AUTO bersaing pada waktu sama | Manual tetap; AUTO mutable dipindah/belum terjadwal |
| B11 | Manual bersaing dengan MANUAL/FINAL atau teaching | Tolak override, tidak memaksa |
| B12 | Target tanpa kandidat, target lain punya kandidat | Proposal parsial dan alasan, bukan data palsu |
| B13 | Solver timeout tanpa solusi dan tanpa bukti infeasible | UNKNOWN, bukan INFEASIBLE |
| B14 | Snapshot berubah saat solver berjalan | Apply 409/STALE dan existing tidak tertimpa |
| B15 | Dua override/apply bersamaan | Serialisasi transaksi menjaga satu reservasi valid |
| B16 | T2 sebelum/sama tanggal T1 | Ditolak; T2 sesudah T1 dan memenuhi gap |
| B17 | SAME/DIFFERENT supervisor atau limit minggu | Tepat menurut rule; minggu Senin–Minggu lokal |
| B18 | Timezone UTC tanggal berbeda dengan tanggal lokal | UI, hitungan harian dan PDF tetap konsisten |
| B19 | T2 difinalkan sebelum T1 final, atau T1 dibuka ulang saat T2 final | Ditolak sampai dependency tahap diselesaikan |
| B20 | AUTO mutable masuk snapshot solver | Tidak dibekukan sebagai reservasi tetap atau dihitung ganda |
| B21 | Apply berhasil menaikkan revision lalu finalisasi | Assignment hasil apply tidak otomatis stale; diperiksa ulang secara aktual |

Uji integration overlap/concurrency memakai DB engine sebenarnya. Contract tests PHP/Python menggunakan fixture interval bersama agar aturan keduanya tidak menyimpang.

### C. Form, file dan nilai

| ID | Skenario | Hasil yang diharapkan |
|---|---|---|
| C01 | SCORE=0 dan YES_NO=NO | Dihitung sudah terjawab |
| C02 | Required nonnilai/link/evidence kosong | Draft boleh; submit ditolak |
| C03 | Required conditional tidak applicable | Tidak menghalangi submit |
| C04 | Question/option dari template/form lain dikirim manual | Server menolak |
| C05 | SVG/executable menyamar gambar, over-size, decompression berlebih | Ditolak tanpa file publik |
| C06 | Upload gagal/PENDING tetapi supervisor submit | Tidak dihitung sebagai evidence lengkap |
| C07 | Dua tab menyimpan versi draft berbeda | 409; tidak silent overwrite |
| C08 | Submit berulang dengan key sama | Satu submission dan satu snapshot nilai |
| C09 | Key sama dipakai untuk payload berbeda | 409 idempotency conflict |
| C10 | Form/template/policy baru setelah hasil submitted | Hasil lama tetap sama |
| C11 | Reopen tahap dengan draft atau submitted | Ditolak pada P0; isian tidak hilang |
| C12 | Manipulasi client numeric_score/faculty/target | Diabaikan/ditolak; server menggunakan sumber otoritatif |

### Golden cases scoring

Angka berikut adalah test arithmetic, **bukan pilihan kebijakan LPM**:

| Input | MEAN_0_4 | LINEAR_1_4 | FLOOR_1_4 |
|---|---:|---:|---:|
| 29 jawaban semua 4, S=116 | 4.000000 | 4.000000 | 4.000000 |
| 29 jawaban semua 0, S=0 | 0.000000 | 1.000000 | 1.000000 |
| 20 jawaban semua 3, S=60 | 3.000000 | 3.250000 | 3.000000 |
| 20 jawaban, S=10 | 0.500000 | 1.375000 | 1.000000 |

N=0 atau satu jawaban scored wajib hilang harus error, bukan score 0. Nonnilai tidak menambah pembilang/penyebut. Pembulatan tampilan hanya pada akhir, misalnya 1.375000 → 1,38.

### D. Analitik dan PDF

| ID | Skenario | Hasil yang diharapkan |
|---|---|---|
| D-A01 | Dosen A: T1=3, T2=4; dosen B: T1=1, T2 belum ada | Rerata perubahan +1 dari 1 pasangan; bukan +2 |
| D-A02 | T1 saja, T2 saja, nol asli | Missing `—`; nol tetap nilai valid |
| D-A03 | Mode/template/policy berbeda | Tidak comparable, jumlah pasangan dijelaskan |
| D-A04 | 10 target ×2 tahap, baru 5 submitted; 3 belum ditugaskan | Progress 5/20=25%, bukan 5/jumlah assignment |
| D-A05 | Unit kelas berubah antara tahap | Delta unit tidak mencampur pasangan beda unit |
| D-P01 | Supervisi hanya tanggal tertentu pada rentang 3 minggu | Kuning hanya pada tanggal/minggu yang tepat |
| D-P02 | Nama panjang, banyak kelas, aktivitas Minggu | Semua informasi terbaca, page break tidak memotong |
| D-P03 | Jadwal dibuka ulang atau direvisi | PDF lama STALE, terbaru sesuai revisi |
| D-P04 | Supervisor mencoba unduh PDF supervisor lain | Ditolak server |

## 7. E2E penerimaan

Siapkan fixture demo dengan beberapa fakultas/prodi, kedua metode, dosen yang juga supervisor, minimal dua tahap, bentrok mengajar dan satu target awalnya tanpa kandidat.

1. Login LPM, buat term/periode/tahap demo dan pilih kebijakan demo berlabel.
2. Unggah dua layout PDF; koreksi satu field dan selesaikan identitas ambigu; commit.
3. Tetapkan scope serta supervisor berakun; generate dan tinjau hasil parsial.
4. Perbaiki data/penugasan yang diperlukan tanpa melanggar hard constraint; apply.
5. Override manual, rerun dan buktikan manual tetap; verifikasi audit.
6. Finalisasi tahap; unduh dan render PDF mingguan.
7. Login supervisor, isi form Offline/Online dari tugas sah; simpan draft, muat ulang, unggah bukti dan submit.
8. Ulangi tahap berikut; lihat detail hasil, comparable pairs dan missingness.
9. Coba akses akun lain dan perubahan data final yang dilarang; pastikan tertolak.
10. Restart worker/aplikasi, pastikan data tersimpan dan job tidak menggandakan output.

Saat instrumen/PDF resmi tersedia, ulangi bagian source verification dan acceptance dengan sumber tersebut. Jangan mengeklaim instrumen resmi telah lolos hanya berdasarkan fixture demo.

## 8. Verifikasi build dan laporan hasil

Perintah disesuaikan repository dan dependency yang benar-benar terpasang. Target minimum: lint/format check PHP, test PHP, test Python, build Vite, migration pada database test disposable, serta E2E. Perintah destruktif seperti `migrate:fresh` hanya boleh pada database test yang terverifikasi terpisah, tidak pada data pengguna.

Untuk setiap gate laporkan command aktual, exit code, ringkasan output, lingkungan, fixture dan hasil. Pisahkan:

- PASS: dijalankan dan memenuhi expected result.
- FAIL: dijalankan dan gagal, disertai diagnosis/perbaikan.
- NOT_RUN: belum dapat dijalankan karena lingkungan/tool tidak tersedia.
- BLOCKED: memerlukan sumber/kebijakan/akses tertentu dari luar implementasi.

Tidak menyebut “semua tes lulus” bila tes tidak dijalankan. Visual review tidak diganti dengan sekadar PDF file exists. Performance hanya dilaporkan bila diukur dengan jumlah data dan mesin yang dicatat.

## 9. Risiko dan mitigasi yang harus diwujudkan

| Risiko | Mitigasi implementasi |
|---|---|
| Layout PDF beragam | Adapter, provenance, review, correction dan fixture sumber nyata |
| Instrumen sumber tidak lengkap | Engine selesai; template resmi tetap belum publish |
| Formula/rule belum disahkan | Policy versioning, demo isolation dan gate operasional |
| Konflik scheduler atau capacity tidak cukup | Partial coverage, alasan, manual sah; tidak melonggarkan hard rules |
| Dua operator atau job bersamaan | Global lock, unique constraint, idempotency dan stale snapshot rejection |
| Histori berubah karena edit master | Snapshot dan versi immutable |
| File/bukti bocor | Private disk, resource policy dan test ownership |
| Kehilangan database/file | Backup gabungan dan restore drill |
| Package tidak kompatibel | Resolusi versi nyata, lockfile dan build di lingkungan target |

## 10. Checklist keluar dan serah terima

- [ ] Semua REQ pada traceability mempunyai kode dan bukti uji.
- [ ] Auth, role, mapping dosen, membership dan ownership bekerja.
- [ ] Term/periode/tahap/scope/kalender/occurrence konsisten.
- [ ] Parser nyata, review, deduplikasi dan commit atomik berjalan.
- [ ] OR-Tools nyata, H01–H08, partial solution dan diagnosis diuji.
- [ ] Manual locked, recalc, stale apply, concurrent write dan finalisasi aman.
- [ ] PDF personal bertanggal/kuning/revisi dirender dan diperiksa.
- [ ] Instrumen source-verified atau status BLOCKED dijelaskan dengan tepat.
- [ ] Draft, conditional required, evidence private dan submit atomik berjalan.
- [ ] Scoring reproducible dan kebijakan resmi tidak diklaim telah disahkan tanpa bukti.
- [ ] Dashboard/T1-T2 memakai sample/denominator/pasangan yang benar.
- [ ] README instalasi, `.env.example`, lockfiles dan runbook tersedia.
- [ ] Backup/restore, recovery job dan deployment lokal/staging diverifikasi sesuai akses.
- [ ] Laporan akhir menyebut selesai, belum diuji, blocker dan cara menjalankan.

Jika seluruh P0 teknis selesai tetapi D01–D07 belum tuntas, handoff yang benar adalah “MVP teknis selesai; operasional menunggu [daftar spesifik]”. Jangan mengurangi scope supaya dapat menyatakan 100% selesai, dan jangan berhenti membangun seluruh proyek hanya karena satu keputusan akademik belum tersedia.
