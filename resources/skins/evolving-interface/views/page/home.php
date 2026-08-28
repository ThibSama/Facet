<?php

/**
 * evolving-interface — logical view "page.home".
 *
 * Renders only values the shared runtime handed it. Every printed value goes
 * through `$view`, so escaping is the default and raw markup would have to be
 * an explicit Html::trusted() call that is not here.
 *
 * The page is a composition of canonical Content objects and nothing else: no
 * fact is spelled out in this file, so a corpus edit is the only way to change
 * what the home page claims. Chrome — section headings, link labels — is the
 * skin's own vocabulary and is deliberately the only prose written here.
 *
 * @var \Facet\Html\ViewContext         $view
 * @var \Facet\Content\Profile          $profile
 * @var list<\Facet\Content\Project>    $projects
 * @var list<\Facet\Content\Skill>      $skills
 * @var list<\Facet\Content\Experience> $experiences
 * @var string                          $appName
 */

declare(strict_types=1);

use Facet\Content\Period;
use Facet\Content\SkillCategory;
use Facet\Html\Html;

$title = null;

$focusAreas = $profile->focusAreas();

/**
 * A canonical period as one readable string.
 *
 * The bounds are printed exactly as the corpus stores them — `YYYY` or
 * `YYYY-MM` — so nothing is invented by formatting. Only the join and the
 * open-ended case are this skin's wording.
 */
$periodLabel = static function (Period $period): string {
    if ($period->isOngoing()) {
        return $period->start() . ' — present';
    }

    $end = (string) $period->end();

    return $period->start() === $end ? $end : $period->start() . ' — ' . $end;
};

/*
 * Skills grouped by their canonical category, in enum-declaration order, each
 * group keeping the corpus order inside it. The categories are the corpus's
 * own vocabulary: this file never invents a grouping, and a category the
 * corpus does not use simply produces no section.
 *
 * @var array<string, list<\Facet\Content\Skill>> $skillsByCategory
 */
$skillsByCategory = [];

foreach ($skills as $skill) {
    $skillsByCategory[$skill->category()->value][] = $skill;
}

ob_start();

?>
<section class="facet-hero" aria-labelledby="hero-title">
    <div class="facet-hero__body max-w-prose">
        <p class="text-sm font-medium uppercase tracking-wide facet-ink-subtle"><?= $view->text($profile->location()) ?></p>

        <h1 id="hero-title" class="mt-2 text-4xl font-semibold tracking-tight"><?= $view->text($profile->name()) ?></h1>

        <p class="mt-3 text-lg facet-ink-muted"><?= $view->text($profile->headline()) ?></p>

        <p class="mt-6 facet-ink-muted"><?= $view->text($profile->summary()) ?></p>

        <?php if ($focusAreas !== []): ?>
        <ul class="mt-6 flex flex-wrap gap-2 text-sm facet-chip-list" aria-label="Focus areas">
            <?php foreach ($focusAreas as $focusArea): ?>
            <li class="rounded border facet-border px-2 py-1 facet-chip"><?= $view->text($focusArea) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <p class="mt-8 flex flex-wrap gap-4">
            <a class="rounded facet-button px-4 py-2" href="<?= $view->url('/projects') ?>">View all projects</a>
            <a class="facet-link underline self-center" href="<?= $view->url('/contact') ?>">Get in touch</a>
        </p>
    </div>

    <?php
    /*
     * The anchor point for the signature visual this skin gets in Phase 4.
     * It is empty and decorative on purpose: the hero must read completely
     * without it, so the slot carries no information and its absence — today,
     * and whenever the portrait has no source — hides nothing.
     */
    ?>
    <div class="facet-hero__visual" aria-hidden="true" data-facet-hero-visual></div>
</section>

