<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\Response;

/**
 * Load generator: posts random events through the real HTTP endpoint. Not part of the
 * API - it exists to see how many events the stack swallows per second.
 */
#[AsCommand(
    name: 'app:post-events',
    description: 'Post random financial events to POST /events',
    help: 'Every event carries its own event_id, so none of them comes back as a 409. The default URL '
        .'resolves inside the containers; from the host pass the published port instead.',
    usages: ['1000', '1000 --concurrency=64', '1000 --url=http://localhost:8080/events'],
)]
final class PostEventsCommand extends Command
{
    private const TYPES = ['payment_received', 'payment_sent', 'fee_charged'];

    /** Currency => how many decimals its amounts may carry (the ISO 4217 exponent). */
    private const CURRENCIES = ['EUR' => 2, 'USD' => 2, 'GBP' => 2, 'JPY' => 0];

    protected function configure(): void
    {
        $this
            ->addArgument(
                name: 'count',
                mode: InputArgument::OPTIONAL,
                description: 'How many events to post',
                default: 1000,
            )
            ->addOption(
                name: 'concurrency',
                shortcut: 'c',
                mode: InputOption::VALUE_REQUIRED,
                description: 'How many requests to keep in flight',
                default: 32,
            )
            ->addOption(
                name: 'url',
                shortcut: 'u',
                mode: InputOption::VALUE_REQUIRED,
                description: 'The endpoint to post to',
                default: 'http://nginx/events',
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = (int) $input->getArgument('count');
        $concurrency = (int) $input->getOption('concurrency');
        $url = (string) $input->getOption('url');

        if ($count < 1 || $concurrency < 1) {
            $io->error('The count and the concurrency must both be at least 1.');

            return Command::INVALID;
        }

        $run = bin2hex(random_bytes(4));
        $io->comment(sprintf('Posting %d events to %s, %d in flight (run %s).', $count, $url, $concurrency, $run));

        $progress = $io->createProgressBar($count);
        $progress->setRedrawFrequency(max(1, (int) ($count / 100)));
        $progress->start();

        $multi = curl_multi_init();
        $sent = 0;
        $done = 0;
        /** @var array<int, int> $statuses status code => how many answered with it */
        $statuses = [];
        $firstError = null;
        $started = microtime(true);

        $add = function () use (&$sent, $count, $multi, $url, $run): void {
            if ($sent >= $count) {
                return;
            }

            $handle = curl_init($url);
            \assert(false !== $handle);

            curl_setopt_array($handle, [
                \CURLOPT_POST => true,
                \CURLOPT_POSTFIELDS => $this->payload($run, $sent),
                \CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                \CURLOPT_RETURNTRANSFER => true,
                \CURLOPT_TIMEOUT => 30,
            ]);
            curl_multi_add_handle($multi, $handle);
            $sent++;
        };

        for ($i = 0; $i < $concurrency; $i++) {
            $add();
        }

        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi, 1.0);

            while (false !== ($info = curl_multi_info_read($multi))) {
                $handle = $info['handle'];
                $status = (int) curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
                $statuses[$status] = ($statuses[$status] ?? 0) + 1;

                if (Response::HTTP_ACCEPTED !== $status && null === $firstError) {
                    $firstError = sprintf('%d: %s', $status, (string) curl_multi_getcontent($handle));
                }

                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
                $done++;
                $progress->advance();

                $add();
            }
        } while ($running > 0 || $done < $count);

        curl_multi_close($multi);
        $progress->finish();
        $io->newLine(2);

        $elapsed = microtime(true) - $started;
        ksort($statuses);

        $io->table(
            ['Status', 'Events'],
            array_map(static fn (int $status, int $n): array => [$status, $n], array_keys($statuses), $statuses),
        );

        if (null !== $firstError) {
            $io->warning(sprintf('First response that was not a 202 - %s', $firstError));
        }

        $io->success(sprintf('%d events posted in %.1fs (%.0f/s).', $done, $elapsed, $done / $elapsed));

        return ($statuses[Response::HTTP_ACCEPTED] ?? 0) === $count ? Command::SUCCESS : Command::FAILURE;
    }

    private function payload(string $run, int $i): string
    {
        $currency = (string) array_rand(self::CURRENCIES);
        $decimals = self::CURRENCIES[$currency];

        return json_encode([
            'event_id' => sprintf('load_%s_%d', $run, $i),
            'type' => self::TYPES[random_int(0, \count(self::TYPES) - 1)],
            // a currency without minor units must not get decimals, or it is a 422
            'amount' => 0 === $decimals
                ? (string) random_int(1, 1_000_000)
                : sprintf('%d.%0'.$decimals.'d', random_int(1, 10_000), random_int(0, 10 ** $decimals - 1)),
            'currency' => $currency,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z', random_int(1_735_689_600, 1_798_761_600)),
        ], \JSON_THROW_ON_ERROR);
    }
}
