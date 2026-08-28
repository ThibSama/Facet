<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Publication;

use PHPUnit\Framework\TestCase;

/**
 * The end-to-end gates, asserted against the committed evidence.
 *
 * PORT-112 requires the suite green on Chromium twice consecutively with no
 * retry; PORT-113 requires the same suite green on all three engines. Both were
 * run, and the raw Playwright JSON of all three runs lives in
 * docs/reports/PORT-113/. This file is what stops the README beside them from
 * drifting away from what those runs actually recorded.
 *
 * It launches nothing. Re-running the suite is `npm run e2e`, and it needs a
 * server, a database and three browsers — none of which belong in a unit test.
 * What is asserted here is narrower and durable: the evidence still parses,
 * still describes the engines it claims, still counts the cases the README
 * counts, and still contains no failure, no skip and no retry. A run that had
 * been quietly re-recorded with a browser disabled, a retry allowed or a case
 * removed would fail here.
 */
final class E2eGateEvidenceTest extends TestCase
{
    private const REPORT_DIRECTORY = 'docs/reports/PORT-113';

    /** The PORT-112 focused gate: the same project, run twice in a row. */
    private const CHROMIUM_RUNS = [
        'playwright-chromium-run-1.json',
        'playwright-chromium-run-2.json',
    ];

    /** The PORT-113 gate: one run, three engines, the same cases in each. */
    private const THREE_ENGINE_RUN = 'playwright-three-engines.json';

    private const ENGINES = ['chromium', 'firefox', 'webkit'];

    /** Cases in the suite, per engine. Raising this is a deliberate edit. */
    private const CASES_PER_ENGINE = 43;

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @return array<string, mixed>
     */
    private static function report(string $name): array
    {
        $path = self::root() . '/' . self::REPORT_DIRECTORY . '/' . $name;

        self::assertFileExists($path, sprintf('The committed E2E evidence "%s" is missing.', $name));

        $contents = file_get_contents($path);
        self::assertIsString($contents);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * Every test result in a run, flattened out of Playwright's nested suites.
     *
     * @param array<string, mixed> $report
     *
     * @return list<array{project: string, title: string, status: string, attempts: int}>
     */
    private static function results(array $report): array
    {
        /** @var list<array{project: string, title: string, status: string, attempts: int}> $results */
        $results = [];

        /** @var list<array<string, mixed>> $suites */
        $suites = is_array($report['suites'] ?? null) ? $report['suites'] : [];

        $walk = static function (array $suite) use (&$walk, &$results): void {
            /** @var list<array<string, mixed>> $specs */
            $specs = is_array($suite['specs'] ?? null) ? $suite['specs'] : [];

            foreach ($specs as $spec) {
                /** @var list<array<string, mixed>> $tests */
                $tests = is_array($spec['tests'] ?? null) ? $spec['tests'] : [];

                foreach ($tests as $test) {
                    /** @var list<array<string, mixed>> $attempts */
                    $attempts = is_array($test['results'] ?? null) ? $test['results'] : [];

                    $results[] = [
                        'project' => (string) ($test['projectName'] ?? ''),
                        'title' => (string) ($spec['title'] ?? ''),
                        'status' => (string) ($attempts[0]['status'] ?? 'missing'),
                        'attempts' => count($attempts),
                    ];
                }
            }

            /** @var list<array<string, mixed>> $children */
            $children = is_array($suite['suites'] ?? null) ? $suite['suites'] : [];

            foreach ($children as $child) {
                $walk($child);
            }
        };

        foreach ($suites as $suite) {
            $walk($suite);
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $report
     */
    private static function assertRunIsClean(array $report, string $name): void
    {
        /** @var array<string, mixed> $stats */
        $stats = is_array($report['stats'] ?? null) ? $report['stats'] : [];

        self::assertSame(0, (int) ($stats['unexpected'] ?? -1), $name . ': a case failed.');
        self::assertSame(0, (int) ($stats['flaky'] ?? -1), $name . ': a case only passed on a retry.');
        self::assertSame(0, (int) ($stats['skipped'] ?? -1), $name . ': a case was skipped.');

        foreach (self::results($report) as $result) {
            self::assertSame('passed', $result['status'], sprintf('%s: "%s" did not pass.', $name, $result['title']));
            self::assertSame(1, $result['attempts'], sprintf(
                '%s: "%s" needed more than one attempt; the gate is a run without retries.',
                $name,
                $result['title']
            ));
        }
    }

    public function testTheChromiumGateWasRunTwiceAndPassedBothTimes(): void
    {
        $titles = [];

        foreach (self::CHROMIUM_RUNS as $name) {
            $report = self::report($name);

            self::assertRunIsClean($report, $name);

            $results = self::results($report);
            self::assertCount(self::CASES_PER_ENGINE, $results, $name . ': unexpected number of cases.');

            foreach ($results as $result) {
                self::assertSame('chromium', $result['project'], $name . ': a case ran on another engine.');
            }

            $run = array_map(static fn (array $result): string => $result['title'], $results);
            sort($run);
            $titles[] = $run;
        }

        // The same suite twice, not two different subsets that each happened to
        // be green.
        self::assertSame($titles[0], $titles[1], 'The two Chromium runs did not cover the same cases.');
    }

    public function testTheSameSuiteRanOnAllThreeEngines(): void
    {
        $report = self::report(self::THREE_ENGINE_RUN);

        self::assertRunIsClean($report, self::THREE_ENGINE_RUN);

        /** @var array<string, list<string>> $byEngine */
        $byEngine = [];

        foreach (self::results($report) as $result) {
            $byEngine[$result['project']][] = $result['title'];
        }

        self::assertSame(self::ENGINES, array_keys($byEngine), 'The run did not cover exactly the three engines.');

        foreach (self::ENGINES as $engine) {
            sort($byEngine[$engine]);
            self::assertCount(self::CASES_PER_ENGINE, $byEngine[$engine], $engine . ': unexpected number of cases.');
        }

        // No engine-specific disabling: every case ran everywhere, so a browser
        // cannot be made to pass by being asked less.
        self::assertSame(
            $byEngine[self::ENGINES[0]],
            $byEngine[self::ENGINES[1]],
            'Firefox did not run the same cases as Chromium.'
        );
        self::assertSame(
            $byEngine[self::ENGINES[0]],
            $byEngine[self::ENGINES[2]],
            'WebKit did not run the same cases as Chromium.'
        );
    }

    public function testTheGateForbidsRetriesAndRunsOneWorker(): void
    {
        foreach ([...self::CHROMIUM_RUNS, self::THREE_ENGINE_RUN] as $name) {
            $report = self::report($name);

            /** @var array<string, mixed> $config */
            $config = is_array($report['config'] ?? null) ? $report['config'] : [];
            /** @var list<array<string, mixed>> $projects */
            $projects = is_array($config['projects'] ?? null) ? $config['projects'] : [];

            self::assertNotSame([], $projects, $name . ': the run recorded no project.');

            foreach ($projects as $project) {
                self::assertSame(0, (int) ($project['retries'] ?? -1), sprintf(
                    '%s: project "%s" was configured with retries; a retried pass is not this gate.',
                    $name,
                    (string) ($project['name'] ?? '?')
                ));
            }

            // One worker, because one server and one schema are shared: two
            // tests at once would observe each other's rows.
            self::assertFalse((bool) ($config['fullyParallel'] ?? true), $name . ': the run was fully parallel.');
        }
    }
}
