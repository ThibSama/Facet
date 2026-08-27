<?php

/**
 * evolving-interface — logical view "page.about".
 *
 * @var \Facet\Html\ViewContext         $view
 * @var \Facet\Content\Profile          $profile
 * @var list<\Facet\Content\Skill>      $skills
 * @var list<\Facet\Content\Experience> $experiences
 */

declare(strict_types=1);

use Facet\Html\Html;

$title = 'About';

ob_start();

?>
<h1 class="text-3xl font-semibold tracking-tight">About</h1>
<p class="mt-4 max-w-prose text-slate-700"><?= $view->text($profile->summary()) ?></p>
<p class="mt-2 text-sm text-slate-500"><?= $view->text($profile->location()) ?></p>

<section class="mt-12" aria-labelledby="skills">
    <h2 id="skills" class="text-xl font-semibold">Skills</h2>
    <ul class="mt-4 flex flex-wrap gap-2 text-sm">
        <?php foreach ($skills as $skill): ?>
        <li class="rounded border border-slate-200 px-2 py-1"><?= $view->text($skill->name()) ?></li>
        <?php endforeach; ?>
    </ul>
</section>

<section class="mt-12" aria-labelledby="experience">
    <h2 id="experience" class="text-xl font-semibold">Experience</h2>
    <ul class="mt-4 space-y-4">
        <?php foreach ($experiences as $experience): ?>
        <li>
            <p class="font-medium"><?= $view->text($experience->title()) ?></p>
            <p class="text-slate-600"><?= $view->text($experience->summary()) ?></p>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php

$content = Html::trusted((string) ob_get_clean());

require dirname(__DIR__) . '/layout.php';
