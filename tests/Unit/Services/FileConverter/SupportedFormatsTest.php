<?php

declare(strict_types=1);

namespace Tests\Unit\Services\FileConverter;

use App\Services\FileConverter\SupportedFormats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The converter decides which file types a chat upload may be - HAWKI asks it
 * and caches the answer. What HAWKI decides on top is what is never allowed
 * (archives) and what an admin turned off (audio and video by default).
 */
class SupportedFormatsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'file_converter.default' => 'hawki_converter',
            'file_converter.converters.hawki_converter.api_url' => 'http://file-converter/extract',
            'file_converter.converters.hawki_converter.api_key' => 'secret',
            'file_converter.never_accept' => ['zip', 'tar', 'gz', 'tgz', '7z', 'pst'],
            'file_converter.excluded_extensions' => ['mp3', 'mpga', 'm4a', 'wav', 'webm', 'mp4', 'mpeg'],
        ]);

        Cache::flush();
    }

    private function fakeConverter(array $formats): void
    {
        Http::fake([
            'http://file-converter/' => Http::response(['version' => '3.0.2', 'supported_formats' => $formats]),
        ]);
    }

    public function test_the_list_comes_from_the_converter(): void
    {
        $this->fakeConverter(['.pdf', '.pptx', '.epub', '.eml', '.adoc']);

        $formats = new SupportedFormats();

        $this->assertEqualsCanonicalizing(['pdf', 'pptx', 'epub', 'eml', 'adoc', 'drawio'], $formats->extensions());
        $this->assertTrue($formats->accepts('slides.PPTX'));
        $this->assertTrue($formats->accepts('mail.eml'));
        $this->assertFalse($formats->accepts('notes.odt'));

        Http::assertSent(fn($request) => $request->url() === 'http://file-converter/'
            && $request->hasHeader('Authorization', 'Bearer secret'));
    }

    public function test_the_converter_is_asked_once_and_the_answer_is_cached(): void
    {
        $this->fakeConverter(['.pdf']);

        (new SupportedFormats())->extensions();
        (new SupportedFormats())->extensions();

        Http::assertSentCount(1);
    }

    public function test_an_unreachable_converter_falls_back_to_the_static_list(): void
    {
        Http::fake(fn() => throw new ConnectionException('Connection refused'));

        $formats = new SupportedFormats();

        $this->assertTrue($formats->accepts('paper.pdf'));
        $this->assertTrue($formats->accepts('deck.pptx'));
        $this->assertTrue($formats->accepts('book.epub'));
        $this->assertFalse($formats->accepts('installer.exe'));
    }

    public function test_archives_are_refused_even_when_the_deny_list_is_empty(): void
    {
        config(['file_converter.excluded_extensions' => []]);
        $this->fakeConverter(['.pdf', '.zip', '.tar', '.gz', '.tgz', '.7z', '.pst', '.mp3']);

        $formats = new SupportedFormats();

        foreach (['a.zip', 'a.tar', 'a.gz', 'a.tgz', 'a.7z', 'mail.pst'] as $archive) {
            $this->assertFalse($formats->accepts($archive), $archive);
        }

        // Audio is only off by configuration, so an empty deny list lets it in.
        $this->assertTrue($formats->accepts('talk.mp3'));
    }

    public function test_audio_and_video_are_off_by_default(): void
    {
        $this->fakeConverter(['.pdf', '.mp3', '.wav', '.mp4', '.webm', '.mpeg', '.m4a', '.mpga']);

        $formats = new SupportedFormats();

        foreach (['talk.mp3', 'talk.wav', 'clip.mp4', 'clip.webm', 'clip.mpeg', 'talk.m4a', 'talk.mpga'] as $media) {
            $this->assertFalse($formats->accepts($media), $media);
        }
        $this->assertTrue($formats->accepts('paper.pdf'));
    }

    public function test_a_converter_that_answers_with_nothing_usable_falls_back(): void
    {
        $this->fakeConverter([]);

        $this->assertTrue((new SupportedFormats())->accepts('paper.pdf'));
    }

    public function test_the_mime_of_an_extension_comes_from_the_config_table(): void
    {
        $formats = new SupportedFormats();

        $this->assertSame('application/vnd.openxmlformats-officedocument.presentationml.presentation', $formats->mimeFor('pptx'));
        $this->assertSame('text/asciidoc', $formats->mimeFor('deck.adoc'));
        $this->assertSame('message/rfc822', $formats->mimeFor('.EML'));
        $this->assertNull($formats->mimeFor('exe'));
    }

    public function test_the_frontend_payload_carries_the_accept_attribute(): void
    {
        $this->fakeConverter(['.pdf', '.pptx']);

        $payload = (new SupportedFormats())->forFrontend();

        $this->assertSame(['drawio', 'pdf', 'pptx'], $payload['extensions']);
        $this->assertSame('.drawio,.pdf,.pptx', $payload['accept']);
        $this->assertSame('application/pdf', $payload['mimes']['pdf']);
    }
}
