<?php

declare(strict_types=1);

namespace App\Equeue\Schedule;

use App\Message\Equeue\PollEqueueMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('default')]
final class EqueueSchedule implements ScheduleProviderInterface
{
    public function __construct(
        #[Autowire(env: 'int:EQUEUE_POLL_INTERVAL')]
        private readonly int $pollInterval,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(
                RecurringMessage::every(
                    sprintf('%d seconds', max(60, $this->pollInterval)),
                    new PollEqueueMessage(),
                ),
            );
    }
}
