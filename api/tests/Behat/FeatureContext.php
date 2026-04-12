<?php

namespace App\Tests\Behat;

use Behat\Behat\Context\Context;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;

final class FeatureContext implements Context
{
    private ?Response $response = null;

    public function __construct(
        private readonly KernelInterface $kernel,
    ) {
    }

    /**
     * @When I send a :method request to :url
     */
    public function iSendARequestTo(string $method, string $url): void
    {
        $this->response = $this->kernel->handle(
            Request::create($url, $method),
        );
    }

    /**
     * @Then the response status code should be :statusCode
     */
    public function theResponseStatusCodeShouldBe(int $statusCode): void
    {
        if ($this->response->getStatusCode() !== $statusCode) {
            throw new \RuntimeException(sprintf('Expected status code %d, got %d. Body: %s', $statusCode, $this->response->getStatusCode(), $this->response->getContent()));
        }
    }

    /**
     * @Then the response should be in JSON
     */
    public function theResponseShouldBeInJson(): void
    {
        $content = $this->response->getContent();
        if (null === json_decode($content)) {
            throw new \RuntimeException('Response is not valid JSON: '.$content);
        }
    }

    /**
     * @Then the JSON node :node should be equal to :expected
     */
    public function theJsonNodeShouldBeEqualTo(string $node, string $expected): void
    {
        $data = json_decode($this->response->getContent(), true);
        $value = $this->getJsonNode($data, $node);

        if ((string) $value !== $expected) {
            throw new \RuntimeException(sprintf('Expected JSON node "%s" to equal "%s", got "%s".', $node, $expected, $value));
        }
    }

    private function getJsonNode(array $data, string $path): mixed
    {
        $keys = explode('.', $path);
        $current = $data;

        foreach ($keys as $key) {
            if (!isset($current[$key])) {
                throw new \RuntimeException(sprintf('JSON node "%s" not found.', $path));
            }
            $current = $current[$key];
        }

        return $current;
    }
}
