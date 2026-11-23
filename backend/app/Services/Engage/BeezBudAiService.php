<?php

namespace App\Services\Engage;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class BeezBudAiService
{
    /**
     * Generate a text response using the centrally configured OpenAI model.
     *
     * @param array<int, array{role: string, content: string}> $messages
     */
    public function generateResponse(
        string $developerInstructions,
        array $messages,
        int $maxOutputTokens = 800
    ): string {
        $apiKey = config('services.openai.api_key');
        $model = config('services.openai.model');
        $baseUrl = rtrim(
            config('services.openai.base_url', 'https://api.openai.com/v1'),
            '/'
        );

        if (!filled($apiKey) || !filled($model)) {
            throw new RuntimeException(
                'BeezBud AI configuration is incomplete.'
            );
        }

        if (empty($messages)) {
            throw new RuntimeException(
                'At least one input message is required.'
            );
        }

        foreach ($messages as $message) {
            if (
                !is_array($message) ||
                !in_array($message['role'] ?? null, ['user', 'assistant'], true) ||
                !is_string($message['content'] ?? null) ||
                trim($message['content']) === ''
            ) {
                throw new RuntimeException(
                    'BeezBud received an invalid conversation message.'
                );
            }
        }

        if ($maxOutputTokens < 1 || $maxOutputTokens > 4096) {
            throw new RuntimeException(
                'Invalid BeezBud output token limit.'
            );
        }

        $input = [
            [
                'role' => 'developer',
                'content' => $developerInstructions,
            ],
            ...$messages,
        ];

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('services.openai.timeout', 60))
                ->post($baseUrl . '/responses', [
                    'model' => $model,
                    'input' => $input,
                    'reasoning' => [
                        'effort' => 'medium',
                    ],
                    'max_output_tokens' => $maxOutputTokens,
                    'store' => false,
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('BeezBud OpenAI connection failed.');

            throw new RuntimeException(
                'BeezBud could not connect to the AI provider.',
                previous: $exception
            );
        }

        if (!$response->successful()) {
            Log::warning('BeezBud OpenAI request failed.', [
                'http_status' => $response->status(),
            ]);

            throw new RuntimeException(
                'BeezBud could not generate a response.'
            );
        }

        $output = $response->json('output', []);

        $text = collect($output)
            ->where('type', 'message')
            ->flatMap(fn (array $item) => $item['content'] ?? [])
            ->where('type', 'output_text')
            ->pluck('text')
            ->filter(fn ($value) => is_string($value))
            ->implode('');

        if (trim($text) === '') {
            Log::warning('BeezBud OpenAI returned no usable text output.');

            throw new RuntimeException(
                'BeezBud received an empty AI response.'
            );
        }

        return trim($text);
    }
}
