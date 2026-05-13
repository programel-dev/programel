<?php

declare(strict_types=1);

namespace App\Equeue\Dto;

final readonly class EqueueSnapshotData
{
    /**
     * @param list<EqueueServiceData> $services
     * @param list<EqueueSlotData>    $slots
     */
    public function __construct(
        public array $services,
        public array $slots,
        public string $parserVersion,
    ) {
    }

    /**
     * @return array{
     *     services: list<array{code: string, label: string}>,
     *     slots: list<array{serviceCode: string, serviceLabel: string, slotAt: string, reference: string|null}>
     * }
     */
    public function toArray(): array
    {
        return [
            'services' => array_map(
                static fn (EqueueServiceData $service): array => [
                    'code' => $service->code,
                    'label' => $service->label,
                ],
                $this->services,
            ),
            'slots' => array_map(
                static fn (EqueueSlotData $slot): array => $slot->toArray(),
                $this->slots,
            ),
        ];
    }
}
