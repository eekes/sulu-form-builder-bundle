<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Command;

use Eekes\Sulu\FormBuilderBundle\Domain\Repository\SubmissionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes blocked submissions older than the retention, from cron. A spam log grows fast on a busy
 * site, and what it holds is personal data when a person was caught by mistake.
 */
#[AsCommand(name: 'eekes:form-builder:prune-spam', description: 'Deletes blocked submissions older than the retention.')]
final class PruneSpamCommand extends Command
{
    public function __construct(
        private readonly SubmissionRepository $submissionRepository,
        private readonly int $retentionDays,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Keep this many days instead of spam_protection.retention_days')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report how many would be deleted');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $option = $input->getOption('days');

        if ($option !== null && (!\is_string($option) || !ctype_digit($option))) {
            $io->error('--days takes a whole number of days.');

            return Command::INVALID;
        }

        $days = $option !== null ? (int) $option : $this->retentionDays;

        if ($days === 0) {
            $io->note('The retention is 0: blocked submissions are kept until --days is given.');

            return Command::SUCCESS;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $count = $this->submissionRepository->deleteSpamOlderThan(new \DateTimeImmutable(\sprintf('-%d days', $days)), $dryRun);

        $io->success(\sprintf($dryRun ? '%d blocked submissions older than %d days would be deleted.' : 'Deleted %d blocked submissions older than %d days.', $count, $days));

        return Command::SUCCESS;
    }
}
