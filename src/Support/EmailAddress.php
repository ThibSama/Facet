<?php

declare(strict_types=1);

namespace Facet\Support;

/**
 * The one definition of what a stored email address looks like.
 *
 * Uniqueness is only meaningful once "the same address" is defined, and
 * `Ada@Example.COM ` and `ada@example.com` are the same account. Normalisation
 * therefore happens here, in one place, and the schema enforces the result
 * with a CHECK — application code that forgets to call this is rejected by the
 * database rather than quietly creating a duplicate account.
 *
 * The domain is lowercased because DNS is case-insensitive. The local part is
 * lowercased too: that is technically stricter than RFC 5321, which permits a
 * case-sensitive local part, but every mail provider Facet will meet treats
 * addresses case-insensitively, and the alternative is two accounts that look
 * identical to a human.
 */
final class EmailAddress
{
    /**
     * RFC 5321's maximum length for a forward path, and the width of the
     * `email` columns.
     */
    public const MAX_LENGTH = 254;

    /**
     * Trim surrounding whitespace and lowercase the whole address.
     */
    public static function normalise(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Normalise, then reject anything that is not a storable address.
     *
     * @throws InvalidEmailAddressException
     */
    public static function canonical(string $email): string
    {
        $normalised = self::normalise($email);

        if ($normalised === '') {
            throw InvalidEmailAddressException::empty();
        }

        if (strlen($normalised) > self::MAX_LENGTH) {
            throw InvalidEmailAddressException::tooLong($normalised, self::MAX_LENGTH);
        }

        if (filter_var($normalised, FILTER_VALIDATE_EMAIL) === false) {
            throw InvalidEmailAddressException::malformed($normalised);
        }

        return $normalised;
    }

    public static function isValid(string $email): bool
    {
        try {
            self::canonical($email);

            return true;
        } catch (InvalidEmailAddressException) {
            return false;
        }
    }
}
