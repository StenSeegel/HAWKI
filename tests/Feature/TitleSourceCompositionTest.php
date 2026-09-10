<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\Transcription\GenerateTranscriptionTitle;
use App\Models\Transcription\Transcription;
use Tests\TestCase;

/**
 * Part two of the title generation bug: what text the title request is given.
 *
 * The composition itself lives in the frontend (composeTitleSource in
 * ai_chat_functions.js) and there is no JS test setup in this repo, so what is
 * pinned down here is the part that is testable in PHP: the labels the frontend
 * reads from the language payload, and the guard against a title that reports
 * missing content instead of naming it.
 */
class TitleSourceCompositionTest extends TestCase
{
    private const LABEL_KEYS = [
        'Name_Attachment_Label',
        'Name_Request_Label',
        'Name_Response_Label',
    ];

    /**
     * The labels separate the file names, the request and the answer in the text
     * the title generator is given. They are prompt text and must be
     * translatable, so they live next to Name_Prompt rather than in the
     * frontend, which only carries English fallbacks for an installation whose
     * language payload predates them.
     */
    public function test_the_prompt_files_carry_the_title_source_labels(): void
    {
        foreach (['de_DE', 'en_US'] as $language) {
            $prompts = json_decode(
                file_get_contents(resource_path("language/prompts_{$language}.json")),
                true
            );

            foreach (self::LABEL_KEYS as $key) {
                $this->assertArrayHasKey($key, $prompts, "{$key} is missing for {$language}.");
                $this->assertNotSame('', trim((string) $prompts[$key]));
            }
        }
    }

    /**
     * The composed input is a labelled list now, so the prompt has to say what
     * that input looks like - and that reporting absent content is never a
     * title. The database rows shadow this file on a deployed host; the
     * migration appends the same sentence there.
     */
    public function test_the_name_prompt_describes_the_composed_input(): void
    {
        $de = json_decode(file_get_contents(resource_path('language/prompts_de_DE.json')), true);
        $en = json_decode(file_get_contents(resource_path('language/prompts_en_US.json')), true);

        $this->assertStringContainsString('Die Eingabe kann aus Dateinamen', $de['Name_Prompt']);
        $this->assertStringContainsString('melde nichts als fehlend', $de['Name_Prompt']);

        $this->assertStringContainsString('The input may consist of file names', $en['Name_Prompt']);
        $this->assertStringContainsString('never report anything as missing', $en['Name_Prompt']);
    }

    public static function missingContentTitles(): array
    {
        return [
            // The three titles actually observed on ki-test.
            ['Keine Datei vorhanden'],
            ['Kein Dokument vorhanden'],
            ['Kein Text erhalten'],
            ['Keine Datei angehängt'],
            ['No file provided'],
            ['No document available'],
        ];
    }

    public static function usableTitles(): array
    {
        return [
            ['Quartalsbericht 2026 Analyse'],
            ['Keine Ahnung Podcast'],
            ['Nordsee Klimawandel Studie'],
            ['No Time Left'],
        ];
    }

    /**
     * @dataProvider missingContentTitles
     */
    public function test_a_title_reporting_missing_content_is_rejected(string $title): void
    {
        $this->assertFalse($this->isValid($title), "'{$title}' must not become a title.");
    }

    /**
     * The guard is language bound and therefore narrow on purpose: it must not
     * swallow a title that happens to start with a negation.
     *
     * @dataProvider usableTitles
     */
    public function test_an_ordinary_title_is_kept(string $title): void
    {
        $this->assertTrue($this->isValid($title), "'{$title}' is a usable title.");
    }

    private function isValid(string $title): bool
    {
        $job = new GenerateTranscriptionTitle(new Transcription());

        $method = new \ReflectionMethod($job, 'isValidGeneratedTitle');
        $method->setAccessible(true);

        return (bool) $method->invoke($job, $title);
    }
}
