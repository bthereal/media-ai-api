<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Content;
use App\Entity\VideoTranscription;
use App\Service\VideoSummaryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:embed-videos',
    description: 'Embed transcripts into the vector store for all completed transcriptions',
)]
class EmbedVideosCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VideoSummaryService $summaryService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $items = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Content::class, 'c')
            ->join('c.transcription', 't')
            ->where('t.status = :status')
            ->setParameter('status', 'completed')
            ->getQuery()
            ->getResult();

        if ([] === $items) {
            $io->success('No completed transcriptions found.');

            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Embedding <info>%d</info> video(s)…', count($items)));

        $succeeded = 0;
        $failed = 0;

        foreach ($items as $content) {
            assert($content instanceof Content);
            $transcription = $content->getTranscription();
            assert($transcription instanceof VideoTranscription);

            $label = sprintf('%s (%s)', $content->getFilename(), $content->getUploadId());

            try {
                $this->summaryService->embedAndStore(
                    contentId: (string) $content->getId(),
                    transcript: $transcription->getTranscription(),
                    title: $content->getTitle() ?? $content->getFilename(),
                );

                $io->writeln("  <info>✓</info> {$label}");
                ++$succeeded;
            } catch (\Throwable $e) {
                $io->writeln("  <error>✗</error> {$label}: {$e->getMessage()}");
                ++$failed;
            }
        }

        $io->newLine();

        if (0 === $failed) {
            $io->success("Done — {$succeeded} embedding(s) generated.");
        } else {
            $io->warning("Done — {$succeeded} succeeded, {$failed} failed.");
        }

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
