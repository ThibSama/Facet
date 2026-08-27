<?php

declare(strict_types=1);

namespace Facet\Skin;

use Facet\Html\ViewContext;
use Throwable;

/**
 * Renders a logical view with the selected skin and returns the markup.
 *
 * Templates are plain PHP: server-side rendering stays the delivery mechanism
 * and the shared runtime never learns a skin's file layout. Output is buffered
 * so a template that throws mid-render cannot emit half a document.
 *
 * Every template is handed a `$view` {@see ViewContext}, which is the escaping
 * surface it is expected to print through. The renderer supplies it rather than
 * letting each skin define its own helper, so escaping cannot vary by skin.
 */
final class SkinRenderer
{
    private SkinViewLocator $locator;

    public function __construct(SkinViewLocator $locator)
    {
        $this->locator = $locator;
    }

    public static function forBasePath(string $basePath): self
    {
        return new self(new SkinViewLocator($basePath));
    }

    /**
     * Whether this skin can answer the logical view at all — asked before
     * rendering when a failure would be worse than a fallback.
     */
    public function supports(SkinDefinition $skin, string $view): bool
    {
        return $this->locator->has($skin, $view);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws UnknownViewException when the skin has no template for the view
     */
    public function render(SkinDefinition $skin, string $view, array $data = []): string
    {
        $template = $this->locator->locate($skin, $view);

        // Reserved: supplied by the renderer so no skin has to define — or
        // forget to define — its own escaping helper.
        $data['view'] = new ViewContext();

        $level = ob_get_level();
        ob_start();

        try {
            (static function (string $__template, array $__data): void {
                extract($__data, EXTR_SKIP);

                require $__template;
            })($template, $data);

            $html = ob_get_clean();
        } catch (Throwable $error) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $error;
        }

        return $html === false ? '' : $html;
    }
}
