<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Models\Attachment;
use App\Models\Message;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * The files of a conversation, by the names the code interpreter offers them
 * under: /work/<name>.
 *
 * The sandbox has no network and every call is a fresh process, so a file the
 * model wants to work with has to travel with the call. Shipping every file of
 * the conversation on every call would be most of them wasted - a deck check
 * alone leaves eight slide previews behind - so the split is: reference in the
 * tool definition, transfer on demand. This class is the reference side. It
 * reads the request payload the frontend sends - the uploads listed on each
 * message, and the generated_image / container_file auxiliaries an assistant
 * turn carries for the pictures and decks it produced - plus what the tools of
 * the current request have produced so far, and gives each a stable,
 * human-readable name. The model names what it needs; {@see resolve()} turns
 * the name back into the attachment to read.
 *
 * Only attachments the requesting user may read are listed: their own, or one
 * on a message in a room they are a member of. Anything else in the payload is
 * dropped with a log line - the payload is the client's word, not proof.
 */
final class ConversationFiles
{
    /**
     * @var array<string,array{uuid: string, mime: string, noun: string, origin: string, turn: int, group: string, attachment: Attachment}>
     *      keyed by the /work name
     */
    private array $entries = [];

    /** @var array<string,string> uuid => name */
    private array $nameByUuid = [];

    /** @var array<int,string> names of the newest user message's uploads, oldest first */
    private array $newestUploads = [];

