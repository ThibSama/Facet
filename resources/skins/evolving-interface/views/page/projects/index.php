<?php

/**
 * evolving-interface — logical view "page.projects.index".
 *
 * The whole catalogue, server-rendered. Every public project the corpus
 * declares appears here exactly once, in the corpus's own order, with a real
 * link to its case study — there is no filter, no tab and no carousel, so the
 * list a visitor receives is the list, not a seed a script has to finish.
 *
 * Nothing here is a fact: names, summaries, tags, dates and statuses are read
 * off canonical Content objects, and the only prose written in this file is
 * the skin's chrome — the page heading and the two field labels. Optional
 * facts are rendered when the corpus documents them and omitted when it does
 * not, because an empty label and the literal string "unspecified" are both
 * claims a portfolio should not make.
 *
 * @var \Facet\Html\ViewContext      $view
 * @var list<\Facet\Content\Project> $projects
 */

declare(strict_types=1);

use Facet\Html\Html;

$title = 'Projects';

require dirname(__DIR__, 2) . '/partials/period-label.php';

ob_start();

?>
<h1 class="text-3xl font-semibold tracking-tight">Projects</h1>

<?php if ($projects !== []): ?>
<ul class="mt-8 grid gap-6 sm:grid-cols-2 facet-card-grid facet-project-grid">
    <?php foreach ($projects as $project): ?>
    <?php
    $slug = $project->slug()->value();
    $href = '/projects/' . $slug;
    $headingId = 'project-' . $slug;

    $status = $project->status();
    $period = $project->period();
    $technologies = $project->technologies();
    $concepts = $project->concepts();

    // Status and period are independently optional. Neither documented means
    // no meta line at all, rather than a line holding an empty span.
    $hasMeta = $status->isSubstantiated() || $period !== null;

    // Tools and ideas stay separate: "PostgreSQL" and "event sourcing" are
    // not the same kind of statement, and merging them would invent a tag
    // vocabulary the corpus does not have.
    $hasTags = $technologies !== [] || $concepts !== [];

    $media = $project->media();
    $mediaRatio = '16 / 9';
    ?>
    <li class="flex flex-col rounded border facet-border p-5 facet-card facet-project-card">
        <article aria-labelledby="<?= $view->attr($headingId) ?>" class="flex h-full flex-col">
            <?php
            /*
             * The illustration slot leads the card and reserves its own
             * geometry, so the grid keeps the same shape whether or not a
             * project has an image yet. Everything below it is readable on
             * its own — the slot adds no fact of its own.
             */
            require dirname(__DIR__, 2) . '/partials/media.php';
            ?>

            <h2 id="<?= $view->attr($headingId) ?>" class="mt-4 text-lg font-medium">
                <a class="facet-link hover:underline" href="<?= $view->url($href) ?>">
                    <?= $view->text($project->name()) ?>
                </a>
            </h2>

            <?php if ($hasMeta): ?>
            <p class="mt-1 flex flex-wrap gap-x-3 text-sm facet-ink-subtle">
                <?php if ($status->isSubstantiated()): ?>
                <span><?= $view->text($status->value) ?></span>
                <?php endif; ?>
                <?php if ($period !== null): ?>
                <time datetime="<?= $view->attr($period->start()) ?>"><?= $view->text($periodLabel($period)) ?></time>
                <?php endif; ?>
            </p>
            <?php endif; ?>

            <p class="mt-3 facet-ink-muted"><?= $view->text($project->summary()) ?></p>

            <?php if ($hasTags): ?>
            <dl class="mt-3 space-y-1 text-sm facet-ink-subtle">
                <?php if ($technologies !== []): ?>
                <div>
                    <dt class="inline font-medium">Technologies:</dt>
                    <dd class="inline"><?= $view->join($technologies) ?></dd>
                </div>
                <?php endif; ?>
                <?php if ($concepts !== []): ?>
                <div>
                    <dt class="inline font-medium">Concepts:</dt>
                    <dd class="inline"><?= $view->join($concepts) ?></dd>
                </div>
                <?php endif; ?>
            </dl>
            <?php endif; ?>
        </article>
    </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
<?php

$content = Html::trusted((string) ob_get_clean());

require dirname(__DIR__, 2) . '/layout.php';
