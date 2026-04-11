<?php

namespace App\Tests\Behat;

use Behat\Behat\Context\Context;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;

final class FeatureContext implements Context
{
    private ?Response $response = null;

    public function __construct(
        private readonly KernelInterface $kernel,
    ) {
    }
}
