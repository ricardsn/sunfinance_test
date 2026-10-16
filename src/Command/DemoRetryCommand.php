<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\FailingProbe;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Shows the retry and dead-letter path on a real queue: dispatches a message that
 * always fails and consumes it until the retries run out. A local tool, like the other
 * app: commands.
 */
#[AsCommand(
    name: 'app:demo-retry',
    description: 'Watch a failing message get retried and then dead-lettered',
    help: 'Dispatches a message whose handler always throws, then consumes it until the retries run out. '
        .'The probe has its own queue, so it never competes with real events, and that queue looks for '
        .'delayed messages every 100ms - on async the 60s scan makes a real retry take about a minute.',
    usages: ['-vv'],
)]
final class DemoRetryCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                name: 'deliveries',
                shortcut: 'd',
                mode: InputOption::VALUE_REQUIRED,
                description: 'How many deliveries to consume: the first one plus the 3 retries',
                default: 4,
            )
            ->addOption(
                name: 'keep',
                mode: InputOption::VALUE_NONE,
                description: 'Leave the dead letter in place for messenger:failed:show',
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $application = $this->getApplication();
        \assert(null !== $application);

        $deliveries = (int) $input->getOption('deliveries');

        if ($deliveries < 1) {
            $io->error('There has to be at least one delivery to consume.');

            return Command::INVALID;
        }

        if (!$output->isVeryVerbose()) {
            $io->comment('Run with -vv to see the retries as the worker logs them.');
        }

        $probe = new FailingProbe(bin2hex(random_bytes(4)));
        $this->bus->dispatch($probe);

        $io->comment(sprintf('Probe "%s" queued. Consuming %d deliveries.', $probe->id, $deliveries));

        $started = microtime(true);

        $application->find('messenger:consume')->run(new ArrayInput([
            'receivers' => ['probe'],
            '--limit' => $deliveries,
            // a safety net: the probe is the only message on this queue, so if it is
            // gone the worker would otherwise sit here waiting
            '--time-limit' => 60,
        ]), $output);

        $io->newLine();
        $io->success(sprintf(
            '%d deliveries in %.1fs. The probe is now in the failed queue (%d message(s) waiting).',
            $deliveries,
            microtime(true) - $started,
            $this->deadLettered(),
        ));

        if ($input->getOption('keep')) {
            $io->comment('Look at it with messenger:failed:show, replay it with messenger:failed:retry, drop it with messenger:failed:remove.');

            return Command::SUCCESS;
        }

        // the probe queue is ours alone, so everything on it can go: a run that
        // consumed fewer deliveries than the retries need leaves the probe waiting
        // there, where nothing would ever take it. In the failed queue the probes have
        // to be picked out, and the message class is in the headers, not the body.
        $removed = $this->connection->executeStatement(
            "DELETE FROM messenger_messages WHERE queue_name = 'probe' OR (queue_name = 'failed' AND headers LIKE ?)",
            ['%FailingProbe%'],
        );

        $io->comment(sprintf('%d probe(s) removed from the queue. Pass --keep to leave them next time.', $removed));

        return Command::SUCCESS;
    }

    private function deadLettered(): int
    {
        return (int) $this->connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed'");
    }
}
