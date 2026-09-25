<?php

namespace Lenorix\LaravelBeel\Commands;

use Illuminate\Console\Command;

class LaravelBeelCommand extends Command
{
    public $signature = 'laravel-beel';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
