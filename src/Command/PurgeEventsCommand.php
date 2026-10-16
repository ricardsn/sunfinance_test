<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Removes the events a load run left behind, with their ledger rows. The counterpart to
 * app:post-events and, like it, a local tool: the ledger is insert-only in the API
 * itself, nothing there ever deletes a transaction.
 */
#[AsCommand(
    name: 'app:purge-events',
    description: 'Delete events whose event_id starts with a prefix, and their ledger rows',
    help: 'Counts what a load run left behind, and deletes it once you pass --force. A job still queued '
        .'for a deleted event cannot be handled, so it is dead-lettered on its next delivery; '
        .'--with-queue drops those jobs as well.',
    usages: ['--force', '--force --with-queue', 'evt_ --force'],
)]
final class PurgeEventsCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                name: 'prefix',
                mode: InputArgument::OPTIONAL,
                description: 'Delete the events whose event_id starts with this',
                default: 'load_',
            )
            ->addOption(
                name: 'force',
                shortcut: 'f',
                mode: InputOption::VALUE_NONE,
                description: 'Actually delete; without it the command only counts',
            )
            ->addOption(
                name: 'with-queue',
                mode: InputOption::VALUE_NONE,
                description: 'Also drop the queued jobs that mention the prefix',
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $prefix = (string) $input->getArgument('prefix');

        if ('' === $prefix) {
            $io->error('The prefix cannot be empty - this command is not meant to wipe the whole ledger.');

            return Command::INVALID;
        }

        // "load_" carries an underscore, which LIKE reads as a single-character
        // wildcard, so the prefix has to be escaped before it goes into the pattern
        $pattern = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix).'%';

        $events = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM processed_events WHERE event_id LIKE ?', [$pattern]);
        $ledger = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM transactions WHERE event_id LIKE ?', [$pattern]);

        if (0 === $events && 0 === $ledger) {
            $io->success(sprintf('Nothing to delete for "%s".', $prefix));

            return Command::SUCCESS;
        }

        $io->table(['Table', 'Rows'], [['processed_events', $events], ['transactions', $ledger]]);

        if (!$input->getOption('force')) {
            $io->comment('Nothing was deleted. Run it again with --force.');

            return Command::SUCCESS;
        }

        $queued = $this->connection->transactional(function (Connection $connection) use ($pattern, $input): int {
            $connection->executeStatement('DELETE FROM transactions WHERE event_id LIKE ?', [$pattern]);
            $connection->executeStatement('DELETE FROM processed_events WHERE event_id LIKE ?', [$pattern]);

            // the event id sits inside the serialised message, so this matches on the body
            return $input->getOption('with-queue')
                ? (int) $connection->executeStatement('DELETE FROM messenger_messages WHERE body LIKE ?', ['%'.$pattern])
                : 0;
        });

        $io->success(sprintf(
            '%d events and %d ledger rows deleted%s.',
            $events,
            $ledger,
            $input->getOption('with-queue') ? sprintf(', plus %d queued jobs', $queued) : '',
        ));

        return Command::SUCCESS;
    }
}
