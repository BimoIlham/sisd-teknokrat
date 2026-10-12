<?php

namespace Database\Seeders;

use App\Models\AcademicTerm;
use Illuminate\Database\Seeder;

class AcademicTermSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $termsData = [
            ['semester' => 'GANJIL', 'academic_year' => '2026/2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-01-31'],
            ['semester' => 'GENAP', 'academic_year' => '2026/2027', 'starts_on' => '2027-02-01', 'ends_on' => '2027-07-31'],
        ];

        foreach ($termsData as $tData) {
            AcademicTerm::firstOrCreate(
                ['semester' => $tData['semester'], 'academic_year' => $tData['academic_year']],
                [
                    'starts_on' => $tData['starts_on'],
                    'ends_on' => $tData['ends_on'],
                    'timezone' => 'Asia/Jakarta',
                ]
            );
        }
    }
}
