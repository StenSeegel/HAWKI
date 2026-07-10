<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Transcription\TranscriptionJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The active-jobs endpoint is what lets the upload screen re-attach to running
 * transcriptions after the user left the page — every in-flight status must be
 * listed, plus completed jobs whose result was never saved (transcription_id
 * still null), so nothing silently disappears from the user's queue.
 */
class TranscriptionActiveJobsTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $suffix = ''): User
    {
        return User::create([
            'name' => 'Test User'.$suffix,
            'email' => "test{$suffix}@example.com",
            'username' => 'testuser'.$suffix,
            'publicKey' => 'test-public-key',
            'employeetype' => 'staff',
            'auth_type' => 'local',
            'approval' => true,
        ]);
    }

    protected function makeJob(User $user, string $id, string $status, array $overrides = []): TranscriptionJob
    {
        return TranscriptionJob::create(array_merge([
            'id' => $id,
            'user_id' => $user->id,
            'status' => $status,
            'file_path' => "uploads/{$id}/original.mp3",
            'manifest_data' => ['settings' => ['filename' => "{$id}.mp3"]],
        ], $overrides));
    }

    public function test_lists_all_in_flight_statuses_with_filename(): void
    {
        $user = $this->makeUser();

        $inFlight = [
            'analyzing_speakers_queued',
            'analyzing_speakers',
            'analyzed_speakers',
            'preprocessing',
            'preprocessed',
            'transcribing',
            'optimizing',
        ];
        foreach ($inFlight as $status) {
            $this->makeJob($user, "job-{$status}", $status);
        }

        // Not listed: still waiting for the browser upload (nothing to resume),
        // failed, and completed jobs already saved to a Transcription.
        $this->makeJob($user, 'job-created', 'created');
        $this->makeJob($user, 'job-failed', 'failed');
        $transcription = \App\Models\Transcription\Transcription::create([
            'title' => 'Saved',
            'user_id' => $user->id,
            'language' => 'de',
            'original_filename' => 'job-saved.mp3',
        ]);
        $this->makeJob($user, 'job-saved', 'completed', ['transcription_id' => $transcription->id]);

        $response = $this->actingAs($user)->getJson('/req/transcriptions/jobs/active');

        $response->assertOk()->assertJson(['success' => true]);
        $jobs = collect($response->json('jobs'));

        $this->assertEqualsCanonicalizing(
            array_map(fn ($s) => "job-{$s}", $inFlight),
            $jobs->pluck('id')->all()
        );
        $this->assertEquals('job-transcribing.mp3', $jobs->firstWhere('id', 'job-transcribing')['filename']);
    }

    public function test_lists_completed_job_whose_result_was_never_saved(): void
    {
        $user = $this->makeUser();
        $this->makeJob($user, 'job-unclaimed', 'completed', ['transcription_id' => null]);

        $response = $this->actingAs($user)->getJson('/req/transcriptions/jobs/active');

        $response->assertOk();
        $this->assertEquals(['job-unclaimed'], collect($response->json('jobs'))->pluck('id')->all());
    }

    public function test_does_not_list_other_users_jobs(): void
    {
        $owner = $this->makeUser('a');
        $other = $this->makeUser('b');
        $this->makeJob($owner, 'job-owner', 'transcribing');

        $response = $this->actingAs($other)->getJson('/req/transcriptions/jobs/active');

        $response->assertOk();
        $this->assertEquals([], $response->json('jobs'));
    }

    public function test_delete_job_removes_row_s3_artifacts_and_active_listing(): void
    {
        \Illuminate\Support\Facades\Storage::fake('s3');
        $s3 = \Illuminate\Support\Facades\Storage::disk('s3');

        $user = $this->makeUser();
        $job = $this->makeJob($user, 'job-delete-me', 'analyzed_speakers');
        $s3->put($job->file_path, 'audio-bytes');
        $s3->put("jobs/{$job->id}/chunks/chunk_000.wav", 'chunk-bytes');

        $response = $this->actingAs($user)->deleteJson('/req/transcription/async/job/job-delete-me');

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseMissing('transcription_jobs', ['id' => 'job-delete-me']);
        $this->assertFalse($s3->exists('uploads/job-delete-me/original.mp3'));
        $this->assertFalse($s3->exists('jobs/job-delete-me/chunks/chunk_000.wav'));

        $active = $this->actingAs($user)->getJson('/req/transcriptions/jobs/active');
        $this->assertEquals([], $active->json('jobs'));
    }

    public function test_cannot_delete_other_users_job(): void
    {
        $owner = $this->makeUser('a');
        $other = $this->makeUser('b');
        $this->makeJob($owner, 'job-foreign', 'analyzed_speakers');

        $response = $this->actingAs($other)->deleteJson('/req/transcription/async/job/job-foreign');

        $response->assertNotFound();
        $this->assertDatabaseHas('transcription_jobs', ['id' => 'job-foreign']);
    }
}
