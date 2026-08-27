<?php

declare(strict_types=1);

namespace Facet\Content;

/**
 * A canonical entry that can be walked as plain text.
 *
 * This is what lets tests traverse the entire corpus without a renderer, and
 * what a future search index or plain-text export would consume. Fragments are
 * human-readable prose or labels — never markup.
 */
interface TextualEntry
{
    /**
     * Every human-readable string this entry carries, in a stable order.
     *
     * @return list<string>
     */
    public function textFragments(): array;
}
