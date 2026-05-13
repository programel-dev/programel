<?php

declare(strict_types=1);

namespace App\Equeue\Fetcher;

interface EqueueFetcherInterface
{
    public function fetch(): EqueueRawResponse;
}