<?php if ($projects !== []): ?>
<section class="mt-16" aria-labelledby="featured-projects">
    <h2 id="featured-projects" class="text-2xl font-semibold tracking-tight">Selected work</h2>

    <ul class="mt-6 grid gap-6 sm:grid-cols-2 facet-card-grid">
        <?php foreach ($projects as $project): ?>
        <?php
        $projectPeriod = $project->period();
        $projectStatus = $project->status();
        $technologies = $project->technologies();
        $concepts = $project->concepts();
        // Status and period are optional facts. When the corpus documents
        // neither, the line is not rendered at all rather than left empty.
        $hasMeta = $projectStatus->isSubstantiated() || $projectPeriod !== null;
        ?>
        <li class="rounded border facet-border p-5 facet-card">
            <article>
                <h3 class="text-lg font-medium">
                    <a class="facet-link hover:underline" href="<?= $view->url('/projects/' . $project->slug()) ?>">
                        <?= $view->text($project->name()) ?>
                    </a>
                </h3>

                <?php if ($hasMeta): ?>
                <p class="mt-1 flex flex-wrap gap-x-3 text-sm facet-ink-subtle">
                    <?php if ($projectStatus->isSubstantiated()): ?>
                    <span><?= $view->text($projectStatus->value) ?></span>
                    <?php endif; ?>
                    <?php if ($projectPeriod !== null): ?>
                    <time datetime="<?= $view->attr($projectPeriod->start()) ?>"><?= $view->text($periodLabel($projectPeriod)) ?></time>
                    <?php endif; ?>
                </p>
                <?php endif; ?>

                <p class="mt-3 facet-ink-muted"><?= $view->text($project->summary()) ?></p>

                <?php if ($technologies !== []): ?>
                <p class="mt-3 text-sm facet-ink-subtle">
                    Technologies: <?= $view->join($technologies) ?>
                </p>
                <?php endif; ?>

                <?php if ($concepts !== []): ?>
                <p class="mt-1 text-sm facet-ink-subtle">
                    Concepts: <?= $view->join($concepts) ?>
                </p>
                <?php endif; ?>
            </article>
        </li>
        <?php endforeach; ?>
    </ul>

    <p class="mt-6">
        <a class="facet-link underline" href="<?= $view->url('/projects') ?>">See every project</a>
    </p>
</section>
<?php endif; ?>

<?php if ($skillsByCategory !== []): ?>
<section class="mt-16" aria-labelledby="skills">
    <h2 id="skills" class="text-2xl font-semibold tracking-tight">Skills</h2>

    <div class="mt-6 space-y-6">
        <?php foreach (SkillCategory::cases() as $category): ?>
        <?php $categorySkills = $skillsByCategory[$category->value] ?? []; ?>
        <?php if ($categorySkills !== []): ?>
        <section aria-labelledby="skills-<?= $view->attr($category->value) ?>">
            <h3 id="skills-<?= $view->attr($category->value) ?>" class="text-sm font-medium uppercase tracking-wide facet-ink-subtle">
                <?= $view->text($category->value) ?>
            </h3>
            <ul class="mt-2 flex flex-wrap gap-2 text-sm facet-chip-list">
                <?php foreach ($categorySkills as $skill): ?>
                <li class="rounded border facet-border px-2 py-1 facet-chip"><?= $view->text($skill->name()) ?></li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($experiences !== []): ?>
<section class="mt-16" aria-labelledby="journey">
    <h2 id="journey" class="text-2xl font-semibold tracking-tight">Journey</h2>

    <?php
    /*
     * A plain ordered list in the corpus's own order — the chronology is the
     * sequence of the items, not a layout trick, so it stays readable at any
     * width and with no stylesheet at all. The rail is a left border and
     * nothing else.
     */
    ?>
    <ol class="mt-6 space-y-8 border-l facet-border pl-6 facet-timeline">
        <?php foreach ($experiences as $experience): ?>
        <?php
        $experiencePeriod = $experience->period();
        $highlights = $experience->highlights();
        ?>
        <li>
            <p class="text-sm facet-ink-subtle">
                <time datetime="<?= $view->attr($experiencePeriod->start()) ?>"><?= $view->text($periodLabel($experiencePeriod)) ?></time>
                <span> · <?= $view->text($experience->kind()->value) ?></span>
            </p>

            <h3 class="mt-1 text-lg font-medium"><?= $view->text($experience->title()) ?></h3>

            <p class="facet-ink-muted">
                <?= $view->text($experience->organisation()) ?> — <?= $view->text($experience->location()) ?>
            </p>

            <p class="mt-2 max-w-prose facet-ink-muted"><?= $view->text($experience->summary()) ?></p>

            <?php if ($highlights !== []): ?>
            <ul class="mt-2 max-w-prose list-disc space-y-1 pl-5 text-sm facet-ink-subtle">
                <?php foreach ($highlights as $highlight): ?>
                <li><?= $view->text($highlight) ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ol>
</section>
<?php endif; ?>

<section class="mt-16" aria-labelledby="get-in-touch">
    <h2 id="get-in-touch" class="text-2xl font-semibold tracking-tight">Get in touch</h2>
    <p class="mt-3 max-w-prose facet-ink-muted">The contact page carries a message form and my public profile links.</p>
    <p class="mt-6">
        <a class="rounded facet-button px-4 py-2" href="<?= $view->url('/contact') ?>">Contact me</a>
    </p>
</section>
<?php

$content = Html::trusted((string) ob_get_clean());

require dirname(__DIR__) . '/layout.php';
