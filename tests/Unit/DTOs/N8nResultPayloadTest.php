<?php

declare(strict_types=1);

use App\DTOs\N8nResultPayload;

describe('N8nResultPayload', function () {
    it('can be instantiated with all properties', function () {
        $payload = new N8nResultPayload(
            newScript: 'Improved ad script',
            analysis: ['sentiment' => 'positive', 'score' => 8.5],
            error: null
        );

        expect($payload->newScript)->toBe('Improved ad script');
        expect($payload->analysis)->toBe(['sentiment' => 'positive', 'score' => 8.5]);
        expect($payload->error)->toBeNull();
    });

    it('can be instantiated with default null values', function () {
        $payload = new N8nResultPayload();

        expect($payload->newScript)->toBeNull();
        expect($payload->analysis)->toBeNull();
        expect($payload->error)->toBeNull();
    });

    it('can be instantiated with only error', function () {
        $payload = new N8nResultPayload(error: 'AI processing failed');

        expect($payload->newScript)->toBeNull();
        expect($payload->analysis)->toBeNull();
        expect($payload->error)->toBe('AI processing failed');
    });

    it('correctly identifies success state', function () {
        $successPayload = new N8nResultPayload(
            newScript: 'Improved script',
            analysis: ['score' => 9.0]
        );

        expect($successPayload->isSuccess())->toBeTrue();
        expect($successPayload->isError())->toBeFalse();
    });

    it('correctly identifies error state', function () {
        $errorPayload = new N8nResultPayload(
            error: 'Processing failed'
        );

        expect($errorPayload->isError())->toBeTrue();
        expect($errorPayload->isSuccess())->toBeFalse();
    });

    it('identifies neither success nor error when incomplete', function () {
        $incompletePayload = new N8nResultPayload(
            analysis: ['score' => 7.0]
        );

        expect($incompletePayload->isSuccess())->toBeFalse();
        expect($incompletePayload->isError())->toBeFalse();
    });

    it('can be created from array data', function () {
        $data = [
            'new_script' => 'Script from array',
            'analysis' => ['sentiment' => 'neutral'],
            'error' => null,
        ];

        $payload = N8nResultPayload::fromArray($data);

        expect($payload->newScript)->toBe('Script from array');
        expect($payload->analysis)->toBe(['sentiment' => 'neutral']);
        expect($payload->error)->toBeNull();
    });

    it('handles missing keys in array data', function () {
        $data = [
            'new_script' => 'Only script provided',
        ];

        $payload = N8nResultPayload::fromArray($data);

        expect($payload->newScript)->toBe('Only script provided');
        expect($payload->analysis)->toBeNull();
        expect($payload->error)->toBeNull();
    });

    it('can create success payload using static method', function () {
        $payload = N8nResultPayload::success(
            'Success script',
            ['quality' => 'excellent']
        );

        expect($payload->newScript)->toBe('Success script');
        expect($payload->analysis)->toBe(['quality' => 'excellent']);
        expect($payload->error)->toBeNull();
        expect($payload->isSuccess())->toBeTrue();
    });

    it('can create error payload using static method', function () {
        $payload = N8nResultPayload::error('Something went wrong');

        expect($payload->newScript)->toBeNull();
        expect($payload->analysis)->toBeNull();
        expect($payload->error)->toBe('Something went wrong');
        expect($payload->isError())->toBeTrue();
    });

    it('converts to array correctly with all values', function () {
        $payload = new N8nResultPayload(
            newScript: 'Complete script',
            analysis: ['score' => 8.5, 'keywords' => ['engaging', 'persuasive']],
            error: null
        );

        $array = $payload->toArray();

        expect($array)->toBe([
            'new_script' => 'Complete script',
            'analysis' => ['score' => 8.5, 'keywords' => ['engaging', 'persuasive']],
        ]);
    });

    it('converts to array correctly with only error', function () {
        $payload = N8nResultPayload::error('Failed to process');

        $array = $payload->toArray();

        expect($array)->toBe([
            'error' => 'Failed to process',
        ]);
    });

    it('converts to array correctly filtering null values', function () {
        $payload = new N8nResultPayload(
            newScript: 'Script only',
            analysis: null,
            error: null
        );

        $array = $payload->toArray();

        expect($array)->toBe([
            'new_script' => 'Script only',
        ]);
    });

    it('maintains immutability as readonly class', function () {
        $payload = new N8nResultPayload(
            newScript: 'Test script',
            analysis: ['test' => true]
        );

        $reflection = new ReflectionClass($payload);
        expect($reflection->isReadOnly())->toBeTrue();
    });

    it('handles complex analysis data structures', function () {
        $complexAnalysis = [
            'sentiment' => [
                'overall' => 'positive',
                'confidence' => 0.85,
                'breakdown' => [
                    'excitement' => 0.7,
                    'trust' => 0.9,
                ],
            ],
            'keywords' => ['innovative', 'reliable', 'affordable'],
            'suggestions' => [
                'Add more emotional appeal',
                'Include specific benefits',
            ],
            'metrics' => [
                'readability_score' => 8.2,
                'engagement_prediction' => 7.8,
            ],
        ];

        $payload = N8nResultPayload::success(
            'Analyzed script content',
            $complexAnalysis
        );

        expect($payload->analysis)->toBe($complexAnalysis);
        expect($payload->isSuccess())->toBeTrue();

        $array = $payload->toArray();
        expect($array['analysis'])->toBe($complexAnalysis);
    });
});
