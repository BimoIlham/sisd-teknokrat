<?php

namespace App\Services\ScheduleImport;

class ScheduleRowValidator
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $issues
     * @return list<array<string, mixed>>
     */
    public function validate(array $payload, array $issues = []): array
    {
        foreach (['lecturer_name', 'course_name', 'class_name'] as $field) {
            if (trim((string) ($payload[$field] ?? '')) === '') {
                $issues[] = $this->issue($field, 'Nama dosen, mata kuliah, dan kelas harus terbaca dari PDF.');
            }
        }
        foreach (['lecturer_name', 'course_name', 'class_name', 'room_name'] as $field) {
            $value = trim((string) ($payload[$field] ?? ''));
            if ($value !== '' && $this->containsSuspiciousCharacters($field, $value)) {
                $issues[] = [
                    'code' => 'SUSPICIOUS_CHARACTERS',
                    'severity' => 'ERROR',
                    'field' => $field,
                    'message' => 'Ada simbol atau karakter encoding yang tidak lazim. Cocokkan teks ini dengan PDF sumber sebelum jadwal diaktifkan.',
                ];
            }
        }
        foreach (['class_name' => 100, 'room_name' => 100, 'course_name' => 255] as $field => $limit) {
            if (mb_strlen((string) ($payload[$field] ?? '')) > $limit) {
                $issues[] = $this->issue($field, 'Teks hasil ekstraksi melebihi batas kolom; periksa sel PDF sumber.');
            }
        }

        if (! in_array((int) ($payload['day_of_week'] ?? 0), range(1, 7), true)) {
            $issues[] = $this->issue('day_of_week', 'Hari perkuliahan tidak terbaca.');
        }

        $start = (string) ($payload['start_time'] ?? '');
        $end = (string) ($payload['end_time'] ?? '');
        if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start)
            || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $end)
            || $start >= $end) {
            $issues[] = $this->issue('time', 'Jam mulai dan selesai harus terbaca dan berurutan.');
        }

        $mode = $payload['delivery_mode'] ?? null;
        if (! in_array($mode, ['OFFLINE', 'ONLINE'], true)) {
            $issues[] = $this->issue('delivery_mode', 'Metode perkuliahan harus Offline atau Online. Periksa PDF sumber.');
        }
        if ($mode === 'OFFLINE' && trim((string) ($payload['room_name'] ?? '')) === '') {
            $issues[] = $this->issue('room_name', 'Ruang wajib terbaca untuk perkuliahan Offline.');
        }

        return $issues;
    }

    /** @return array{code: string, severity: string, field: string, message: string} */
    private function issue(string $field, string $message): array
    {
        return ['code' => 'MISSING_FIELD', 'severity' => 'ERROR', 'field' => $field, 'message' => $message];
    }

    private function containsSuspiciousCharacters(string $field, string $value): bool
    {
        if (preg_match('/(?:Ã.|Â.|â[^\p{L}\p{N}\s]|ï¿½|\x{FFFD})/u', $value) === 1) {
            return true;
        }

        $allowedCharacterPatterns = [
            'lecturer_name' => '/[^\p{L}\p{M}\p{N}\s.,\/()&+\x{27}\x{2019}-]/u',
            'course_name' => '/[^\p{L}\p{M}\p{N}\s.,\/()&+#:\x{27}\x{2019}-]/u',
            'class_name' => '/[^\p{L}\p{M}\p{N}\s.,\/()&_+\x{27}\x{2019}-]/u',
            'room_name' => '/[^\p{L}\p{M}\p{N}\s.,\/()&_+\x{27}\x{2019}-]/u',
        ];

        return isset($allowedCharacterPatterns[$field])
            && preg_match($allowedCharacterPatterns[$field], $value) === 1;
    }
}
