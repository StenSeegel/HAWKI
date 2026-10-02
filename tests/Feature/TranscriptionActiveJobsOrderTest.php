<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Transcription\TranscriptionController;
use App\Models\Transcription\TranscriptionJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The running jobs are listed newest first with their file name, without
 * MySQL sorting the rows: a long recording's manifest is larger than the sort
 * buffer, and the ORDER BY failed with "Out of sort memory".
 */
class TranscriptionActiveJobsOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_running_jobs_come_newest_first_with_their_file_name(): void
    {
        $user = User::create([
            'name' => 'Test User', 'email' => 'test@example.com', 'username' => 'testuser',
            'publicKey' => 'key', 'employeetype' => 'staff', 'auth_type' => 'local', 'approval' => true,
        ]);

        // A manifest the size of a long recording's, one entry per chunk.
        $chunks = array_fill(0, 2000, ['path' => 's3://bucket/jobs/x/chunks/chunk_000.wav', 'start' => 0.0, 'end' => 30.0]);
        foreach (['older.wav' => 3, 'newest.wav' => 1, 'middle.wav' => 2] as $name => $hoursAgo) {
            $id = (string) Str::uuid();
            TranscriptionJob::create([
                'id' => $id, 'user_id' => $user->id, 'status' => 'transcribing', 'file_path' => "uploads/$id/original.wav",
                'manifest_data' => ['settings' => ['filename' => $name], 'chunks' => $chunks],
            ]);
            TranscriptionJob::where('id', $id)->update(['created_at' => now()->subHours($hoursAgo)]);
        }

        Auth::login($user);
        $response = app(TranscriptionController::class)->getActiveJobs(request());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['newest.wav', 'middle.wav', 'older.wav'], array_column($response->getData(true)['jobs'], 'filename'));
    }
}
