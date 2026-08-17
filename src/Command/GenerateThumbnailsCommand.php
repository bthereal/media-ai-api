<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ContentRepository;
use App\Service\ThumbnailGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:generate-thumbnails',
    description: 'Generate thumbnails for all content records that do not have one yet',
)]
class GenerateThumbnailsCommand extends Command
{
    public function __construct(
        private readonly ContentRepository $contentRepository,
        private readonly ThumbnailGenerator $thumbnailGenerator,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $items = $this->contentRepository->findWithoutThumbnail();

        if ($items === []) {
            $io->success('All content already has a thumbnail.');

            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Generating thumbnails for <info>%d</info> video(s)…', count($items)));
        $io->newLine();

        $succeeded = 0;
        $failed = 0;

        foreach ($items as $content) {
            $label = sprintf('%s (%s)', $content->getFilename(), $content->getUploadId());
            $candidateCount = $this->thumbnailGenerator->generate($content->getUploadId(), $content->getFilename(), $content->getDuration());

            if ($candidateCount > 0) {
                $content->setHasThumbnail(true);
                $content->setThumbnailCandidateCount($candidateCount);
                $this->entityManager->flush();
                $io->writeln("  <info>✓</info> {$label}");
                ++$succeeded;
            } else {
                $io->writeln("  <error>✗</error> {$label}");
                ++$failed;
            }
        }

        $io->newLine();

        if ($failed === 0) {
            $io->success("All done — {$succeeded} thumbnail(s) generated.");
        } else {
            $io->warning("Done — {$succeeded} generated, {$failed} failed (file missing or ffmpeg error).");
        }

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
