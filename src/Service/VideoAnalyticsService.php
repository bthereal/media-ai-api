<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\AnalyticsOverviewDto;
use App\Dto\RetentionPointDto;
use App\Dto\VideoAnalyticsDto;
use App\Dto\VideoAnalyticsSummaryDto;
use App\Entity\Content;
use App\Repository\ContentRepository;
use App\Repository\WatchEventRepository;

/**
 * Aggregates raw WatchEvent rows into the watch-analytics DTOs served by ContentController/AnalyticsController.
 *
 * Watch time is approximated from "progress" heartbeat events (one per HEARTBEAT_INTERVAL_SECONDS
 * of active playback, throttled client-side) rather than from precise play/pause intervals — a
 * deliberate simplification to keep event volume and aggregation queries cheap.
 */
class VideoAnalyticsService
{
    private const int HEARTBEAT_INTERVAL_SECONDS = 5;
    private const int RETENTION_BUCKET_STEP_PERCENT = 10;
    private const int RESUME_MIN_SECONDS = 10;
    private const int RESUME_END_BUFFER_SECONDS = 5;

    public function __construct(
        private readonly WatchEventRepository $watchEventRepository,
        private readonly ContentRepository $contentRepository,
    ) {
    }

    public function buildVideoAnalytics(Content $content): VideoAnalyticsDto
    {
        $contentId = $content->getId();
        $views = $this->watchEventRepository->countDistinctViewers($contentId);

        if (0 === $views) {
            return new VideoAnalyticsDto(
                ok: true,
                contentId: (string) $contentId,
                views: 0,
                completionRate: 0.0,
                totalWatchTimeSeconds: 0.0,
                averageWatchTimeSeconds: 0.0,
                averageDropOffSeconds: null,
                retentionCurve: [],
            );
        }

        $maxPositions = $this->watchEventRepository->getViewerMaxPositions($contentId);
        $progressCounts = $this->watchEventRepository->getViewerProgressCounts($contentId);
        $completed = $this->watchEventRepository->countCompletedViewers($contentId);

        $totalWatchTimeSeconds = array_sum($progressCounts) * self::HEARTBEAT_INTERVAL_SECONDS;
        $averageDropOffSeconds = array_sum($maxPositions) / (float) count($maxPositions);

        return new VideoAnalyticsDto(
            ok: true,
            contentId: (string) $contentId,
            views: $views,
            completionRate: (float) $completed / (float) $views * 100.0,
            totalWatchTimeSeconds: $totalWatchTimeSeconds,
            averageWatchTimeSeconds: $totalWatchTimeSeconds / $views,
            averageDropOffSeconds: $averageDropOffSeconds,
            retentionCurve: $this->buildRetentionCurve($content->getDuration(), $maxPositions, $views),
        );
    }

    public function buildOverview(): AnalyticsOverviewDto
    {
        $items = $this->contentRepository->findBy(['deletedAt' => null]);

        $summaries = [];
        $totalViews = 0;
        $totalWatchTimeSeconds = 0.0;
        $completionRateSum = 0.0;
        $videosWithViews = 0;

        foreach ($items as $content) {
            $analytics = $this->buildVideoAnalytics($content);

            if ($analytics->views > 0) {
                ++$videosWithViews;
                $completionRateSum += $analytics->completionRate;
            }

            $totalViews += $analytics->views;
            $totalWatchTimeSeconds += $analytics->totalWatchTimeSeconds;

            $summaries[] = new VideoAnalyticsSummaryDto(
                contentId: (string) $content->getId(),
                title: $content->getTitle(),
                filename: $content->getFilename(),
                views: $analytics->views,
                completionRate: $analytics->completionRate,
                watchTimeSeconds: $analytics->totalWatchTimeSeconds,
            );
        }

        usort($summaries, static fn (VideoAnalyticsSummaryDto $a, VideoAnalyticsSummaryDto $b): int => $b->views <=> $a->views);

        return new AnalyticsOverviewDto(
            ok: true,
            totalVideos: count($items),
            totalViews: $totalViews,
            totalWatchTimeSeconds: $totalWatchTimeSeconds,
            averageCompletionRate: $videosWithViews > 0 ? $completionRateSum / (float) $videosWithViews : 0.0,
            videos: $summaries,
        );
    }

    /**
     * Returns where this viewer left off, or null when there's nothing worth resuming:
     * no prior events, the viewer already finished the video, they're within the first
     * RESUME_MIN_SECONDS, or they're within RESUME_END_BUFFER_SECONDS of the end.
     */
    public function getResumePosition(Content $content, string $viewerId): ?float
    {
        $lastEvent = $this->watchEventRepository->getLastEventForViewer($content->getId(), $viewerId);

        if (null === $lastEvent || 'complete' === $lastEvent->getEventType()) {
            return null;
        }

        $position = $lastEvent->getPositionSeconds();

        if ($position < self::RESUME_MIN_SECONDS) {
            return null;
        }

        $duration = $content->getDuration();
        if (null !== $duration && $position >= $duration - (float) self::RESUME_END_BUFFER_SECONDS) {
            return null;
        }

        return $position;
    }

    /**
     * @param array<string, float> $maxPositions viewerId => furthest position reached (seconds)
     *
     * @return list<RetentionPointDto>
     */
    private function buildRetentionCurve(?float $duration, array $maxPositions, int $views): array
    {
        if (null === $duration || $duration <= 0) {
            return [];
        }

        $curve = [];
        for ($percent = 0; $percent <= 100; $percent += self::RETENTION_BUCKET_STEP_PERCENT) {
            $threshold = $duration * (float) $percent / 100.0;
            $remaining = 0;
            foreach ($maxPositions as $maxPosition) {
                if ($maxPosition >= $threshold) {
                    ++$remaining;
                }
            }
            $curve[] = new RetentionPointDto(percent: $percent, retentionRate: (float) $remaining / (float) $views * 100.0);
        }

        return $curve;
    }
}
