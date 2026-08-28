<?php

declare(strict_types=1);

namespace Facet\Contact;

/**
 * One validated contact message, ready to be stored.
 *
 * The type is the guarantee: an instance cannot be constructed except by
 * {@see ContactValidator}, so a repository that accepts one of these is
 * accepting something whose bounds have already been checked against the
 * columns it is about to be written into. There is no path from a raw request
 * array to a stored row that skips the validator, because there is no other
 * way to obtain this object.
 *
 * It carries the four fields the schema has and nothing else — no token, no
 * honeypot, no throttle bookkeeping, no request metadata. What the table stores
 * is exactly what a person typed.
 */
final class ContactSubmission
{
    private string $name;

    private string $email;

    private string $subject;

    private string $message;

    /**
     * @internal Constructed only by {@see ContactValidator}.
     */
    public function __construct(string $name, string $email, string $subject, string $message)
    {
        $this->name = $name;
        $this->email = $email;
        $this->subject = $subject;
        $this->message = $message;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** Canonical, lowercased — the form the `email` CHECK constraint requires. */
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

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'subject' => $this->subject,
            'message' => $this->message,
        ];
    }
}
