<?php

declare(strict_types=1);

namespace App\Equeue\Dto;

final readonly class EqueueSlotData
{
    public function __construct(
        public string $serviceCode,
        public string $serviceLabel,
        public \DateTimeImmutable $slotAt,
        public ?string $reference = null,
    ) {
    }

    /**
     * @return array{serviceCode: string, serviceLabel: string, slotAt: string, reference: string|null}
     */
    public function toArray(): array
    {
        return [
            'serviceCode' => $this->serviceCode,
            'serviceLabel' => $this->serviceLabel,
            'slotAt' => $this->slotAt->format(\DateTimeInterface::ATOM),
            'reference' => $this->reference,
        ];
    }
}
