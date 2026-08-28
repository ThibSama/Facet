<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Publication;

use PHPUnit\Framework\TestCase;

/**
 * The publication Lighthouse gate, asserted against the committed evidence.
 *
 * PORT-111 requires three Lighthouse runs over one frozen production build and
 * a median that clears six thresholds. Those runs were narrated once and then
 * lost; the raw reports now live in docs/reports/PORT-111/ and this file is
 * what keeps them honest. It never launches a browser — re-measuring is the
 * job of the harness described in that directory's README. What it does is
 * refuse to let the recorded evidence drift: every committed report must still
 * parse, still name the Lighthouse version and audited URL the README claims,
 * still carry the three categories, and the median it yields must still clear
 * the gate.
 *
 * The median is the middle of three sorted values — the same statistic the
 * README publishes, computed here from the JSON rather than transcribed, so a
 * hand-edited number in the prose cannot survive the suite.
 */
final class LighthouseGateEvidenceTest extends TestCase
{
    private const REPORT_DIRECTORY = 'docs/reports/PORT-111';

    private const RUNS = 3;

    /** The URL the harness audits: loopback transport, not the canonical origin. */
    private const AUDITED_URL = 'http://127.0.0.1:8000/';

    private const LIGHTHOUSE_VERSION = '13.4.1';

    private const CATEGORIES = ['performance', 'accessibility', 'seo'];

    /** Score thresholds, as percentages: the median may not fall below these. */
    private const SCORE_FLOORS = [
        'performance' => 90,
        'accessibility' => 95,
        'seo' => 95,
    ];

    /** Metric ceilings: the median may not exceed these. */
    private const METRIC_CEILINGS = [
        'largest-contentful-paint' => 2500.0,
        'cumulative-layout-shift' => 0.1,
        'total-blocking-time' => 200.0,
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function reports(): array
    {
        $reports = [];

        for ($run = 1; $run <= self::RUNS; $run++) {
            $path = self::root() . '/' . self::REPORT_DIRECTORY . '/lighthouse-run-' . $run . '.json';
            $raw = @file_get_contents($path);

            self::assertIsString($raw, sprintf('Missing Lighthouse report for run %d (%s).', $run, $path));

            $decoded = json_decode($raw, true);

            self::assertIsArray($decoded, sprintf('Run %d does not parse as JSON.', $run));

            $reports[$run] = $decoded;
        }

        return $reports;
    }

    public function testEveryCommittedReportIdentifiesTheAuditedRun(): void
    {
        foreach (self::reports() as $run => $report) {
            self::assertSame(
                self::LIGHTHOUSE_VERSION,
                $report['lighthouseVersion'] ?? null,
                sprintf('Run %d was produced by an unexpected Lighthouse version.', $run)
            );

            self::assertSame(
                self::AUDITED_URL,
                $report['finalDisplayedUrl'] ?? null,
                sprintf('Run %d audited an unexpected URL.', $run)
            );

            self::assertSame('mobile', $report['configSettings']['formFactor'] ?? null, sprintf('Run %d is not a mobile run.', $run));
            self::assertSame('simulate', $report['configSettings']['throttlingMethod'] ?? null, sprintf('Run %d did not use simulated throttling.', $run));

            foreach (self::CATEGORIES as $category) {
                self::assertArrayHasKey($category, $report['categories'] ?? [], sprintf('Run %d is missing the %s category.', $run, $category));
                self::assertIsNumeric($report['categories'][$category]['score'], sprintf('Run %d has no %s score.', $run, $category));
            }

            foreach (array_keys(self::METRIC_CEILINGS) as $audit) {
                self::assertIsNumeric(
                    $report['audits'][$audit]['numericValue'] ?? null,
                    sprintf('Run %d is missing the %s measurement.', $run, $audit)
                );
            }
        }
    }

    public function testMedianScoresClearTheGate(): void
    {
        $reports = self::reports();

        foreach (self::SCORE_FLOORS as $category => $floor) {
            $scores = array_map(
                static fn (array $report): int => (int) round(((float) $report['categories'][$category]['score']) * 100),
                $reports
            );

            self::assertGreaterThanOrEqual(
                $floor,
                self::median($scores),
                sprintf('Median %s score is below the publication gate (runs: %s).', $category, implode(', ', $scores))
            );
        }
    }

    public function testMedianMetricsClearTheGate(): void
    {
        $reports = self::reports();

        foreach (self::METRIC_CEILINGS as $audit => $ceiling) {
            $values = array_map(
                static fn (array $report): float => (float) $report['audits'][$audit]['numericValue'],
                $reports
            );

            self::assertLessThanOrEqual(
                $ceiling,
                self::median($values),
                sprintf('Median %s exceeds the publication gate (runs: %s).', $audit, implode(', ', $values))
            );
        }
    }

    /**
     * @param array<int, int|float> $values
     */
    private static function median(array $values): int|float
    {
        $sorted = array_values($values);
        sort($sorted);

        self::assertCount(self::RUNS, $sorted);

        return $sorted[intdiv(self::RUNS, 2)];
    }
}
