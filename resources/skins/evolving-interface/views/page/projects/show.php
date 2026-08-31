<?php

/**
 * evolving-interface — logical view "page.projects.show".
 *
 * One project's case study. The slug that reached this template was validated
 * by the canonical slug contract and resolved to a real corpus entry before
 * dispatch, so this file renders an object and never re-parses a URL fragment.
 *
 * The page is a composition of one Content object and nothing else. Name,
 * summary, context and role are always present and always shown; everything
 * else is optional, and optional here means *omitted* — a project with no
 * documented stack shows no stack section rather than an empty one, and a
 * project whose lifecycle no source states shows no status rather than the
 * word "unspecified". Guessed copy in place of a missing fact would turn a
 * gap in the record into a claim.
 *
 * @var \Facet\Html\ViewContext $view
 * @var \Facet\Content\Project  $project
 * @var \Facet\I18n\Translator  $t
 * @var \Facet\I18n\Locale      $locale
 */

declare(strict_types=1);

use Facet\Html\Html;
use Facet\I18n\LocalizedRoutes;
use Facet\Routing\RouteCatalog;

require dirname(__DIR__, 2) . '/partials/locale-context.php';

$title = $project->name();

require dirname(__DIR__, 2) . '/partials/period-label.php';

$status = $project->status();
$period = $project->period();
$technologies = $project->technologies();
$concepts = $project->concepts();
$outcomes = $project->outcomes();
$links = $project->links();

$hasMeta = $status->isSubstantiated() || $period !== null;
$hasTags = $technologies !== [] || $concepts !== [];

$media = $project->media();
$mediaRatio = '16 / 9';

ob_start();

?>
<p class="text-sm facet-ink-subtle">
    <a class="facet-link underline" href="<?= $view->url(LocalizedRoutes::path(RouteCatalog::PROJECTS_INDEX, $locale)) ?>"><?= $view->text($t->text('projects.title')) ?></a>
</p>

<h1 class="mt-2 text-3xl font-semibold tracking-tight"><?= $view->text($project->name()) ?></h1>

<?php if ($hasMeta): ?>
<p class="mt-2 flex flex-wrap gap-x-3 text-sm facet-ink-subtle">
    <?php if ($status->isSubstantiated()): ?>
    <span><?= $view->text($t->text('content.status.' . $status->value)) ?></span>
    <?php endif; ?>
    <?php if ($period !== null): ?>
    <time datetime="<?= $view->attr($period->start()) ?>"><?= $view->text($periodLabel($period)) ?></time>
    <?php endif; ?>
</p>
<?php endif; ?>

<p class="mt-4 max-w-prose text-lg facet-ink-muted"><?= $view->text($project->summary()) ?></p>

<?php
/*
 * The same slot the catalogue uses. It sits after the summary on purpose: the
 * case study must be complete as text, so the illustration follows the claim
 * it illustrates instead of standing in for it.
 */
require dirname(__DIR__, 2) . '/partials/media.php';
?>

<section class="mt-12" aria-labelledby="context">
    <h2 id="context" class="text-xl font-semibold"><?= $view->text($t->text('project.context')) ?></h2>
    <p class="mt-4 max-w-prose facet-ink-muted"><?= $view->text($project->context()) ?></p>
</section>

<section class="mt-12" aria-labelledby="role">
    <h2 id="role" class="text-xl font-semibold"><?= $view->text($t->text('project.role')) ?></h2>
    <p class="mt-4 max-w-prose facet-ink-muted"><?= $view->text($project->role()) ?></p>
</section>

<?php if ($hasTags): ?>
<section class="mt-12" aria-labelledby="stack">
    <h2 id="stack" class="text-xl font-semibold"><?= $view->text($t->text('project.stack')) ?></h2>

    <?php
    /*
     * Two fields, never one. "PostgreSQL" is a tool and "event sourcing" is an
     * idea; folding them into a single tag row would make both unreadable and
     * would invent a vocabulary the corpus does not carry.
     */
    ?>
    <dl class="mt-4 space-y-4">
        <?php if ($technologies !== []): ?>
        <div>
            <dt class="text-sm font-medium facet-ink-subtle"><?= $view->text($t->text('projects.technologies')) ?></dt>
            <dd class="mt-1"><?= $view->join($technologies) ?></dd>
        </div>
        <?php endif; ?>
        <?php if ($concepts !== []): ?>
        <div>
            <dt class="text-sm font-medium facet-ink-subtle"><?= $view->text($t->text('projects.concepts')) ?></dt>
            <dd class="mt-1"><?= $view->join($concepts) ?></dd>
        </div>
        <?php endif; ?>
    </dl>
</section>
<?php endif; ?>

<?php if ($outcomes !== []): ?>
<section class="mt-12" aria-labelledby="outcomes">
    <h2 id="outcomes" class="text-xl font-semibold"><?= $view->text($t->text('project.outcomes')) ?></h2>
    <ul class="mt-4 max-w-prose list-disc space-y-1 pl-5 facet-ink-muted">
        <?php foreach ($outcomes as $outcome): ?>
        <li><?= $view->text($outcome) ?></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php if ($links !== []): ?>
<section class="mt-12" aria-labelledby="links">
    <h2 id="links" class="text-xl font-semibold"><?= $view->text($t->text('project.links')) ?></h2>

    <?php
    /*
     * Canonical label, canonical URL, and the link type as data so a later
     * design can group or ornament links without this file hard-coding a
     * single host. `rel` is the safe-outbound default; nothing is invented.
     */
    ?>
    <ul class="mt-4 space-y-1">
        <?php foreach ($links as $link): ?>
        <li>
            <a
                class="facet-link underline"
                href="<?= $view->url($link->url()) ?>"
                rel="noopener noreferrer"
                data-link-type="<?= $view->attr($link->type()->value) ?>"
            ><?= $view->text($link->label()) ?></a>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
<?php

$content = Html::trusted((string) ob_get_clean());

require dirname(__DIR__, 2) . '/layout.php';
