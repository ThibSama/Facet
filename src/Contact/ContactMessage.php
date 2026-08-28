<?php

declare(strict_types=1);

namespace Facet\Contact;

/** A contact message as the private inbox is allowed to read it. */
final class ContactMessage
{
    public function __construct(
        private int $id,
        private string $name,
        private string $email,
        private string $subject,
        private string $message,
        private ContactMessageStatus $status,
        private string $createdAt
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function status(): ContactMessageStatus
    {
        return $this->status;
    }

    public function createdAt(): string
    {
        return $this->createdAt;
    }
}
