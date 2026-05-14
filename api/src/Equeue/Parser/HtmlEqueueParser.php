<?php

declare(strict_types=1);

namespace App\Equeue\Parser;

use App\Equeue\Dto\EqueueServiceData;
use App\Equeue\Dto\EqueueSlotData;
use App\Equeue\Dto\EqueueSnapshotData;
use App\Equeue\Fetcher\EqueueRawResponse;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Default parser for the Munich consulate e-queue HTML page.
 *
 * The page renders a list of services with their available slots inline. This
 * parser is intentionally permissive: when the markup changes we update one
 * class and re-run fixture tests. Two selector strategies are attempted in
 * order so that minor markup changes do not break the pipeline.
 */
final class HtmlEqueueParser implements EqueueParserInterface
{
    private const VERSION = 'html-v1';

    public function parse(EqueueRawResponse $response): EqueueSnapshotData
    {
        if ('' === trim($response->body)) {
            throw new EqueueParseException('Empty response body');
        }

        $crawler = new Crawler($response->body);
        $services = [];
        $slots = [];

        $serviceNodes = $crawler->filter('[data-service-code], .e-queue-service');
        if (0 === $serviceNodes->count()) {
            return new EqueueSnapshotData([], [], self::VERSION);
        }

        $serviceNodes->each(function (Crawler $node) use (&$services, &$slots): void {
            $code = $this->extractServiceCode($node);
            if (null === $code) {
                return;
            }

            $label = trim($node->filter('.service-name, [data-service-label]')->first()->text(''));
            if ('' === $label) {
                $label = $code;
            }

            $services[] = new EqueueServiceData($code, $label);

            $slotNodes = $node->filter('[data-slot-at], .e-queue-slot');
            $slotNodes->each(function (Crawler $slotNode) use ($code, $label, &$slots): void {
                $slotAt = $this->extractSlotAt($slotNode);
                if (null === $slotAt) {
                    return;
                }

                $reference = $slotNode->attr('data-slot-id');
                $slots[] = new EqueueSlotData($code, $label, $slotAt, $reference);
            });
        });

        return new EqueueSnapshotData($services, $slots, self::VERSION);
    }

    public function version(): string
    {
        return self::VERSION;
    }

    private function extractServiceCode(Crawler $node): ?string
    {
        $code = $node->attr('data-service-code');
        if (null !== $code && '' !== $code) {
            return $code;
        }

        $idAttribute = $node->attr('id');
        if (null !== $idAttribute && '' !== $idAttribute) {
            return $idAttribute;
        }

        return null;
    }

    private function extractSlotAt(Crawler $slotNode): ?\DateTimeImmutable
    {
        $raw = $slotNode->attr('data-slot-at');
        if (null === $raw || '' === $raw) {
            $raw = trim($slotNode->text(''));
        }

        if ('' === $raw) {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw, new \DateTimeZone('Europe/Berlin'));
        } catch (\Exception) {
            return null;
        }
    }
}
