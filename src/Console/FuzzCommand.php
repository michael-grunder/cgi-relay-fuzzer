<?php

namespace Mgrunder\Fuzzer\Console;

use Mgrunder\Fuzzer\Clients;
use Mgrunder\Fuzzer\Cmd;
use Mgrunder\Fuzzer\FuzzConfig;
use Mgrunder\Fuzzer\Stats;
use Mgrunder\Fuzzer\Trimmer;
use Redis;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'fuzz', description: 'Run the relay fuzzer and report concise, human friendly stats.')]
class FuzzCommand extends Command
{

    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Relay host to target', '127.0.0.1')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Relay port to target', 8080)
            ->addOption('grace-period', null, InputOption::VALUE_REQUIRED, 'Time (sec) to wait for matching reads', 1.0)
            ->addOption('delay', null, InputOption::VALUE_REQUIRED, 'Delay (sec) between fuzz iterations', 0.0)
            ->addOption('kill', null, InputOption::VALUE_REQUIRED, 'Chance (0-100) to kill a Redis client per iteration', 0.0)
            ->addOption('flush', null, InputOption::VALUE_NONE, 'Allow commands that require FLUSHDB')
            ->addOption('del', null, InputOption::VALUE_NONE, 'Allow commands that perform DEL before writes')
            ->addOption('trim', null, InputOption::VALUE_REQUIRED, 'Trim threshold for the relay', -1)
            ->addOption('keys', null, InputOption::VALUE_REQUIRED, 'Number of logical keys to target', 100)
            ->addOption('mems', null, InputOption::VALUE_REQUIRED, 'Number of set/list members to target', 100)
            ->addOption('show-last', null, InputOption::VALUE_NONE, 'Print the last Redis/Relay reply each tick')
            ->addOption('list-commands', null, InputOption::VALUE_NONE, 'List the grouped commands and exit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $host = (string) $input->getOption('host');
        $port = (int) $input->getOption('port');
        $grace = (float) $input->getOption('grace-period');
        $delay = (float) $input->getOption('delay');
        $kill = (float) $input->getOption('kill');
        $flush = (bool) $input->getOption('flush');
        $del = (bool) $input->getOption('del');
        $trim = (int) $input->getOption('trim');
        $keys = (int) $input->getOption('keys');
        $mems = (int) $input->getOption('mems');
        $showLast = (bool) $input->getOption('show-last');
        $listCommands = (bool) $input->getOption('list-commands');

        $cfg = new FuzzConfig($host, $port, $keys, $mems);
        $clients = new Clients($host, $port);
        $trimmer = new Trimmer($trim);
        $stats = new Stats($host, $port);
        $redis = new Redis(['host' => 'localhost', 'port' => 6379]);
        $registryForListing = new Cmd\Registry($cfg, true, true);

        if ($listCommands) {
            $this->renderCommandList($io, $registryForListing);
            return Command::SUCCESS;
        }

        $registry = new Cmd\Registry($cfg, $flush, $del);
        $io->title('Relay Fuzzer');
        $io->definitionList(
            ['Target' => sprintf('%s:%d', $host, $port)],
            ['Keys' => $keys],
            ['Members' => $mems],
            ['Grace Period' => sprintf('%.2f sec', $grace)],
            ['Delay' => sprintf('%.2f sec', $delay)],
            ['Kill Chance' => sprintf('%.2f%%', $kill)],
            ['Trim Threshold' => $trim],
            ['FlushDB Commands' => $flush ? 'Enabled' : 'Disabled'],
            ['DEL Before Writes' => $del ? 'Enabled' : 'Disabled']
        );

        $io->writeln('');
        $io->text('<info>Loaded commands:</info> ' . implode(', ', $registry->commandNames()));
        $io->newLine();

        $totals = [];
        $start = $lastTick = microtime(true);
        $iteration = 0;
        $retries = 0;
        $kills = 0;
        $trimmed = 0;
        $delayUs = (int) ($delay * 1_000_000);
        $lastRedisResult = null;
        $lastRelayResult = null;

        while (++$iteration) {
            if ($this->randChance($kill)) {
                $kills += $clients->kill(null);
            }

            $command = $registry->randomCmd();
            $args = $command->nextArgs();

            $totals[$command->name] = ($totals[$command->name] ?? 0) + 1;

            $commandStart = microtime(true);
            $result = $command->exec($args);

            if (!($command->flags() & Cmd\Cmd::READ)) {
                continue;
            }

            if (!$this->validateReply($result)) {
                $io->error('Received malformed reply from command execution');
                return Command::FAILURE;
            }

            do {
                $relayResult = $result['relay']['result'];
                $redisResult = $result['redis']['result'];
                if ($relayResult === $redisResult) {
                    break;
                }

                $retries++;
                usleep(10_000);
            } while (microtime(true) - $commandStart < $grace);

            if ($relayResult !== $redisResult) {
                $io->error(sprintf('Result mismatch after %d iterations', $iteration));
                $io->writeln(print_r($result, true));
                return Command::FAILURE;
            }

            $lastRedisResult = $redisResult;
            $lastRelayResult = $relayResult;

            if (($now = microtime(true)) - $lastTick >= 1.0) {
                $trimmed += $trimmer->trim();

                $redisInfo = $redis->info();
                $redisUsedMem = $redisInfo['used_memory_human'] ?? 'N/A';

                $relayInfo = $stats->exec([]);
                $relayMemory = [
                    $relayInfo['memory']['used'],
                    $relayInfo['memory']['total'],
                ];
                $relayStats = [
                    $relayInfo['stats']['requests'],
                    $relayInfo['stats']['hits'],
                    $relayInfo['stats']['misses'],
                ];

                $this->renderTick(
                    $io,
                    $iteration,
                    $start,
                    $now,
                    $retries,
                    $kills,
                    $trimmed,
                    $totals,
                    $clients->ids(),
                    $redisUsedMem,
                    $relayMemory,
                    $relayStats,
                    $showLast,
                    $lastRedisResult,
                    $lastRelayResult
                );

                $lastTick = $now;
            }

            if ($delayUs > 0) {
                usleep($delayUs);
            }
        }

        return Command::SUCCESS;
    }

    private function renderCommandList(SymfonyStyle $io, Cmd\Registry $registry): void
    {
        $groups = [];
        foreach ($registry->commands() as $command) {
            $groups[$command->type()->value][] = $command->name;
        }

        ksort($groups);

        $io->title('Available commands');
        foreach ($groups as $group => $names) {
            sort($names);
            $io->section(strtoupper($group));
            $io->writeln(implode(', ', $names));
        }
    }

    private function renderTick(
        SymfonyStyle $io,
        int $iteration,
        float $start,
        float $now,
        int $retries,
        int $kills,
        int $trimmed,
        array $totals,
        array $clientIds,
        string $redisUsedMem,
        array $relayMemory,
        array $relayStats,
        bool $showLast,
        mixed $lastRedisResult,
        mixed $lastRelayResult
    ): void {
        $io->newLine(2);
        $io->section(sprintf('Iteration %d', $iteration));

        $io->definitionList(
            ['Rate' => sprintf('%.2f commands/sec', $iteration / ($now - $start))],
            ['Retries / Kills / Trimmed' => sprintf('%d / %d / %d', $retries, $kills, $trimmed)],
            ['Redis Memory' => $redisUsedMem],
            ['Relay Memory' => sprintf('%s / %s bytes', number_format($relayMemory[0]), number_format($relayMemory[1]))],
            ['Relay Requests' => number_format($relayStats[0])],
            ['Relay Hits / Misses' => sprintf('%s / %s', number_format($relayStats[1]), number_format($relayStats[2]))],
            ['Relay Client IDs' => $clientIds ? implode(', ', $clientIds) : 'None']
        );

        if ($io->isVerbose()) {
            arsort($totals);
            $top = array_slice($totals, 0, 10, true);
            if ($top) {
                $io->text('Command distribution (top 10):');
                $rows = [];
                foreach ($top as $name => $count) {
                    $rows[] = [$name, number_format($count)];
                }
                $io->table(['Command', 'Count'], $rows);
            }
        }

        if ($showLast && $lastRedisResult !== null) {
            $io->text('Last Redis result: ' . json_encode($lastRedisResult, JSON_UNESCAPED_SLASHES));
            $io->text('Last Relay result: ' . json_encode($lastRelayResult, JSON_UNESCAPED_SLASHES));
        }
    }

    private function validateReply(array $reply): bool
    {
        return isset($reply['query'], $reply['redis'], $reply['relay'])
            && isset($reply['relay']['result'])
            && isset($reply['redis']['result']);
    }

    private function randChance(float $percent): bool
    {
        if ($percent <= 0.0) {
            return false;
        }

        if ($percent >= 100.0) {
            return true;
        }

        return mt_rand() / mt_getrandmax() < ($percent / 100.0);
    }
}
