<?php

/**
 * evolving-interface — logical view "page.about".
 *
 * The reference page for the person behind the site, and deliberately not a
 * second home page. Home already carries the identity paragraph and the
 * chronological journey, so neither is repeated here: what this page adds is
 * the canonical depth home leaves out — every skill with the summary the
 * corpus writes for it, the profile's own outbound links, and the background
 * grouped by its canonical kind rather than replayed as narrative.
 *
 * Like every view in this skin it states no fact of its own. Section headings,
 * link labels and the joining words are the skin's vocabulary; everything a
 * reader could quote comes from a Content object, so the only way to change
 * what this page claims is to edit the corpus.
 *
 * @var \Facet\Html\ViewContext         $view
 * @var \Facet\Content\Profile          $profile
 * @var list<\Facet\Content\Skill>      $skills
 * @var list<\Facet\Content\Experience> $experiences
 */

declare(strict_types=1);

use Facet\Content\ExperienceKind;
use Facet\Content\SkillCategory;
use Facet\Html\Html;

$title = 'About';

require dirname(__DIR__) . '/partials/period-label.php';

$profileLinks = $profile->links();

/*
 * Skills and background grouped by their canonical vocabularies, in
 * enum-declaration order, each group keeping corpus order inside it. A
 * category or kind the corpus does not use simply produces no group: the
 * grouping is read from the data, never asserted by this file.
 *
 * @var array<string, list<\Facet\Content\Skill>>      $skillsByCategory
 * @var array<string, list<\Facet\Content\Experience>> $experiencesByKind
 */
$skillsByCategory = [];

foreach ($skills as $skill) {
    $skillsByCategory[$skill->category()->value][] = $skill;
}

$experiencesByKind = [];

foreach ($experiences as $experience) {
    $experiencesByKind[$experience->kind()->value][] = $experience;
}

/*
 * The profile's portrait, rendered through the skin's one media slot. The
 * corpus documents no source for it today, and the slot is built for exactly
 * that: it never emits an <img> for a reference no asset answers, so there is
 * no broken image, and it carries the portrait's mandatory description into
 * the accessibility tree. Nothing on this page is stated only by the picture,
 * so its absence hides no information.
 */
$media = $profile->portrait();
$mediaRatio = '1 / 1';

ob_start();

?>
<h1 class="text-3xl font-semibold tracking-tight">About <?= $view->text($profile->name()) ?></h1>

<p class="mt-3 max-w-prose text-lg facet-ink-muted"><?= $view->text($profile->headline()) ?></p>

<p class="mt-2 text-sm facet-ink-subtle"><?= $view->text($profile->location()) ?></p>

<div class="mt-8 max-w-xs">
    <?php require dirname(__DIR__) . '/partials/media.php'; ?>
</div>

<?php if ($skillsByCategory !== []): ?>
<section class="mt-16" aria-labelledby="skill-detail">
    <h2 id="skill-detail" class="text-2xl font-semibold tracking-tight">Skills in detail</h2>

    <?php
    /*
     * Home lists the same skills as bare names. The reason this page exists is
     * the second half of each record — the corpus's own sentence saying where
     * the skill was actually used — so a description list is the honest shape:
     * the name is the term, the canonical summary is its definition.
     */
    ?>
    <div class="mt-6 space-y-8">
        <?php foreach (SkillCategory::cases() as $category): ?>
        <?php $categorySkills = $skillsByCategory[$category->value] ?? []; ?>
        <?php if ($categorySkills !== []): ?>
        <section aria-labelledby="skill-detail-<?= $view->attr($category->value) ?>">
            <h3 id="skill-detail-<?= $view->attr($category->value) ?>" class="text-sm font-medium uppercase tracking-wide facet-ink-subtle">
                <?= $view->text($category->value) ?>
            </h3>

            <dl class="mt-3 space-y-3">
                <?php foreach ($categorySkills as $skill): ?>
                <?php $skillLinks = $skill->links(); ?>
                <div>
                    <dt class="font-medium"><?= $view->text($skill->name()) ?></dt>
                    <dd class="max-w-prose facet-ink-muted"><?= $view->text($skill->summary()) ?></dd>
                    <?php if ($skillLinks !== []): ?>
                    <dd class="mt-1 text-sm">
                        <?php foreach ($skillLinks as $skillLink): ?>
                        <a
                            class="facet-link underline"
                            href="<?= $view->url($skillLink->url()) ?>"
                            rel="noopener noreferrer"
                            data-link-type="<?= $view->attr($skillLink->type()->value) ?>"
                        ><?= $view->text($skillLink->label()) ?></a>
                        <?php endforeach; ?>
                    </dd>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </dl>
        </section>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($experiencesByKind !== []): ?>
