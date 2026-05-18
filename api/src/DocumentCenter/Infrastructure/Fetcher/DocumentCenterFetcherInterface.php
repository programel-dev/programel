<?php

declare(strict_types=1);

namespace App\DocumentCenter\Infrastructure\Fetcher;

interface DocumentCenterFetcherInterface
{
    public function fetch(): DocumentCenterRawResponse;
}
