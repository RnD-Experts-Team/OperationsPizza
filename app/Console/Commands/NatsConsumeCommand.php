<?php

namespace App\Console\Commands;

use App\Services\EventConsume\JetStreamConsumer;
use Illuminate\Console\Command;

class NatsConsumeCommand extends Command
{
    protected $signature = 'nats:consume';
    protected $description = 'Consume JetStream events (auth + hiring) into the operations database';

    public function handle(JetStreamConsumer $consumer): int
    {
        $this->info('Starting JetStream consumer...');
        $consumer->runForever();
        return self::SUCCESS;
    }
}