<section class="mt-16" aria-labelledby="background">
    <h2 id="background" class="text-2xl font-semibold tracking-tight">Background</h2>

    <?php
    /*
     * The record, not the story. Home already tells the journey in one
     * chronology with each entry's summary and highlights; repeating that
     * prose here would be the same page twice. This is the other cut of the
     * same canonical entries — grouped by the kind the corpus assigns, so a
     * study programme can never read as employment — and it carries only the
     * facts a reader scans for: what, where, when.
     */
    ?>
    <div class="mt-6 space-y-8">
        <?php foreach (ExperienceKind::cases() as $kind): ?>
        <?php $kindExperiences = $experiencesByKind[$kind->value] ?? []; ?>
        <?php if ($kindExperiences !== []): ?>
        <section aria-labelledby="background-<?= $view->attr($kind->value) ?>">
            <h3 id="background-<?= $view->attr($kind->value) ?>" class="text-sm font-medium uppercase tracking-wide facet-ink-subtle">
                <?= $view->text($kind->value) ?>
            </h3>

            <ul class="mt-3 space-y-3">
                <?php foreach ($kindExperiences as $experience): ?>
                <?php $experiencePeriod = $experience->period(); ?>
                <li>
                    <p class="font-medium"><?= $view->text($experience->title()) ?></p>
                    <p class="facet-ink-muted">
                        <?= $view->text($experience->organisation()) ?> — <?= $view->text($experience->location()) ?>
                    </p>
                    <p class="text-sm facet-ink-subtle">
                        <time datetime="<?= $view->attr($experiencePeriod->start()) ?>"><?= $view->text($periodLabel($experiencePeriod)) ?></time>
                    </p>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($profileLinks !== []): ?>
<section class="mt-16" aria-labelledby="elsewhere">
    <h2 id="elsewhere" class="text-2xl font-semibold tracking-tight">Elsewhere</h2>

    <?php
    /*
     * Canonical label, canonical URL, link type as data. The corpus is the
     * only source of an outbound address anywhere on this site, so a profile
     * it does not document simply does not appear.
     */
    ?>
    <ul class="mt-4 space-y-1">
        <?php foreach ($profileLinks as $profileLink): ?>
        <li>
            <a
                class="facet-link underline"
                href="<?= $view->url($profileLink->url()) ?>"
                rel="noopener noreferrer"
                data-link-type="<?= $view->attr($profileLink->type()->value) ?>"
            ><?= $view->text($profileLink->label()) ?></a>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<section class="mt-16" aria-labelledby="continue">
    <h2 id="continue" class="text-2xl font-semibold tracking-tight">Continue</h2>
    <p class="mt-6 flex flex-wrap gap-4">
        <a class="rounded facet-button px-4 py-2" href="<?= $view->url('/projects') ?>">Read the project write-ups</a>
        <a class="facet-link underline self-center" href="<?= $view->url('/contact') ?>">Contact page</a>
    </p>
</section>
<?php

$content = Html::trusted((string) ob_get_clean());

require dirname(__DIR__) . '/layout.php';
