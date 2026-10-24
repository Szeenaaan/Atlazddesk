<?php

namespace App\Support;

use Illuminate\Queue\Console\WorkCommand;
use Illuminate\Queue\WorkerOptions;
use InvalidArgumentException;

final class QueueWorkCommand extends WorkCommand
{
    protected function gatherWorkerOptions(): WorkerOptions
    {
        $options = parent::gatherWorkerOptions();
        // Console options are strings; pcntl_alarm requires an integer on strict runtimes.
        $timeout = filter_var($options->timeout, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($timeout === false) {
            throw new InvalidArgumentException('The --timeout option must be a non-negative integer number of seconds.');
        }
        $options->timeout = $timeout;

        return $options;
    }
}
