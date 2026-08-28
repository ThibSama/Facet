<?php

declare(strict_types=1);

namespace Facet\Contact;

/**
 * The outcome of validating one submission.
 *
 * It carries three things at once because re-rendering a failed form needs all
 * three: what was wrong (per field, so the message lands on the control it
 * belongs to), what the visitor had typed (so a rejected form is corrected
 * rather than retyped), and — only when everything passed — the submission
 * itself.
 *
 * The returned values are the *normalised* ones, never the raw request. They
 * are still untrusted text and are escaped at render like anything else; what
 * normalisation guarantees is that the value echoed back is the value that was
 * judged, so a visitor cannot be shown one string and have another rejected.
 */
final class ContactValidation
{
    /** @var array<string, string> */
    private array $values;

    /** @var array<string, string> */
    private array $errors;

    private ?ContactSubmission $submission;

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function __construct(array $values, array $errors, ?ContactSubmission $submission)
    {
        $this->values = $values;
        $this->errors = $errors;
        $this->submission = $submission;
    }

    /**
     * @param array<string, string> $values
     */
    public static function accepted(array $values, ContactSubmission $submission): self
    {
        return new self($values, [], $submission);
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    public static function rejected(array $values, array $errors): self
    {
        return new self($values, $errors, null);
    }

    public function isValid(): bool
    {
        return $this->errors === [] && $this->submission !== null;
    }

    /**
     * Field name to a human-readable reason. Empty when the submission passed.
     *
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function error(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }

    /**
     * The normalised value of every known field, ready to be re-rendered.
     *
     * @return array<string, string>
     */
    public function values(): array
    {
        return $this->values;
    }

    /**
     * The submission, or null when validation failed.
     */
    public function submission(): ?ContactSubmission
    {
        return $this->submission;
    }
}
