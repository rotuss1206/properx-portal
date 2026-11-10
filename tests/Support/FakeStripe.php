<?php

namespace Tests\Support;

use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\Util\CaseInsensitiveArray;

/**
 * Stands in for Stripe's HTTP client, so no test reaches the network. Tests\TestCase installs
 * one without answers, which fails on any request; a test adds the answers it expects with on().
 */
final class FakeStripe implements ClientInterface
{
    /** @var list<array{method: string, path: string, params: array<string, mixed>}> */
    public array $requests = [];

    /** @var list<array{method: string, path: string, answer: \Closure|array<string, mixed>, status: int}> */
    private array $answers = [];

    public static function install(): self
    {
        $fake = new self;
        ApiRequestor::setHttpClient($fake);

        return $fake;
    }

    public static function uninstall(): void
    {
        ApiRequestor::setHttpClient(null);
    }

    /**
     * @param  string  $path  e.g. "/v1/prices" or "/v1/prices/*"
     * @param  \Closure(array<string, mixed>): array<string, mixed>|array<string, mixed>  $answer
     */
    public function on(string $method, string $path, \Closure|array $answer, int $status = 200): self
    {
        $this->answers[] = ['method' => strtolower($method), 'path' => $path, 'answer' => $answer, 'status' => $status];

        return $this;
    }

    /**
     * @return list<array{method: string, path: string, params: array<string, mixed>}>
     */
    public function sent(string $method, string $path): array
    {
        return array_values(array_filter($this->requests, fn (array $request) => $request['method'] === strtolower($method) && $request['path'] === $path));
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $method = strtolower($method);
        $path = (string) parse_url($absUrl, PHP_URL_PATH);
        parse_str((string) parse_url($absUrl, PHP_URL_QUERY), $query);
        $params = array_merge($query, is_array($params) ? $params : []);

        $this->requests[] = ['method' => $method, 'path' => $path, 'params' => $params];

        foreach ($this->answers as $answer) {
            if ($answer['method'] === $method && fnmatch($answer['path'], $path)) {
                $body = $answer['answer'] instanceof \Closure ? ($answer['answer'])($params, $path) : $answer['answer'];

                return [json_encode($body), $answer['status'], new CaseInsensitiveArray(['request-id' => 'req_fake'])];
            }
        }

        throw new RuntimeException("A test sent an unexpected Stripe request: {$method} {$path}");
    }
}
