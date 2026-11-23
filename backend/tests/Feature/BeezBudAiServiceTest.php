<?php

namespace Tests\Feature;

use App\Services\Engage\BeezBudAiService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class BeezBudAiServiceTest extends TestCase
{
    protected BeezBudAiService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openai.api_key' => 'test-openai-key',
            'services.openai.model' => 'gpt-6.2-sol',
            'services.openai.base_url' => 'https://api.openai.com/v1',
            'services.openai.timeout' => 60,
        ]);

        $this->service = app(BeezBudAiService::class);
    }

    private function successfulResponse(string $text = 'Hello! How can I help you?'): array
    {
        return [
            'id' => 'resp_test_001',
            'object' => 'response',
            'status' => 'completed',
            'output' => [
                [
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => $text,
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_beezbud_generates_a_response_successfully(): void
    {
        Http::fake([
            'api.openai.com/v1/responses' => Http::response(
                $this->successfulResponse(),
                200
            ),
        ]);

        $result = $this->service->generateResponse(
            'You are BeezBud, a helpful business assistant.',
            [
                [
                    'role' => 'user',
                    'content' => 'Hello.',
                ],
            ]
        );

        $this->assertSame(
            'Hello! How can I help you?',
            $result
        );

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.openai.com/v1/responses'
                && $request->hasHeader('Authorization', 'Bearer test-openai-key')
                && $request['model'] === 'gpt-6.2-sol'
                && $request['store'] === false
                && $request['reasoning']['effort'] === 'medium'
                && $request['max_output_tokens'] === 800
                && $request['input'][0]['role'] === 'developer'
                && $request['input'][1]['role'] === 'user';
        });
    }

    public function test_beezbud_uses_custom_output_token_limit(): void
    {
        Http::fake([
            'api.openai.com/v1/responses' => Http::response(
                $this->successfulResponse('Your order is ready.'),
                200
            ),
        ]);

        $result = $this->service->generateResponse(
            'You are BeezBud.',
            [
                ['role' => 'user', 'content' => 'Check my order.'],
            ],
            1200
        );

        $this->assertSame('Your order is ready.', $result);

        Http::assertSent(fn ($request) =>
            $request['max_output_tokens'] === 1200
        );
    }

    public function test_provider_error_is_reported_safely(): void
    {
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'error' => [
                    'message' => 'Provider internal error',
                ],
            ], 500),
        ]);

        try {
            $this->service->generateResponse(
                'You are BeezBud.',
                [
                    ['role' => 'user', 'content' => 'Hello.'],
                ]
            );

            $this->fail('Expected provider error was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'BeezBud could not generate a response.',
                $exception->getMessage()
            );
        }
    }

    public function test_connection_failure_is_reported_safely(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        try {
            $this->service->generateResponse(
                'You are BeezBud.',
                [
                    ['role' => 'user', 'content' => 'Hello.'],
                ]
            );

            $this->fail('Expected connection error was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'BeezBud could not connect to the AI provider.',
                $exception->getMessage()
            );

            $this->assertInstanceOf(
                ConnectionException::class,
                $exception->getPrevious()
            );
        }
    }

    public function test_missing_api_key_is_rejected(): void
    {
        config(['services.openai.api_key' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'BeezBud AI configuration is incomplete.'
        );

        $this->service->generateResponse(
            'You are BeezBud.',
            [
                ['role' => 'user', 'content' => 'Hello.'],
            ]
        );
    }

    public function test_missing_model_is_rejected(): void
    {
        config(['services.openai.model' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'BeezBud AI configuration is incomplete.'
        );

        $this->service->generateResponse(
            'You are BeezBud.',
            [
                ['role' => 'user', 'content' => 'Hello.'],
            ]
        );
    }

    public function test_empty_message_list_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'At least one input message is required.'
        );

        $this->service->generateResponse(
            'You are BeezBud.',
            []
        );
    }

    public function test_invalid_message_structure_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'BeezBud received an invalid conversation message.'
        );

        $this->service->generateResponse(
            'You are BeezBud.',
            [
                [
                    'role' => 'system',
                    'content' => 'Invalid role.',
                ],
            ]
        );
    }

    public function test_invalid_output_token_limit_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Invalid BeezBud output token limit.'
        );

        $this->service->generateResponse(
            'You are BeezBud.',
            [
                ['role' => 'user', 'content' => 'Hello.'],
            ],
            5000
        );
    }

    public function test_empty_provider_response_is_rejected(): void
    {
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output' => [],
            ], 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'BeezBud received an empty AI response.'
        );

        $this->service->generateResponse(
            'You are BeezBud.',
            [
                ['role' => 'user', 'content' => 'Hello.'],
            ]
        );
    }
}
