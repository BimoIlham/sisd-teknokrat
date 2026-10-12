<?php

namespace Database\Seeders;

use App\Models\FormSection;
use App\Models\FormTemplate;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\ScoringPolicy;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoInstrumentSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::first();

        // 1. Seed Scoring Policies
        $policyMean = ScoringPolicy::firstOrCreate(
            ['code' => 'DEMO_POLICY_MEAN', 'version' => 1],
            [
                'strategy' => 'MEAN_0_4',
                'config' => [
                    'source_min' => 0,
                    'source_max' => 4,
                    'aggregation' => 'UNWEIGHTED',
                    'stored_precision' => 6,
                    'display_precision' => 2,
                    'rounding' => 'HALF_UP',
                ],
                'status' => 'APPROVED',
                'is_demo' => true,
                'approved_by' => $admin?->id,
                'approved_at' => now(),
                'approval_note' => 'Kebijakan nilai demo default LPM (Rata-rata 0-4)',
            ]
        );

        ScoringPolicy::firstOrCreate(
            ['code' => 'DEMO_POLICY_LINEAR', 'version' => 1],
            [
                'strategy' => 'LINEAR_1_4',
                'config' => [
                    'source_min' => 0,
                    'source_max' => 4,
                    'aggregation' => 'UNWEIGHTED',
                    'stored_precision' => 6,
                    'display_precision' => 2,
                    'rounding' => 'HALF_UP',
                ],
                'status' => 'APPROVED',
                'is_demo' => true,
                'approved_by' => $admin?->id,
                'approved_at' => now(),
                'approval_note' => 'Kebijakan nilai demo alternatif LPM (Konversi Linier Skala 1-4)',
            ]
        );

        ScoringPolicy::firstOrCreate(
            ['code' => 'DEMO_POLICY_FLOOR', 'version' => 1],
            [
                'strategy' => 'FLOOR_1_4',
                'config' => [
                    'source_min' => 0,
                    'source_max' => 4,
                    'aggregation' => 'UNWEIGHTED',
                    'stored_precision' => 6,
                    'display_precision' => 2,
                    'rounding' => 'HALF_UP',
                ],
                'status' => 'APPROVED',
                'is_demo' => true,
                'approved_by' => $admin?->id,
                'approved_at' => now(),
                'approval_note' => 'Kebijakan nilai demo alternatif LPM (Floor Skala 1-4)',
            ]
        );

        // 2. Seed Offline Template (29 Scored Questions across 9 sections)
        $offlineTemplate = FormTemplate::firstOrCreate(
            ['code' => 'DEMO_OFFLINE', 'version' => 1],
            [
                'name' => 'Instrumen Supervisi Perkuliahan Tatap Muka (Offline)',
                'delivery_mode' => 'OFFLINE',
                'status' => 'PUBLISHED',
                'is_demo' => true,
                'expected_scored_count' => 29,
                'source_path' => 'docs/spec/PRD.md',
                'published_by' => $admin?->id,
                'published_at' => now(),
            ]
        );

        $offlineSections = [
            ['code' => 'SEC_OFF_1', 'title' => 'Pembukaan Pembelajaran', 'count' => 3],
            ['code' => 'SEC_OFF_2', 'title' => 'Penguasaan Materi Pembelajaran', 'count' => 4],
            ['code' => 'SEC_OFF_3', 'title' => 'Kegiatan Evaluasi', 'count' => 3],
            ['code' => 'SEC_OFF_4', 'title' => 'Metodologi Pembelajaran', 'count' => 4],
            ['code' => 'SEC_OFF_5', 'title' => 'Penggunaan Media Pembelajaran', 'count' => 3],
            ['code' => 'SEC_OFF_6', 'title' => 'Manajemen Kelas', 'count' => 4],
            ['code' => 'SEC_OFF_7', 'title' => 'Sikap Dosen Dalam Pembelajaran', 'count' => 4],
            ['code' => 'SEC_OFF_8', 'title' => 'Refleksi dan Menyimpulkan Materi', 'count' => 4],
            ['code' => 'SEC_OFF_9', 'title' => 'Upload Bukti & Evaluasi', 'count' => 0], // Non-score
        ];

        $qIndex = 1;
        foreach ($offlineSections as $sOrder => $secData) {
            $section = FormSection::firstOrCreate(
                ['form_template_id' => $offlineTemplate->id, 'code' => $secData['code']],
                ['title' => $secData['title'], 'sort_order' => $sOrder + 1]
            );

            for ($i = 1; $i <= $secData['count']; $i++) {
                $qCode = sprintf('OFF_Q%02d', $qIndex);
                $question = Question::firstOrCreate(
                    ['form_template_id' => $offlineTemplate->id, 'code' => $qCode],
                    [
                        'form_section_id' => $section->id,
                        'question_text' => "Butir {$qIndex}: Kemampuan dosen terkait {$secData['title']} indikator {$i}",
                        'help_text' => 'Beri penilaian berdasarkan pengamatan langsung di kelas.',
                        'question_type' => 'SCORE',
                        'is_required' => true,
                        'is_scored' => true,
                        'sort_order' => $i,
                    ]
                );

                $options = [
                    ['val' => '0', 'score' => 0.0, 'label' => 'Sangat Kurang / Tidak Dilakukan'],
                    ['val' => '1', 'score' => 1.0, 'label' => 'Kurang Memenuhi'],
                    ['val' => '2', 'score' => 2.0, 'label' => 'Cukup Memenuhi'],
                    ['val' => '3', 'score' => 3.0, 'label' => 'Baik / Sesuai Standar'],
                    ['val' => '4', 'score' => 4.0, 'label' => 'Sangat Baik / Melampaui Standar'],
                ];
                foreach ($options as $optOrder => $opt) {
                    QuestionOption::firstOrCreate(
                        ['question_id' => $question->id, 'value' => $opt['val']],
                        ['numeric_score' => $opt['score'], 'label' => $opt['label'], 'sort_order' => $optOrder + 1]
                    );
                }
                $qIndex++;
            }

            // For section 9 (non-score items)
            if ($secData['code'] === 'SEC_OFF_9') {
                // YES_NO: Bahasa Inggris
                $qYesNo = Question::firstOrCreate(
                    ['form_template_id' => $offlineTemplate->id, 'code' => 'OFF_EN_SLIDE'],
                    [
                        'form_section_id' => $section->id,
                        'question_text' => 'Apakah terdapat materi slide / instruksi Bahasa Inggris pada sistem SPADA?',
                        'question_type' => 'YES_NO',
                        'is_required' => true,
                        'is_scored' => false,
                        'sort_order' => 1,
                    ]
                );
                QuestionOption::firstOrCreate(['question_id' => $qYesNo->id, 'value' => 'YES'], ['label' => 'Ya, tersedia', 'sort_order' => 1]);
                QuestionOption::firstOrCreate(['question_id' => $qYesNo->id, 'value' => 'NO'], ['label' => 'Tidak tersedia', 'sort_order' => 2]);

                // LINK: SPADA link
                Question::firstOrCreate(
                    ['form_template_id' => $offlineTemplate->id, 'code' => 'OFF_SPADA_LINK'],
                    [
                        'form_section_id' => $section->id,
                        'question_text' => 'Tautan Pembuktian Perkuliahan di SPADA',
                        'help_text' => 'Masukkan URL tautan kelas pada SPADA Teknokrat.',
                        'question_type' => 'LINK',
                        'is_required' => false,
                        'is_scored' => false,
                        'sort_order' => 2,
                    ]
                );

                // FILE: Evidence Photo
                Question::firstOrCreate(
                    ['form_template_id' => $offlineTemplate->id, 'code' => 'OFF_EVIDENCE_PHOTO'],
                    [
                        'form_section_id' => $section->id,
                        'question_text' => 'Unggah Foto Bukti Pelaksanaan Supervisi di Ruang Kelas',
                        'help_text' => 'Format JPG, PNG, atau WebP maksimum 10MB.',
                        'question_type' => 'FILE',
                        'is_required' => true,
                        'is_scored' => false,
                        'sort_order' => 3,
                    ]
                );

                // TEXTAREA: Evaluasi Umum
                Question::firstOrCreate(
                    ['form_template_id' => $offlineTemplate->id, 'code' => 'EVALUASI_UMUM'],
                    [
                        'form_section_id' => $section->id,
                        'question_text' => 'Catatan Evaluasi, Kritik, dan Saran Dosen Pengamat',
                        'help_text' => 'Berikan masukan konstruktif untuk peningkatan mutu perkuliahan.',
                        'question_type' => 'TEXTAREA',
                        'is_required' => true,
                        'is_scored' => false,
                        'sort_order' => 4,
                    ]
                );
            }
        }

        // 3. Seed Online Template (20 Scored Questions across 4 sections)
        $onlineTemplate = FormTemplate::firstOrCreate(
            ['code' => 'DEMO_ONLINE', 'version' => 1],
            [
                'name' => 'Instrumen Supervisi Perkuliahan Daring (Online/SPADA)',
                'delivery_mode' => 'ONLINE',
                'status' => 'PUBLISHED',
                'is_demo' => true,
                'expected_scored_count' => 20,
                'source_path' => 'docs/spec/PRD.md',
                'published_by' => $admin?->id,
                'published_at' => now(),
            ]
        );

        $onlineSections = [
            ['code' => 'SEC_ON_1', 'title' => 'Perencanaan', 'count' => 5],
            ['code' => 'SEC_ON_2', 'title' => 'Proses Pembelajaran / Asinkronus', 'count' => 9],
            ['code' => 'SEC_ON_3', 'title' => 'Evaluasi', 'count' => 6],
            ['code' => 'SEC_ON_4', 'title' => 'Upload Bukti & Evaluasi', 'count' => 0], // Non-score
        ];

        $onIndex = 1;
        foreach ($onlineSections as $sOrder => $secData) {
            $section = FormSection::firstOrCreate(
                ['form_template_id' => $onlineTemplate->id, 'code' => $secData['code']],
                ['title' => $secData['title'], 'sort_order' => $sOrder + 1]
            );

            for ($i = 1; $i <= $secData['count']; $i++) {
                $qCode = sprintf('ON_Q%02d', $onIndex);
                $question = Question::firstOrCreate(
                    ['form_template_id' => $onlineTemplate->id, 'code' => $qCode],
                    [
                        'form_section_id' => $section->id,
                        'question_text' => "Butir {$onIndex}: Mutu perencanaan dan konten pembelajaran daring indikator {$i}",
                        'help_text' => 'Beri penilaian berdasarkan ketersediaan materi dan keaktifan di SPADA / V-Class.',
                        'question_type' => 'SCORE',
                        'is_required' => true,
                        'is_scored' => true,
                        'sort_order' => $i,
                    ]
                );

                $options = [
                    ['val' => '0', 'score' => 0.0, 'label' => 'Sangat Kurang / Tidak Dilakukan'],
                    ['val' => '1', 'score' => 1.0, 'label' => 'Kurang Memenuhi'],
                    ['val' => '2', 'score' => 2.0, 'label' => 'Cukup Memenuhi'],
                    ['val' => '3', 'score' => 3.0, 'label' => 'Baik / Sesuai Standar'],
                    ['val' => '4', 'score' => 4.0, 'label' => 'Sangat Baik / Melampaui Standar'],
                ];
                foreach ($options as $optOrder => $opt) {
                    QuestionOption::firstOrCreate(
                        ['question_id' => $question->id, 'value' => $opt['val']],
                        ['numeric_score' => $opt['score'], 'label' => $opt['label'], 'sort_order' => $optOrder + 1]
                    );
                }
                $onIndex++;
            }

            if ($secData['code'] === 'SEC_ON_4') {
                $qYesNo = Question::firstOrCreate(
                    ['form_template_id' => $onlineTemplate->id, 'code' => 'ON_EN_CONTENT'],
                    [
                        'form_section_id' => $section->id,
                        'question_text' => 'Apakah modul dan forum perkuliahan daring menggunakan pengantar Bahasa Inggris?',
                        'question_type' => 'YES_NO',
                        'is_required' => true,
                        'is_scored' => false,
                        'sort_order' => 1,
                    ]
                );
                QuestionOption::firstOrCreate(['question_id' => $qYesNo->id, 'value' => 'YES'], ['label' => 'Ya, menggunakan', 'sort_order' => 1]);
                QuestionOption::firstOrCreate(['question_id' => $qYesNo->id, 'value' => 'NO'], ['label' => 'Tidak menggunakan', 'sort_order' => 2]);

                Question::firstOrCreate(
                    ['form_template_id' => $onlineTemplate->id, 'code' => 'ON_SPADA_LINK'],
                    [
                        'form_section_id' => $section->id,
                        'question_text' => 'Tautan Bukti Aktivitas SPADA / Zoom',
                        'question_type' => 'LINK',
                        'is_required' => true,
                        'is_scored' => false,
                        'sort_order' => 2,
                    ]
                );

                Question::firstOrCreate(
                    ['form_template_id' => $onlineTemplate->id, 'code' => 'ON_SCREENSHOT_PHOTO'],
                    [
                        'form_section_id' => $section->id,
                        'question_text' => 'Tangkapan Layar (Screenshot) Rekam Aktivitas Daring',
                        'question_type' => 'FILE',
                        'is_required' => true,
                        'is_scored' => false,
                        'sort_order' => 3,
                    ]
                );

                Question::firstOrCreate(
                    ['form_template_id' => $onlineTemplate->id, 'code' => 'EVALUASI_UMUM'],
                    [
                        'form_section_id' => $section->id,
                        'question_text' => 'Catatan Evaluasi dan Rekomendasi Supervisi Daring',
                        'question_type' => 'TEXTAREA',
                        'is_required' => true,
                        'is_scored' => false,
                        'sort_order' => 4,
                    ]
                );
            }
        }
    }
}
