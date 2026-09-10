<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The title generator now names a conversation from the request, the answer to
 * it and the names of the uploaded files instead of from the request alone.
 *
 * Two things have to follow it into the database, because on a deployed host the
 * language payload does not come from resources/language/prompts_*.json:
 * LanguageController merges the ai_assistants_prompts rows over it, and with
 * ai_config_system on the database those rows are the only source.
 *
 *   1. the labels that separate the three parts, so they are translated and
 *      editable rather than hardcoded in the frontend
 *   2. one sentence appended to the Name Prompt, telling the model what that
 *      input now looks like and not to comment on content it cannot see
 *
 * Both are additive and marker guarded, so an admin's own edits survive and
 * running the migration twice changes nothing.
 */
return new class extends Migration
{
    private const TABLE = 'ai_assistants_prompts';

    /**
     * title => [language => content]
     */
    private const LABELS = [
        'Name Attachment Label' => [
            'de_DE' => ['content' => 'Datei:', 'description' => 'Bezeichnung der Dateinamen in der Eingabe der Titelerstellung'],
            'en_US' => ['content' => 'File:', 'description' => 'Label for the file names in the title generation input'],
        ],
        'Name Request Label' => [
            'de_DE' => ['content' => 'Anfrage:', 'description' => 'Bezeichnung der Anfrage in der Eingabe der Titelerstellung'],
            'en_US' => ['content' => 'Request:', 'description' => 'Label for the request in the title generation input'],
        ],
        'Name Response Label' => [
            'de_DE' => ['content' => 'Antwort:', 'description' => 'Bezeichnung der Antwort in der Eingabe der Titelerstellung'],
            'en_US' => ['content' => 'Response:', 'description' => 'Label for the response in the title generation input'],
        ],
    ];

    /**
     * Appended to the Name Prompt. The first few words are the marker that keeps
     * this idempotent, so they must stay in sync with the sentence itself.
     */
    private const NAME_PROMPT_ADDITION = [
        'de_DE' => 'Die Eingabe kann aus Dateinamen, Anfrage und Antwort bestehen - benenne das gemeinsame Thema. Kommentiere niemals Inhalte, die du nicht sehen kannst, und melde nichts als fehlend.',
        'en_US' => 'The input may consist of file names, a request and the answer to it - name the topic they share. Never comment on content you cannot see, and never report anything as missing.',
    ];

    private const MARKERS = [
        'de_DE' => 'Die Eingabe kann aus Dateinamen',
        'en_US' => 'The input may consist of file names',
    ];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $createdBy = DB::table('users')->min('id');
        if ($createdBy === null) {
            // A database without users cannot satisfy the created_by foreign key.
            // The seeder writes the same rows on first setup.
            return;
        }

        $this->insertMissingLabels((int) $createdBy);
        $this->extendNamePrompts();
        $this->forgetTranslationCaches();
    }

    /**
     * The labels are left in place: they are prompt text an admin may have
     * adjusted, and removing them would only fall back to the frontend defaults.
     */
    public function down(): void {}

    private function insertMissingLabels(int $createdBy): void
    {
        foreach (self::LABELS as $title => $languages) {
            foreach ($languages as $language => $row) {
                $exists = DB::table(self::TABLE)
                    ->where('title', $title)
                    ->where('language', $language)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table(self::TABLE)->insert([
                    'category' => 'utility',
                    'title' => $title,
                    'language' => $language,
                    'description' => $row['description'],
                    'content' => $row['content'],
                    'created_by' => $createdBy,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function extendNamePrompts(): void
    {
        foreach (self::NAME_PROMPT_ADDITION as $language => $addition) {
            $row = DB::table(self::TABLE)
                ->where('title', 'Name Prompt')
                ->where('language', $language)
                ->first();

            if ($row === null || str_contains((string) $row->content, self::MARKERS[$language])) {
                continue;
            }

            DB::table(self::TABLE)
                ->where('id', $row->id)
                ->update([
                    'content' => rtrim((string) $row->content).' '.$addition,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * The language payload is cached for an hour, so without this the new keys
     * would stay invisible until it expired - the frontend would fall back to
     * its own labels in the meantime.
     */
    private function forgetTranslationCaches(): void
    {
        foreach (array_keys(config('locale.langs', [])) as $prefix) {
            foreach (['db', 'config'] as $variant) {
                Cache::forget("translations_{$prefix}_ai_{$variant}");
                Cache::forget("json_translations_{$prefix}_ai_{$variant}");
            }
        }
    }
};
