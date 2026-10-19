<?php

namespace Tests\Unit;

use App\Services\Scheduling\SchedulingInputSnapshot;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class SchedulingInputSnapshotHashTest extends TestCase
{
    /**
     * A basic unit test example.
     */
    #[DataProvider('canonicalInputs')]
    public function test_hash_remains_compatible_with_existing_snapshot_hashes(array $input, array $canonicalInput): void
    {
        $expected = hash('sha256', json_encode($canonicalInput, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));

        $this->assertSame($expected, (new SchedulingInputSnapshot)->hash($input));
    }

    public static function canonicalInputs(): array
    {
        return [
            'nested objects and scalar values' => [
                ['z' => (object) ['b' => false, 'a' => null], 'number' => 1.0, 'date' => new DateTimeImmutable('2026-10-19T08:30:00+07:00'), 'list' => ['Dosen / Penguji', 3, true]],
                ['date' => '2026-10-19T08:30:00.000000+07:00', 'list' => ['Dosen / Penguji', 3, true], 'number' => 1.0, 'z' => ['a' => null, 'b' => false]],
            ],
            'numeric keys become a list after sorting' => [
                ['items' => [1 => 'second', 0 => 'first']],
                ['items' => ['first', 'second']],
            ],
            'sparse numeric keys use string ordering' => [
                ['items' => [2 => 'two', 10 => 'ten']],
                ['items' => [10 => 'ten', 2 => 'two']],
            ],
            'empty values' => [
                ['z' => (object) [], 'a' => []],
                ['a' => [], 'z' => []],
            ],
        ];
    }

    public function test_invalid_utf8_is_rejected(): void
    {
        $this->expectException(JsonException::class);

        (new SchedulingInputSnapshot)->hash(['name' => "\xB1\x31"]);
    }

    public function test_serializable_dates_keep_the_existing_json_representation(): void
    {
        $input = ['date' => CarbonImmutable::parse('2026-10-19T08:30:00+07:00')];
        $expected = hash('sha256', '{"date":"2026-10-19T01:30:00.000000Z"}');

        $this->assertSame($expected, (new SchedulingInputSnapshot)->hash($input));
    }

    public function test_snapshots_exceeding_the_json_depth_limit_are_rejected(): void
    {
        $input = [];
        for ($depth = 0; $depth < 513; $depth++) {
            $input = [$input];
        }
        $this->expectException(JsonException::class);

        (new SchedulingInputSnapshot)->hash($input);
    }

    public function test_production_sized_candidate_snapshot_can_be_hashed_and_encoded_with_a_64_mb_memory_limit(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=64M'], dirname(__DIR__, 2));
        $process->setTimeout(30);
        $process->setInput(<<<'PHP'
<?php
require 'vendor/autoload.php';

$sources = ['occurrences' => []];
for ($index = 0; $index < 10000; $index++) {
    $sources['occurrences'][] = (object) ['id' => $index, 'teaching_schedule_id' => intdiv($index, 16), 'occurs_on' => '2026-10-20', 'starts_at' => '2026-10-20 01:00:00.000000', 'ends_at' => '2026-10-20 02:40:00.000000', 'status' => 'SCHEDULED'];
}
$candidates = [];
for ($index = 0; $index < 33000; $index++) {
    if ($index % 17 === 0) {
        $identity = ['lecturer_id' => $index % 109, 'course_id' => $index % 400, 'day_of_week' => 2, 'starts_local' => '08:00', 'ends_local' => '09:40', 'class_name' => 'Kelas Pengujian', 'room_name' => 'Ruang Pengujian', 'delivery_mode' => 'OFFLINE'];
    }
    $candidates[] = ['occurrence_id' => intdiv($index, 17), 'target_lecturer_id' => $index % 109, 'date' => '2026-10-20', 'day_of_week' => 2, 'start_time' => '08:00', 'end_time' => '09:40', 'schedule_identity' => $identity, 'delivery_mode' => 'OFFLINE', 'supervisor_lecturer_id' => $index % 17];
}
$input = ['sources' => $sources, 'solver' => ['candidates' => $candidates]];
$hash = (new App\Services\Scheduling\SchedulingInputSnapshot)->hash($input);
$run = new App\Models\ScheduleRun(['input_payload' => $input, 'input_hash' => $hash]);
echo $run->input_hash;
PHP);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $process->getOutput());
    }
}
