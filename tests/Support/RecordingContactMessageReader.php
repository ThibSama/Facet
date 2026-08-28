<?php

declare(strict_types=1);

namespace Facet\Tests\Support;

use Facet\Contact\ContactInboxException;
use Facet\Contact\ContactMessage;
use Facet\Contact\ContactMessageReader;
use Facet\Contact\ContactMessageMutationException;
use Facet\Contact\ContactMessageStatus;
use Facet\Contact\ContactMessageStatusUpdater;

final class RecordingContactMessageReader implements ContactMessageReader, ContactMessageStatusUpdater
{
    /** @var list<ContactMessage> */
    private array $messages;

    private bool $failing;

    private ?int $lastLimit = null;

    /** @param list<ContactMessage> $messages */
    public function __construct(array $messages = [], bool $failing = false)
    {
        usort($messages, static fn (ContactMessage $a, ContactMessage $b): int => $b->id() <=> $a->id());
        $this->messages = $messages;
        $this->failing = $failing;
    }

    /** @param list<ContactMessage> $messages */
    public static function with(array $messages): self
    {
        return new self($messages);
    }

    public static function failing(): self
    {
        return new self([], true);
    }

    public function newest(int $limit): array
    {
        if ($this->failing) {
            throw ContactInboxException::readFailed();
        }

        $this->lastLimit = $limit;

        return array_slice($this->messages, 0, $limit);
    }

    public function find(int $id): ?ContactMessage
    {
        if ($this->failing) {
            throw ContactInboxException::readFailed();
        }

        foreach ($this->messages as $message) {
            if ($message->id() === $id) {
                return $message;
            }
        }

        return null;
    }

    public function updateStatus(int $id, ContactMessageStatus $status): bool
    {
        if ($this->failing) {
            throw ContactMessageMutationException::updateFailed();
        }

        foreach ($this->messages as $index => $message) {
            if ($message->id() !== $id) {
                continue;
            }

            $this->messages[$index] = new ContactMessage(
                $message->id(),
                $message->name(),
                $message->email(),
                $message->subject(),
                $message->message(),
                $status,
                $message->createdAt()
            );

            return true;
        }

        return false;
    }

    public function lastLimit(): ?int
    {
        return $this->lastLimit;
    }
}
