<?php

declare(strict_types=1);

namespace Facet\Content;

use Facet\Content\Exception\InvalidContentException;

/**
 * An outbound link with a human label.
 *
 * Labels are content, not presentation: no icon name, no CSS class, no target
 * attribute. A skin decides how a link of a given type looks.
 */
final class Link
{
    private string $label;

    private string $url;

    private LinkType $type;

    private function __construct(string $label, string $url, LinkType $type)
    {
        $this->label = $label;
        $this->url = $url;
        $this->type = $type;
    }

    /**
     * @throws InvalidContentException when the label is empty or the URL is not absolute http(s)
     */
    public static function create(string $label, string $url, LinkType $type): self
    {
        if (trim($label) === '') {
            throw InvalidContentException::because('link', 'a link label must not be empty');
        }

        if (!self::isAbsoluteHttpUrl($url)) {
            throw InvalidContentException::because(
                'link',
                sprintf('"%s" is not an absolute http(s) URL', $url)
            );
        }

        return new self($label, $url, $type);
    }

    public static function isAbsoluteHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        return in_array($scheme, ['http', 'https'], true) && is_string($host) && $host !== '';
    }

    public function label(): string
    {
        return $this->label;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function type(): LinkType
    {
        return $this->type;
    }
}
