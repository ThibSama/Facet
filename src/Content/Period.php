<?php

declare(strict_types=1);

namespace Facet\Content;

use Facet\Content\Exception\InvalidContentException;

/**
 * A date range expressed only as precisely as the source supports.
 *
 * Sources for this corpus state years (and sometimes months); inventing a day
 * would be inventing a fact, so the grammar stops at YYYY or YYYY-MM.
 */
final class Period
{
    private string $start;

    private ?string $end;

    private function __construct(string $start, ?string $end)
    {
        $this->start = $start;
        $this->end = $end;
    }

    /**
     * @throws InvalidContentException when a bound is malformed or reversed
     */
    public static function create(string $start, ?string $end): self
    {
        self::assertBound($start, 'start');

        if ($end !== null) {
            self::assertBound($end, 'end');

            if (self::comparable($end) < self::comparable($start)) {
                throw InvalidContentException::because(
                    'period',
                    sprintf('end "%s" precedes start "%s"', $end, $start)
                );
            }
        }

        return new self($start, $end);
    }

    public static function ongoingFrom(string $start): self
    {
        return self::create($start, null);
    }

    private static function assertBound(string $bound, string $which): void
    {
        if (preg_match('/^\d{4}(-(0[1-9]|1[0-2]))?$/', $bound) !== 1) {
            throw InvalidContentException::because(
                'period',
                sprintf('%s "%s" must be YYYY or YYYY-MM', $which, $bound)
            );
        }
    }

    private static function comparable(string $bound): string
    {
        return strlen($bound) === 4 ? $bound . '-00' : $bound;
    }

    public function start(): string
    {
        return $this->start;
    }

    public function end(): ?string
    {
        return $this->end;
    }

    public function isOngoing(): bool
    {
        return $this->end === null;
    }

    public function startYear(): int
    {
        return (int) substr($this->start, 0, 4);
    }
}
