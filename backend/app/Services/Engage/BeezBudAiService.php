<?php

namespace App\Services\Engage;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Illuminate\Support\Str;

class BeezBudAiService
{

	private const HANDOVER_REASONS = [
	    'customer_requested_human',
	    'complex_or_unresolved_issue',
	    'complaint_or_sensitive_issue',
	    'ai_confidence_insufficient',
	];
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
		    $providerError = $response->json('error', []);
		
		    Log::error('BeezBud OpenAI request failed.', [
		        'http_status' => $response->status(),
		        'request_id' => $response->header('x-request-id'),
		        'error_type' => data_get($providerError, 'type'),
		        'error_code' => data_get($providerError, 'code'),
		        'error_message' => Str::limit(
		            (string) data_get($providerError, 'message', 'No provider error message returned.'),
		            1000
		        ),
		        'model' => $model,
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

	
	/**
	 * Generate a structured BeezBud response with a handover decision.
	 *
	 * @param array<int, array{role: string, content: string}> $messages
	 * @return array{
	 *     reply: string,
	 *     handover_required: bool,
	 *     handover_reason: ?string
	 * }
	 */
	public function generateStructuredResponse(
	    string $developerInstructions,
	    array $messages,
	    int $maxOutputTokens = 800
	): array {
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
	
	    $schema = [
	        'type' => 'object',
	        'additionalProperties' => false,
	        'properties' => [
	            'reply' => [
	                'type' => 'string',
	                'description' => 'The customer-facing response.',
	            ],
	            'handover_required' => [
	                'type' => 'boolean',
	                'description' => 'Whether a human should take over.',
	            ],
	            'handover_reason' => [
	                'anyOf' => [
	                    [
	                        'type' => 'string',
	                        'enum' => self::HANDOVER_REASONS,
	                    ],
	                    [
	                        'type' => 'null',
	                    ],
	                ],
	            ],
	        ],
	        'required' => [
	            'reply',
	            'handover_required',
	            'handover_reason',
	        ],
	    ];
	
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
	                'text' => [
	                    'format' => [
	                        'type' => 'json_schema',
	                        'name' => 'beezbud_response',
	                        'strict' => true,
	                        'schema' => $schema,
	                    ],
	                ],
	            ]);
	    } catch (ConnectionException $exception) {
	        Log::warning('BeezBud OpenAI connection failed.');
	
	        throw new RuntimeException(
	            'BeezBud could not connect to the AI provider.',
	            previous: $exception
	        );
	    }
	
	    if (!$response->successful()) {
	        $providerError = $response->json('error', []);
	
	        Log::error('BeezBud OpenAI request failed.', [
	            'http_status' => $response->status(),
	            'request_id' => $response->header('x-request-id'),
	            'error_type' => data_get($providerError, 'type'),
	            'error_code' => data_get($providerError, 'code'),
	            'error_message' => \Illuminate\Support\Str::limit(
	                (string) data_get(
	                    $providerError,
	                    'message',
	                    'No provider error message returned.'
	                ),
	                1000
	            ),
	            'model' => $model,
	        ]);
	
	        throw new RuntimeException(
	            'BeezBud could not generate a response.'
	        );
	    }
	
	    $output = $response->json('output', []);
	
	    // A refusal is not a normal structured response.
	    foreach ($output as $item) {
	        foreach ($item['content'] ?? [] as $content) {
	            if (($content['type'] ?? null) === 'refusal') {
	                Log::warning('BeezBud OpenAI returned a refusal.');
	
	                throw new RuntimeException(
	                    'BeezBud could not generate a usable response.'
	                );
	            }
	        }
	    }
	
	    $text = collect($output)
	        ->where('type', 'message')
	        ->flatMap(fn (array $item) => $item['content'] ?? [])
	        ->where('type', 'output_text')
	        ->pluck('text')
	        ->filter(fn ($value) => is_string($value))
	        ->implode('');
	
	    if (trim($text) === '') {
	        Log::warning('BeezBud OpenAI returned no usable structured output.');
	
	        throw new RuntimeException(
	            'BeezBud received an empty AI response.'
	        );
	    }
	
	    try {
	        $data = json_decode(
	            $text,
	            true,
	            512,
	            JSON_THROW_ON_ERROR
	        );
	    } catch (\JsonException $exception) {
	        Log::warning('BeezBud returned invalid structured JSON.');
	
	        throw new RuntimeException(
	            'BeezBud received an invalid structured response.',
	            previous: $exception
	        );
	    }
	
	    if (
	        !is_array($data) ||
	        array_keys($data) !== [
	            'reply',
	            'handover_required',
	            'handover_reason',
	        ] ||
	        !is_string($data['reply']) ||
	        trim($data['reply']) === '' ||
	        !is_bool($data['handover_required'])
	    ) {
	        throw new RuntimeException(
	            'BeezBud returned an invalid response structure.'
	        );
	    }
	
	    $reason = $data['handover_reason'];
	
	    if ($data['handover_required']) {
	        if (
	            !is_string($reason) ||
	            !in_array($reason, self::HANDOVER_REASONS, true)
	        ) {
	            throw new RuntimeException(
	                'BeezBud returned an invalid handover reason.'
	            );
	        }
	    } elseif ($reason !== null) {
	        throw new RuntimeException(
	            'BeezBud returned a handover reason without requesting handover.'
	        );
	    }
	
	    return [
	        'reply' => trim($data['reply']),
	        'handover_required' => $data['handover_required'],
	        'handover_reason' => $reason,
	    ];
	}

}
