<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_aidialogue;

use core\http_client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use mod_aidialogue\local\ai_client;
use Psr\Http\Message\RequestInterface;

/**
 * Unit tests for ai_client HTTP wrapper, request formatting, and error handling.
 *
 * @package    mod_aidialogue
 * @category   test
 * @copyright  2026 Phung Vo Anh <phung.voanh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ai_client::class)]
final class ai_client_test extends \basic_testcase {
    /**
     * Test successful 200 OK chat completion verifies request payload, headers, and trimmed reply.
     */
    public function test_chat_success(): void {
        $body = json_encode([
            'choices' => [
                [
                    'finish_reason' => 'stop',
                    'message' => [
                        'content' => "  Hello from AI!\n\n[MOVE:session_open]  ",
                    ],
                ],
            ],
        ]);

        $capturedrequest = null;
        $capturedoptions = null;

        $mockhttp = $this->createMock(http_client::class);
        $mockhttp->expects($this->once())
            ->method('send')
            ->willReturnCallback(
                function (RequestInterface $req, array $opts) use ($body, &$capturedrequest, &$capturedoptions): Response {
                    $capturedrequest = $req;
                    $capturedoptions = $opts;
                    return new Response(200, [], $body);
                }
            );

        $messages = [['role' => 'user', 'content' => 'Hi']];
        $client = new ai_client($mockhttp);
        $reply = $client->chat(
            'https://api.openai.com/v1/',
            'test-api-key',
            'gpt-4o',
            $messages
        );

        $this->assertSame("Hello from AI!\n\n[MOVE:session_open]", $reply);

        // Verify request URI, method, headers, body, and Guzzle options.
        $this->assertNotNull($capturedrequest);
        $this->assertSame('POST', $capturedrequest->getMethod());
        $this->assertSame('https://api.openai.com/v1/chat/completions', (string) $capturedrequest->getUri());
        $this->assertSame(['Bearer test-api-key'], $capturedrequest->getHeader('Authorization'));
        $this->assertSame(['application/json'], $capturedrequest->getHeader('Content-Type'));

        $sentpayload = json_decode((string) $capturedrequest->getBody(), true);
        $this->assertSame('gpt-4o', $sentpayload['model']);
        $this->assertSame($messages, $sentpayload['messages']);
        $this->assertSame(1024, $sentpayload['max_tokens']);
        $this->assertSame(0.4, $sentpayload['temperature']);

        $this->assertFalse($capturedoptions[RequestOptions::HTTP_ERRORS]);
        $this->assertSame(60, $capturedoptions[RequestOptions::TIMEOUT]);
    }

    /**
     * Test HTTP 401 throws error:aiunauthorised exception.
     */
    public function test_chat_unauthorised(): void {
        $mockhttp = $this->createMock(http_client::class);
        $mockhttp->expects($this->once())
            ->method('send')
            ->willReturn(new Response(401, [], '{"error":{"message":"Invalid key"}}'));

        $client = new ai_client($mockhttp);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error:aiunauthorised', 'mod_aidialogue'));
        $client->chat('https://api.openai.com/v1', 'bad-key', 'gpt-4o', [['role' => 'user', 'content' => 'Hi']]);
    }

    /**
     * Test HTTP 429 throws error:airatelimited exception.
     */
    public function test_chat_rate_limited(): void {
        $mockhttp = $this->createMock(http_client::class);
        $mockhttp->expects($this->once())
            ->method('send')
            ->willReturn(new Response(429, [], '{"error":{"message":"Quota exceeded"}}'));

        $client = new ai_client($mockhttp);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error:airatelimited', 'mod_aidialogue'));
        $client->chat('https://api.openai.com/v1', 'key', 'gpt-4o', [['role' => 'user', 'content' => 'Hi']]);
    }

    /**
     * Data provider for {@see test_chat_http_error}.
     *
     * @return array
     */
    public static function chat_http_error_provider(): array {
        return [
            '400 with JSON error message' => [
                400,
                '{"error":{"message":"Model not found"}}',
                'Model not found',
            ],
            '503 with plain text body falls back to status code' => [
                503,
                'Service Unavailable',
                '503',
            ],
        ];
    }

    /**
     * Test non-2xx HTTP error (e.g. 400 and 503) throws error:aihttperror exception.
     *
     * @param int $statuscode HTTP status code returned by the endpoint.
     * @param string $responsebody Raw HTTP response body.
     * @param string $expecteddetail Expected detail parameter in error:aihttperror string.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('chat_http_error_provider')]
    public function test_chat_http_error(int $statuscode, string $responsebody, string $expecteddetail): void {
        $mockhttp = $this->createMock(http_client::class);
        $mockhttp->expects($this->once())
            ->method('send')
            ->willReturn(new Response($statuscode, [], $responsebody));

        $client = new ai_client($mockhttp);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error:aihttperror', 'mod_aidialogue', $expecteddetail));
        $client->chat('https://api.openai.com/v1', 'key', 'gpt-4o', [['role' => 'user', 'content' => 'Hi']]);
    }

    /**
     * Test GuzzleException throws error:aicurlfailed exception.
     */
    public function test_chat_curl_failed(): void {
        $mockhttp = $this->createMock(http_client::class);
        $mockhttp->expects($this->once())
            ->method('send')
            ->willThrowException(new ConnectException('Connection timed out', new Request('POST', 'https://api.openai.com/v1')));

        $client = new ai_client($mockhttp);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error:aicurlfailed', 'mod_aidialogue', 'Connection timed out'));
        $client->chat('https://api.openai.com/v1', 'key', 'gpt-4o', [['role' => 'user', 'content' => 'Hi']]);
    }

    /**
     * Test finish_reason = 'length' throws error:airesponsetruncated exception.
     */
    public function test_chat_response_truncated(): void {
        $body = json_encode([
            'choices' => [
                [
                    'finish_reason' => 'length',
                    'message' => [
                        'content' => 'Truncated output...',
                    ],
                ],
            ],
        ]);

        $mockhttp = $this->createMock(http_client::class);
        $mockhttp->expects($this->once())
            ->method('send')
            ->willReturn(new Response(200, [], $body));

        $client = new ai_client($mockhttp);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error:airesponsetruncated', 'mod_aidialogue'));
        $client->chat('https://api.openai.com/v1', 'key', 'gpt-4o', [['role' => 'user', 'content' => 'Hi']]);
    }

    /**
     * Test missing message content throws error:aiemptyresponse exception.
     */
    public function test_chat_empty_response(): void {
        $mockhttp = $this->createMock(http_client::class);
        $mockhttp->expects($this->once())
            ->method('send')
            ->willReturn(new Response(200, [], '{"choices":[]}'));

        $client = new ai_client($mockhttp);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error:aiemptyresponse', 'mod_aidialogue'));
        $client->chat('https://api.openai.com/v1', 'key', 'gpt-4o', [['role' => 'user', 'content' => 'Hi']]);
    }

    /**
     * Test invalid JSON response throws error:aiinvalidjson exception.
     */
    public function test_chat_invalid_json(): void {
        $mockhttp = $this->createMock(http_client::class);
        $mockhttp->expects($this->once())
            ->method('send')
            ->willReturn(new Response(200, [], 'not-valid-json'));

        $client = new ai_client($mockhttp);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error:aiinvalidjson', 'mod_aidialogue'));
        $client->chat('https://api.openai.com/v1', 'key', 'gpt-4o', [['role' => 'user', 'content' => 'Hi']]);
    }
}
