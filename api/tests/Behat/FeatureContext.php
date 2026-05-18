<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use App\User\Domain\User;
use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\PyStringNode;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class FeatureContext implements Context
{
    private ?Response $response = null;
    private ?string $authToken = null;

    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly EntityManagerInterface $entityManager,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * @Given I am authenticated as admin
     */
    public function iAmAuthenticatedAsAdmin(): void
    {
        $this->authToken = $this->createTokenForUser('behat_admin@test.com', ['ROLE_USER', 'ROLE_ADMIN']);
    }

    /**
     * @Given I am authenticated as a regular user
     */
    public function iAmAuthenticatedAsRegularUser(): void
    {
        $this->authToken = $this->createTokenForUser('behat_user@test.com', ['ROLE_USER']);
    }

    /**
     * @Given I am not authenticated
     */
    public function iAmNotAuthenticated(): void
    {
        $this->authToken = null;
    }

    /**
     * @When I send a :method request to :url
     */
    public function iSendARequestTo(string $method, string $url): void
    {
        $request = Request::create($url, $method);
        if (null !== $this->authToken) {
            $request->headers->set('Authorization', 'Bearer '.$this->authToken);
        }
        $this->response = $this->kernel->handle($request);
    }

    /**
     * @When I send a :method request to :url with body:
     */
    public function iSendARequestToWithBody(string $method, string $url, PyStringNode $body): void
    {
        $request = Request::create(
            $url,
            $method,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            (string) $body,
        );
        if (null !== $this->authToken) {
            $request->headers->set('Authorization', 'Bearer '.$this->authToken);
        }
        $this->response = $this->kernel->handle($request);
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
        json_decode($content, true);
        if (\JSON_ERROR_NONE !== json_last_error()) {
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
        $valueStr = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

        if ($valueStr !== $expected) {
            throw new \RuntimeException(sprintf('Expected JSON node "%s" to equal "%s", got "%s".', $node, $expected, $valueStr));
        }
    }

    /**
     * @Then the JSON node :node should exist
     */
    public function theJsonNodeShouldExist(string $node): void
    {
        $data = json_decode($this->response->getContent(), true);
        $this->getJsonNode($data, $node);
    }

    private function getJsonNode(array $data, string $path): mixed
    {
        $keys = explode('.', $path);
        $current = $data;

        foreach ($keys as $key) {
            if (!array_key_exists($key, $current)) {
                throw new \RuntimeException(sprintf('JSON node "%s" not found in: %s', $path, json_encode($data)));
            }
            $current = $current[$key];
        }

        return $current;
    }

    private function createTokenForUser(string $email, array $roles): string
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        if (null === $user) {
            $user = new User();
            $user->setEmail($email);
            $user->setPassword($this->passwordHasher->hashPassword($user, 'behat-password'));
            $this->entityManager->persist($user);
        }

        $user->setRoles($roles);
        $this->entityManager->flush();

        return $this->jwtManager->create($user);
    }
}
