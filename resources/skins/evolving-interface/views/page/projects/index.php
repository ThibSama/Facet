<?php

/**
 * evolving-interface — logical view "page.projects.index".
 *
 * @var \Facet\Html\ViewContext     $view
 * @var list<\Facet\Content\Project> $projects
 */

declare(strict_types=1);

use Facet\Html\Html;

$title = 'Projects';

ob_start();

?>
<h1 class="text-3xl font-semibold tracking-tight">Projects</h1>
<ul class="mt-8 space-y-6">
    <?php foreach ($projects as $project): ?>
    <li>
        <h2 class="font-medium">
            <a class="hover:underline" href="<?= $view->url('/projects/' . $project->slug()) ?>">
                <?= $view->text($project->name()) ?>
            </a>
        </h2>
        <p class="text-slate-600"><?= $view->text($project->summary()) ?></p>
        <p class="mt-1 text-sm text-slate-500"><?= $view->join($project->technologies()) ?></p>
    </li>
    <?php endforeach; ?>
</ul>
<?php

$content = Html::trusted((string) ob_get_clean());

require dirname(__DIR__, 2) . '/layout.php';
