<?php

declare(strict_types=1);

namespace App\DocumentCenter\Domain;

final class SlotSignature
{
    public static function for(string $serviceCode, \DateTimeImmutable $slotAt): string
    {
        return hash('sha256', $serviceCode.'|'.$slotAt->format(\DateTimeInterface::ATOM));
    }
}
