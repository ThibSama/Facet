<?php

/**
 * evolving-interface — logical view "page.home".
 *
 * Renders only values the shared runtime handed it. Every printed value goes
 * through `$view`, so escaping is the default and raw markup would have to be
 * an explicit Html::trusted() call that is not here.
 *
 * @var \Facet\Html\ViewContext  $view
 * @var \Facet\Content\Profile   $profile
 * @var list<\Facet\Content\Project> $projects
 * @var string                   $appName
 * @var string                   $environment
 */

declare(strict_types=1);

use Facet\Html\Html;

$title = null;

ob_start();

?>
<h1 class="text-3xl font-semibold tracking-tight"><?= $view->text($profile->name()) ?></h1>
<p class="mt-2 text-lg text-slate-600"><?= $view->text($profile->headline()) ?></p>
<p class="mt-6 max-w-prose text-slate-700"><?= $view->text($profile->summary()) ?></p>

<?php if ($projects !== []): ?>
<section class="mt-12" aria-labelledby="featured">
    <h2 id="featured" class="text-xl font-semibold">Selected work</h2>
    <ul class="mt-4 space-y-4">
        <?php foreach ($projects as $project): ?>
        <li>
            <a class="font-medium hover:underline" href="<?= $view->url('/projects/' . $project->slug()) ?>">
                <?= $view->text($project->name()) ?>
            </a>
            <p class="text-slate-600"><?= $view->text($project->summary()) ?></p>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<p class="mt-12 text-sm text-slate-500">
    Environment: <code><?= $view->text($environment) ?></code>
</p>
<?php

$content = Html::trusted((string) ob_get_clean());

require dirname(__DIR__) . '/layout.php';
