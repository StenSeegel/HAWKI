<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Services\AI\Providers\AbstractRequest;
use PHPUnit\Framework\TestCase;

/**
 * A byte of broken UTF-8 anywhere in a payload used to answer json_encode()
 * with false, which cURL sends as an empty body - and the provider then reports
 * the request as missing its messages, naming nothing that would lead back to
 * the file that carried the byte.
 */
class RequestPayloadEncodingTest extends TestCase
{
    private function encode(array $payload): string
    {
        $method = new \ReflectionMethod(AbstractRequest::class, 'encodePayload');

        return $method->invoke(null, $payload);
    }

    public function test_a_payload_with_a_broken_byte_still_carries_its_messages(): void
    {
        $json = $this->encode([
            'model' => 'jlu/gemma-4-26b-it',
            'messages' => [['role' => 'user', 'content' => "hello \xB0\xFE world"]],
        ]);

        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('messages', $decoded, 'the body must never lose its messages');
        $this->assertSame('user', $decoded['messages'][0]['role']);
        $this->assertStringContainsString('hello', $decoded['messages'][0]['content']);
    }

    public function test_a_clean_payload_is_unchanged(): void
    {
        $payload = ['model' => 'm', 'messages' => [['role' => 'user', 'content' => 'Grüße']]];

        $this->assertSame(json_encode($payload), $this->encode($payload));
    }
}
