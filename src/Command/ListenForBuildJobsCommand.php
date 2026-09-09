<?php

declare(strict_types=1);

namespace App\Command;

use App\Messaging\AmqpBuildJobListener;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:listen-for-build-jobs', description: 'Listen for build jobs on the q.builds queue')]
final class ListenForBuildJobsCommand extends Command
{
    public function __construct(private readonly AmqpBuildJobListener $listener)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->listener->listen();

        return Command::SUCCESS;
    }
}
