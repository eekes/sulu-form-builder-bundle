<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Double;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The HTTP client of the test application: answers with a configurable status and remembers
 * every request, so the webhook can be tested without a network.
 */
final class RecordingHttpClient extends MockHttpClient
{
    /**
     * @var list<array{method: string, url: string, options: array<string, mixed>}>
     */
    public array $requests = [];

    public int $status = 200;

    public function __construct()
    {
        parent::__construct(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => array_filter($options, 'is_string', \ARRAY_FILTER_USE_KEY)];

            return new MockResponse('{"ok":true}', ['http_code' => $this->status]);
        });
    }
}
