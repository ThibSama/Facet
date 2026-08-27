<?php

/**
 * evolving-interface — logical view "page.projects.show".
 *
 * The slug that reached this template was validated by the canonical slug
 * contract before dispatch and resolved to a real corpus entry, so the view
 * renders an object rather than re-parsing a URL fragment.
 *
 * @var \Facet\Html\ViewContext $view
 * @var \Facet\Content\Project  $project
 */

declare(strict_types=1);

use Facet\Html\Html;

$title = $project->name();

ob_start();

?>
<p class="text-sm text-slate-500">
    <a class="underline" href="<?= $view->url('/projects') ?>">Projects</a>
</p>
<h1 class="mt-2 text-3xl font-semibold tracking-tight"><?= $view->text($project->name()) ?></h1>
<p class="mt-4 max-w-prose text-lg text-slate-700"><?= $view->text($project->summary()) ?></p>

<dl class="mt-8 space-y-4">
    <div>
        <dt class="text-sm font-medium text-slate-500">Role</dt>
        <dd><?= $view->text($project->role()) ?></dd>
    </div>
    <div>
        <dt class="text-sm font-medium text-slate-500">Context</dt>
        <dd><?= $view->text($project->context()) ?></dd>
    </div>
    <div>
        <dt class="text-sm font-medium text-slate-500">Status</dt>
        <dd><?= $view->text($project->status()->value) ?></dd>
    </div>
    <div>
        <dt class="text-sm font-medium text-slate-500">Technologies</dt>
        <dd><?= $view->join($project->technologies()) ?></dd>
    </div>
</dl>

<?php if ($project->outcomes() !== []): ?>
<section class="mt-12" aria-labelledby="outcomes">
    <h2 id="outcomes" class="text-xl font-semibold">Outcomes</h2>
    <ul class="mt-4 list-disc space-y-1 pl-5">
        <?php foreach ($project->outcomes() as $outcome): ?>
        <li><?= $view->text($outcome) ?></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php if ($project->links() !== []): ?>
<section class="mt-12" aria-labelledby="links">
    <h2 id="links" class="text-xl font-semibold">Links</h2>
    <ul class="mt-4 space-y-1">
        <?php foreach ($project->links() as $link): ?>
        <li>
            <a class="underline" href="<?= $view->url($link->url()) ?>" rel="noopener noreferrer">
                <?= $view->text($link->label()) ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
<?php

$content = Html::trusted((string) ob_get_clean());

require dirname(__DIR__, 2) . '/layout.php';
