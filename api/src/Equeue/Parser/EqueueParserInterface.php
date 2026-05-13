<?php

declare(strict_types=1);

namespace App\Equeue\Parser;

use App\Equeue\Dto\EqueueSnapshotData;
use App\Equeue\Fetcher\EqueueRawResponse;

interface EqueueParserInterface
{
    /**
     * @throws EqueueParseException
     */
    public function parse(EqueueRawResponse $response): EqueueSnapshotData;

    public function version(): string;
}
