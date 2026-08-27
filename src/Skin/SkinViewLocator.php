<?php

declare(strict_types=1);

namespace Facet\Skin;

/**
 * Turns a logical view identifier into an absolute template path.
 *
 * This is the only component that knows a skin's views are PHP files on disk.
 * Shared code asks for "page.home"; which file answers it is the skin's
 * business, and swapping skins never touches the caller.
 */
final class SkinViewLocator
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim(str_replace('\\', '/', $basePath), '/');
    }

    public function has(SkinDefinition $skin, string $view): bool
    {
        try {
            $path = $this->absolutePath($skin, $view);
        } catch (UnknownViewException) {
            return false;
        }

        return is_file($path);
    }

    /**
     * @throws UnknownViewException when the identifier is malformed or unresolved
     */
    public function locate(SkinDefinition $skin, string $view): string
    {
        $path = $this->absolutePath($skin, $view);

        if (!is_file($path)) {
            throw UnknownViewException::forView($skin->id(), $view, $path);
        }

        return $path;
    }

    /**
     * @throws UnknownViewException when the identifier is malformed
     */
    private function absolutePath(SkinDefinition $skin, string $view): string
    {
        return $this->basePath . '/' . $skin->viewPath($view);
    }
}
