<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Transcription\Transcription;
use App\Models\Transcription\TranscriptionJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The S3 audio of a transcription goes when nothing can reach it anymore:
 * with the transcription it belongs to, or after the retention period for a
 * result that was never saved. It used to stay forever (KI-860).
 */
class TranscriptionArtifactsCleanupTest extends TestCase
{
    use RefreshDatabase;

    private int $users = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        config(['filesystems.disks.s3.bucket' => 'audio-ingest-test']);
    }

    private function user(): User
    {
        $n = ++$this->users;

        return User::create([
            'name' => "Test User $n", 'email' => "test$n@example.com", 'username' => "testuser$n",
            'publicKey' => 'key', 'employeetype' => 'staff', 'auth_type' => 'local', 'approval' => true,
        ]);
    }

    /** A job with an uploaded original and two chunks in S3. */
    private function job(User $user, array $attributes = [], ?\DateTimeInterface $at = null): TranscriptionJob
    {
        $id = (string) Str::uuid();
        $job = TranscriptionJob::create(array_merge([
            'id' => $id, 'user_id' => $user->id, 'status' => 'completed', 'file_path' => "uploads/$id/original.wav",
        ], $attributes));
        if ($at !== null) {
            TranscriptionJob::where('id', $id)->update(['created_at' => $at, 'updated_at' => $at]);
            $job->refresh();
        }
        foreach (["uploads/$id/original.wav", "jobs/$id/manifest.json", "jobs/$id/chunks/chunk_000.wav"] as $key) {
            Storage::disk('s3')->put($key, 'audio');
        }

        return $job;
    }

    private function transcription(User $user, array $metadata = [], ?\DateTimeInterface $at = null): Transcription
    {
        $t = Transcription::create(['title' => 'T', 'user_id' => $user->id, 'metadata' => $metadata]);
        if ($at !== null) {
            Transcription::where('id', $t->id)->update(['created_at' => $at]);
            $t->refresh();
        }

        return $t;
    }

    private function hasAudio(TranscriptionJob $job): bool
    {
        return Storage::disk('s3')->allFiles("uploads/{$job->id}") !== [] || Storage::disk('s3')->allFiles("jobs/{$job->id}") !== [];
    }

    private function exists(TranscriptionJob $job): bool
    {
        return TranscriptionJob::where('id', $job->id)->exists();
    }

    public function test_deleting_a_transcription_deletes_its_jobs_and_their_audio(): void
    {
        $user = $this->user();
        $linked = $this->job($user);
        $named = $this->job($user);          // named in the metadata only
        $other = $this->job($user);          // belongs to another transcription
        $transcription = $this->transcription($user, ['source_files' => [['job_id' => $linked->id], ['job_id' => $named->id]]]);
        $linked->update(['transcription_id' => $transcription->id]);
        $other->update(['transcription_id' => $this->transcription($user)->id]);

        $transcription->delete();

        $this->assertFalse($this->exists($linked));
        $this->assertFalse($this->hasAudio($linked));
        $this->assertFalse($this->exists($named));
        $this->assertFalse($this->hasAudio($named));
        $this->assertTrue($this->exists($other));
        $this->assertTrue($this->hasAudio($other));
    }

    public function test_a_job_named_by_a_transcription_of_another_user_is_not_deleted(): void
    {
        $owner = $this->user();
        $job = $this->job($owner);
        $foreign = $this->transcription($this->user(), ['job_id' => $job->id]);

        $foreign->delete();

        $this->assertTrue($this->exists($job));
        $this->assertTrue($this->hasAudio($job));
    }

    public function test_the_prune_deletes_only_jobs_no_transcription_can_reach(): void
    {
        $user = $this->user();
        $old = now()->subDays(30);

        $abandoned = $this->job($user, ['status' => 'completed'], $old);
        $failed = $this->job($user, ['status' => 'failed'], $old);
        $recent = $this->job($user, ['status' => 'completed'], now()->subDays(2));
        $named = $this->job($user, [], $old);
        $this->transcription($user, ['job_id' => $named->id], now()->subDays(5));
        $saved = $this->job($user, [], $old);
        $saved->update(['transcription_id' => $this->transcription($user)->id]);
        TranscriptionJob::where('id', $saved->id)->update(['updated_at' => $old]);

        // An old transcription that names no job finds its audio by time: a job
        // within two hours of it, of the same user, must stay.
        $fallback = $this->job($user, [], now()->subDays(60));
        $this->transcription($user, [], now()->subDays(60)->addHour());

        $this->assertSame(0, Artisan::call('transcription:prune-jobs'));

        foreach ([$abandoned, $failed] as $gone) {
            $this->assertFalse($this->exists($gone));
            $this->assertFalse($this->hasAudio($gone));
        }
        foreach ([$recent, $named, $saved, $fallback] as $kept) {
            $this->assertTrue($this->exists($kept), 'kept job was pruned');
            $this->assertTrue($this->hasAudio($kept), 'kept audio was deleted');
        }
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $job = $this->job($this->user(), [], now()->subDays(30));

        Artisan::call('transcription:prune-jobs', ['--dry-run' => true, '--orphans-in' => 'audio-ingest-test']);

        $this->assertTrue($this->exists($job));
        $this->assertTrue($this->hasAudio($job));
    }

    public function test_orphans_are_deleted_only_when_the_bucket_is_named(): void
    {
        $job = $this->job($this->user(), [], now()->subDays(30));
        $orphan = (string) Str::uuid();
        Storage::disk('s3')->put("jobs/$orphan/chunks/chunk_000.wav", 'audio');
        Storage::disk('s3')->put("uploads/$orphan/original.wav", 'audio');

        // The wrong bucket name stops the command before anything is deleted.
        $this->assertSame(1, Artisan::call('transcription:prune-jobs', ['--orphans-in' => 'audio-ingest']));
        $this->assertTrue($this->hasAudio($job));
        $this->assertNotSame([], Storage::disk('s3')->allFiles("jobs/$orphan"));

        $this->assertSame(0, Artisan::call('transcription:prune-jobs', ['--orphans-in' => 'audio-ingest-test']));
        $this->assertSame([], Storage::disk('s3')->allFiles("jobs/$orphan"));
        $this->assertSame([], Storage::disk('s3')->allFiles("uploads/$orphan"));
    }

    public function test_the_scheduled_prune_never_deletes_orphans(): void
    {
        $orphan = (string) Str::uuid();
        Storage::disk('s3')->put("jobs/$orphan/manifest.json", 'x');

        Artisan::call('transcription:prune-jobs');

        $this->assertNotSame([], Storage::disk('s3')->allFiles("jobs/$orphan"));
    }
}