    /**
     * @param  array<int,array<string,mixed>>  $messages  the request payload's messages
     * @param  array<int,array<string,mixed>>  $produced  what {@see SandboxImages::produced()} holds for this request
     */
    public function __construct(array $messages, array $produced = [])
    {
        $this->build($messages, $produced);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /** @return array<int,string> */
    public function names(): array
    {
        return array_keys($this->entries);
    }

    /**
     * The uploads of the last user message - what a call gets without asking,
     * because a file attached to the question is meant for it. Uploads of
     * earlier turns are in the manifest, to be named like everything else.
     *
     * @return array<int,string>
     */
    public function newestUploads(): array
    {
        return $this->newestUploads;
    }

    /**
     * The attachment behind a name the model used: the /work name, with or
     * without the /work/ prefix, or the uuid. Null when there is no such file.
     */
    public function resolve(string $requested): ?Attachment
    {
        $name = trim($requested);
        if (str_starts_with($name, '/work/')) {
            $name = substr($name, strlen('/work/'));
        }
        $name = basename($name);

        if (isset($this->entries[$name])) {
            return $this->entries[$name]['attachment'];
        }

        if (isset($this->nameByUuid[$name])) {
            return $this->entries[$this->nameByUuid[$name]]['attachment'];
        }

        return null;
    }

    public function nameOf(string $uuid): ?string
    {
        return $this->nameByUuid[$uuid] ?? null;
    }

    /**
     * One line per file for the tool definition, oldest first, so the model
     * can name what it wants. The slide previews of a deck check - eight PNGs
     * from one run - share a line: every name is still there to copy, without
     * eight lines saying the same thing.
     */
    public function manifest(): string
    {
        if ($this->entries === []) {
            return '';
        }

        $groups = [];
        foreach ($this->entries as $name => $entry) {
            $groups[$entry['group']][] = ['name' => $name, 'noun' => $entry['noun'], 'origin' => $entry['origin']];
        }

        $lines = [];
        foreach ($groups as $members) {
            $names = array_map(static fn (array $m): string => '/work/'.$m['name'], $members);
            $what = count($members) === 1
                ? $members[0]['noun']
                : count($members).' '.$members[0]['noun'].'s';
            $lines[] = '- '.implode(', ', $names).' - '.$what.' '.$members[0]['origin'];
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int,array<string,mixed>>  $messages
     * @param  array<int,array<string,mixed>>  $produced
     */
    private function build(array $messages, array $produced): void
    {
        // [uuid, name hint, origin, turn, group] in conversation order.
        $candidates = [];
        $turn = 0;
        $newestUploadUuids = [];

        foreach (array_values($messages) as $index => $message) {
            $role = (string) ($message['role'] ?? 'user');
            $content = is_array($message['content'] ?? null) ? $message['content'] : [];

            if ($role === 'user') {
                $turn++;
            }

            $uuids = $this->uuidsOf($content['attachments'] ?? []);
            if ($role === 'user') {
                // The last user message's uploads, or none: a file attached to
                // an earlier question was for that question.
                $newestUploadUuids = $uuids;
            }

            // An assistant turn's auxiliaries first: they say what a file is
            // (a generated image, a deck the code built), where the plain
            // attachment list only says it was there.
            foreach ($role === 'assistant' ? $this->auxiliariesOf($content['auxiliaries'] ?? []) : [] as $auxiliary) {
                $isFile = $auxiliary['type'] === 'container_file';
                $fromCode = $isFile || str_starts_with($auxiliary['name'], 'sandbox_');

                $candidates[] = [
                    'uuid' => $auxiliary['uuid'],
                    'name' => $auxiliary['name'],
                    'noun' => $isFile ? 'file' : 'image',
                    'origin' => $isFile ? 'your code built in turn '.$turn
                        : ($fromCode ? 'from your code in turn '.$turn : 'you generated in turn '.$turn),
                    'turn' => $turn,
                    // Plots and slide previews of one run share a line in the manifest.
                    'group' => ! $isFile && $fromCode ? 'm'.$index.'-code-images' : 'm'.$index.'-'.$auxiliary['uuid'],
                ];
            }

            foreach ($uuids as $uuid) {
                $candidates[] = [
                    'uuid' => $uuid,
                    'name' => null,
                    'noun' => 'file',
                    'origin' => $role === 'user' ? 'attached by the user in turn '.$turn : 'from your answer in turn '.$turn,
                    'turn' => $turn,
                    'group' => 'm'.$index.'-attachment-'.$uuid,
                ];
            }
        }

        foreach ($produced as $i => $stored) {
            $uuid = (string) ($stored['uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }
            $isFile = ($stored['kind'] ?? null) === 'file';
            $candidates[] = [
                'uuid' => $uuid,
                'name' => (string) ($stored['name'] ?? ''),
                'noun' => $isFile ? 'file' : 'image',
                'origin' => 'produced earlier in this answer',
                'turn' => $turn,
                'group' => $isFile ? 'produced-'.$i : 'produced-images',
            ];
        }

        if ($candidates === []) {
            return;
        }

        $uuids = array_values(array_unique(array_column($candidates, 'uuid')));
        $attachments = Attachment::whereIn('uuid', $uuids)->get()->keyBy('uuid');

        $seenUuids = [];
        foreach ($candidates as $candidate) {
            $uuid = $candidate['uuid'];
            if (isset($seenUuids[$uuid])) {
                continue;
            }
            $seenUuids[$uuid] = true;

            $attachment = $attachments[$uuid] ?? null;
            if (! $attachment instanceof Attachment) {
                continue;
            }

            if (! $this->mayRead($attachment)) {
                Log::warning('[ConversationFiles] An attachment in the payload is not the requesting user\'s and is not offered', ['uuid' => $uuid]);

                continue;
            }

            $name = $this->workName($attachment, $candidate['name']);

            $this->entries[$name] = [
                'uuid' => $uuid,
                'mime' => (string) $attachment->mime,
                'noun' => $candidate['noun'],
                'origin' => $candidate['origin'],
                'turn' => $candidate['turn'],
                'group' => $candidate['group'],
                'attachment' => $attachment,
            ];
            $this->nameByUuid[$uuid] = $name;
        }

        foreach ($newestUploadUuids as $uuid) {
            if (isset($this->nameByUuid[$uuid])) {
                $this->newestUploads[] = $this->nameByUuid[$uuid];
            }
        }
    }

    /**
     * The /work name: the attachment's own file name, reduced to a basename
     * the sandbox accepts; the uuid in front when the conversation already has
     * a file of that name, so the model can tell the two apart.
     */
    private function workName(Attachment $attachment, ?string $hint): string
    {
        $name = basename(trim((string) ($attachment->name ?: $hint ?: '')));
        $short = substr((string) $attachment->uuid, 0, 8);

        if ($name === '' || $name === '.' || $name === '..' || str_starts_with($name, '.') || $name === 'code.py') {
            $name = 'attachment_'.$short;
        }

        if (isset($this->entries[$name])) {
            $name = $short.'_'.$name;
        }

        return $name;
    }

    /**
     * The requesting user's own attachment, or one on a message in a room
     * they are a member of.
     */
    private function mayRead(Attachment $attachment): bool
    {
        $userId = Auth::id();
        if ($userId === null) {
            return false;
        }

        if ((int) $attachment->user_id === (int) $userId) {
            return true;
        }

        if ($attachment->attachable_type === Message::class) {
            $message = $attachment->attachable;
            $room = $message instanceof Message ? $message->room : null;

            return $room !== null && $room->isMember($userId);
        }

        return false;
    }

    /**
     * @return array<int,string>
     */
    private function uuidsOf(mixed $attachments): array
    {
        if (! is_array($attachments)) {
            return [];
        }

        $uuids = [];
        foreach ($attachments as $attachment) {
            if (is_string($attachment) && $attachment !== '') {
                $uuids[] = $attachment;
            } elseif (is_array($attachment)) {
                $uuid = $attachment['uuid'] ?? ($attachment['fileData']['uuid'] ?? null);
                if (is_string($uuid) && $uuid !== '') {
                    $uuids[] = $uuid;
                }
            }
        }

        return $uuids;
    }

    /**
     * The generated_image and container_file auxiliaries of an assistant turn,
     * decoded: what the frontend remembers about the pictures and files that
     * answer produced.
     *
     * @return array<int,array{type: string, uuid: string, name: string}>
     */
    private function auxiliariesOf(mixed $auxiliaries): array
    {
        if (! is_array($auxiliaries)) {
            return [];
        }

        $found = [];
        foreach ($auxiliaries as $auxiliary) {
            $type = $auxiliary['type'] ?? null;
            if ($type !== 'generated_image' && $type !== 'container_file') {
                continue;
            }

            $content = $auxiliary['content'] ?? null;
            $data = is_string($content) ? json_decode($content, true) : $content;
            if (! is_array($data) || ! is_string($data['uuid'] ?? null) || $data['uuid'] === '') {
                continue;
            }

            $found[] = [
                'type' => $type,
                'uuid' => $data['uuid'],
                'name' => (string) ($data['name'] ?? ($data['filename'] ?? '')),
            ];
        }

        return $found;
    }
}
