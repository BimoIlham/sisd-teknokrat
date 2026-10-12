<?php

namespace Tests\Feature;

use App\Services\PythonServiceConnection;
use Dompdf\Dompdf;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PythonAuthenticationIntegrationTest extends TestCase
{
    use CreatesSchedulingFixtures;

    public function test_laravel_bearer_contract_reaches_real_parser_and_solver_with_a_temporary_process_secret(): void
    {
        $python = base_path('python-service/venv/Scripts/python.exe');
        $this->assertFileExists($python);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        $url = 'http://127.0.0.1:'.$port;
        $secret = Str::random(64);
        $process = new Process([$python, '-m', 'uvicorn', 'app.main:app', '--host', '127.0.0.1',
            '--port', (string) $port, '--no-access-log'], base_path('python-service'), [
                'PYTHON_SERVICE_SECRET' => $secret, 'PYTHONDONTWRITEBYTECODE' => '1',
            ]);
        $process->setTimeout(30)->start();
        try {
            $ready = false;
            $deadline = microtime(true) + 15;
            while ($process->isRunning() && microtime(true) < $deadline) {
                try {
                    $ready = Http::connectTimeout(1)->timeout(1)->get($url.'/health')->successful();
                } catch (ConnectionException) {
                }
                if ($ready) {
                    break;
                }
                usleep(50000);
            }
            $this->assertTrue($ready, 'Isolated Python service must start successfully.');
            config(['services.python.url' => $url, 'services.python.secret' => $secret]);
            $pdf = new Dompdf;
            $pdf->loadHtml('<p style="font-size:8px">Dosen Pengajar Contoh Senin 09.00-10.40 Algoritma Pemrograman IF23A R. 301</p>');
            $pdf->render();
            $pdfBytes = $pdf->output();
            $payload = [
                'request_id' => 'authentication-integration', 'input_revision' => 1, 'input_hash' => 'synthetic-input',
                'period_id' => 1, 'stage_id' => 1, 'stage_no' => 1,
                'date_range' => ['starts_on' => '2026-10-01', 'ends_on' => '2026-10-31'], 'rules' => (object) [],
                'targets' => [['id' => 1, 'name' => 'Target']], 'supervisors' => [['id' => 2, 'name' => 'Supervisor']],
                'occurrences' => [['id' => 10, 'target_id' => 1, 'occurs_on' => '2026-10-05',
                    'starts_at' => '2026-10-05T02:00:00Z', 'ends_at' => '2026-10-05T03:40:00Z',
                    'course_name' => 'Course', 'class_name' => 'Class']],
            ];
            $send = function (PendingRequest $client, string $endpoint) use ($pdfBytes, $payload): Response {
                $client->connectTimeout(2)->timeout(10);

                return $endpoint === 'parser'
                    ? $client->attach('file', $pdfBytes, 'synthetic.pdf')->post('/v1/parse-schedule', ['metadata' => json_encode(['import_id' => 1])])
                    : $client->post('/v1/solve-assignments', $payload);
            };
            foreach (['parser', 'solver'] as $endpoint) {
                foreach (['missing', 'wrong', 'legacy'] as $kind) {
                    $client = Http::baseUrl($url);
                    if ($kind === 'wrong') {
                        $client->withToken(Str::random(64));
                    } elseif ($kind === 'legacy') {
                        $client->withHeaders(['X-Service-Token' => $secret]);
                    }
                    $response = $send($client, $endpoint);
                    $this->assertSame(401, $response->status());
                    $this->assertFalse(str_contains($response->body(), $secret), 'Response must not contain the temporary credential.');
                }
                $response = $send(app(PythonServiceConnection::class)->client(), $endpoint);
                $this->assertSame(200, $response->status());
                $this->assertFalse(str_contains($response->body(), $secret), 'Response must not contain the temporary credential.');
                if ($endpoint === 'solver') {
                    $this->assertSame([['target_id' => 1, 'supervisor_id' => 2, 'occurrence_id' => 10]], $response->json('assignments'));
                } else {
                    $this->assertSame(1, $response->json('page_count'));
                    $this->assertNotEmpty($response->json('rows'));
                    $this->assertSame('09:00', $response->json('rows.0.normalized.start_time'));
                }
            }
        } finally {
            $process->stop(1);
            $this->assertFalse(str_contains($process->getOutput().$process->getErrorOutput(), $secret), 'Python process output must not contain the temporary credential.');
        }
    }
}
