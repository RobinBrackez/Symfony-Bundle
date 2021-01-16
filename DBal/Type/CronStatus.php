<?php

namespace Cron\CronBundle\DBal\Type;

class CronStatus extends AbstractEnumType
{
    const IDLE = 'idle';
    const RUNNING = 'running';
    const CRASHED = 'crashed';

    const VALUES = [
        CronStatus::IDLE,
        CronStatus::RUNNING,
        CronStatus::CRASHED,
    ];
    protected $name = 'cron_status';

    protected $values = CronStatus::VALUES;
}
