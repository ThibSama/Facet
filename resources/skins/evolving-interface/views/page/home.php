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
 * ## The composition (PORT-136)
 *
 * The page is four movements over one editorial grid, and the grid is the
 * point: the home route takes its own width off `.facet-shell` and gives each
 * section the width its content deserves instead of one column for all of
 * them. Three of them are measured — hero, work, journey — and two are bands
 * that run to the viewport's own edges, which is what makes the skills strip
 * read as a machine and the finale read as an ending rather than as one more
 * block before the footer.
 *
 * Widths, spans and rhythm are stated here as utilities, because they are
 * ordinary layout and reading the template should tell you what the page does.
 * The stylesheet keeps only what a utility cannot say: the bands' surfaces,
 * the abstract project figures, the ribbon material and the inked finale.
 *
 * Nothing below is a claim. The decorative index numerals are the position of
 * a thing in a list this file composed, they are `aria-hidden`, and they are
 * the only text on the page that is neither corpus nor chrome.
 *
 * @var \Facet\Html\ViewContext         $view
 * @var \Facet\Content\Profile          $profile
 * @var list<\Facet\Content\Project>    $projects
 * @var list<\Facet\Content\Skill>      $skills
 * @var list<\Facet\Content\Experience> $experiences
 * @var string                          $appName
 * @var \Facet\I18n\Translator          $t
 * @var \Facet\I18n\Locale              $locale
 */

declare(strict_types=1);

use Facet\Content\SkillCategory;
use Facet\Html\Html;
use Facet\I18n\LocalizedRoutes;
use Facet\Routing\RouteCatalog;

require dirname(__DIR__) . '/partials/locale-context.php';

$title = null;

$focusAreas = $profile->focusAreas();

/*
 * Both destinations this page offers, in the language it is written in. A
 * reader on the English home page must never be handed a French catalogue by
 * a link the page itself wrote.
 */
$projectsHref = LocalizedRoutes::path(RouteCatalog::PROJECTS_INDEX, $locale);
$contactHref = LocalizedRoutes::path(RouteCatalog::CONTACT, $locale);

// The same period wording the project pages use, so the home page and a case
// study never disagree about how one set of dates reads.
require dirname(__DIR__) . '/partials/period-label.php';

/**
 * A decorative ordinal, zero-padded.
 *
 * It numbers a position in a list this template composed and states nothing
 * about the thing numbered, which is why every one of them is `aria-hidden`:
 * a reader who cannot see the composition is not missing information, they
 * are being spared a number that means nothing outside it.
 */
$ordinal = static fn (int $index): string => str_pad((string) $index, 2, '0', STR_PAD_LEFT);

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
<?php
/*
 * The hero.
 *
 * Layout, spacing, typography and responsive behaviour are Tailwind utilities:
 * this is where they belong, and reading the template tells you what the hero
 * does without opening a stylesheet. The `facet-hero` class is kept as a
 * marker only — the skin uses it to exempt the hero from the section rhythm
 * applied to every other section, and to hang the signature visual's material
 * off it. It carries no layout of its own.
 *
 * The two-column arrangement starts at 56rem, which is a hero measurement
 * rather than a shell breakpoint, so it is written as an arbitrary variant
 * instead of being promoted to a global screen.
 *
 * What changed in PORT-136 is proportion, not architecture. The words now take
 * a full editorial measure instead of a prose column, the visual is given
 * roughly half the composition instead of a seventeen-rem sidebar, and the
 * first screen is allowed to be a screen: `min-h` is a clamped share of the
 * *small* viewport height, so a phone's dynamic toolbar can never turn the
 * hero into something the reader has to scroll past to finish reading.
 */
?>
<section
    class="facet-hero facet-home-shell pt-[clamp(var(--facet-space-6),6vw,var(--facet-space-8))] grid grid-cols-1 gap-[clamp(var(--facet-space-6),5vw,var(--facet-space-8))] min-[56rem]:grid-cols-[minmax(0,1.05fr)_minmax(19rem,0.95fr)] min-[56rem]:items-center min-[56rem]:gap-[clamp(var(--facet-space-6),4vw,var(--facet-space-8))] min-[56rem]:min-h-[min(46rem,calc(100svh-8rem))]"
    aria-labelledby="hero-title"
>
    <div class="facet-hero__body">
        <p class="facet-eyebrow"><?= $view->text($profile->location()) ?></p>

        <h1
            id="hero-title"
            data-facet-hero-title
            class="mt-[clamp(var(--facet-space-4),2vw,var(--facet-space-6))] max-w-[11ch] text-display leading-display tracking-display font-bold text-ink"
        ><?= $view->text($profile->name()) ?></h1>

        <p class="facet-hero__headline mt-[clamp(var(--facet-space-4),2vw,var(--facet-space-5))] max-w-[26ch] text-[clamp(1.25rem,0.95rem+1.15vw,2.05rem)] leading-heading tracking-[-0.02em] font-bold text-ink-muted"><?= $view->text($profile->headline()) ?></p>

        <p class="facet-hero__summary mt-[clamp(var(--facet-space-5),2.5vw,var(--facet-space-6))] max-w-[52ch] text-[clamp(1rem,0.97rem+0.14vw,1.0625rem)] facet-ink-muted"><?= $view->text($profile->summary()) ?></p>

        <?php if ($focusAreas !== []): ?>
        <?php
        /*
         * The focus areas, as a rule rather than as pills.
         *
         * They are the three words the profile leads with, so they are set as
         * an index line under a hairline: separated, evenly weighted, and
         * legible as a set. The chip vocabulary now belongs to the skills
         * band, where a hundred short names actually need it.
         */
        ?>
        <ul class="facet-hero__focus mt-[clamp(var(--facet-space-5),2.5vw,var(--facet-space-6))] flex flex-wrap" aria-label="<?= $view->attr($t->text('home.focusAreas')) ?>">
            <?php foreach ($focusAreas as $focusArea): ?>
            <li><?= $view->text($focusArea) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <p class="mt-[clamp(var(--facet-space-5),3vw,var(--facet-space-7))] flex flex-wrap items-center gap-x-[clamp(var(--facet-space-4),2vw,var(--facet-space-6))] gap-y-4">
            <a class="facet-button facet-button--lead px-6 py-3" href="<?= $view->url($projectsHref) ?>"><?= $view->text($t->text('home.viewAllProjects')) ?></a>
            <a class="facet-link facet-quiet-action" href="<?= $view->url($contactHref) ?>"><?= $view->text($t->text('home.getInTouch')) ?></a>
        </p>
    </div>

    <?php
    /*
     * The signature visual.
     *
     * It is empty, decorative and hidden from assistive technology on purpose:
     * the hero must read completely without it, so the slot carries no
     * information and its absence hides nothing. Below 40rem it is not
     * rendered at all — a narrow viewport spends its height on the words.
     *
     * What the slot *looks* like is the skin's material (layered gradients and
     * two shard pseudo-elements) and stays in CSS. What it *occupies* is
     * layout, and is stated here. Because the slot is fully sized by these
     * utilities before any script runs, the enhancement layer PORT-100 mounts
     * inside it is absolutely positioned and cannot move anything.
     *
     * PORT-136 changed where it sits, not what it is. It is roughly half again
     * as wide as it was, it is justified to the composition's own edge rather
     * than floated beside the text, and from 80rem it is allowed to run out
     * through the gutter — so the material reads as the page's own surface
     * catching light rather than as a rectangle parked next to a paragraph.
     * The stylesheet feathers its outer edge for the same reason.
     */
    ?>
    <div
        class="facet-hero__visual hidden min-[40rem]:block aspect-[16/7] w-full max-w-[46rem] justify-self-start min-[56rem]:aspect-[4/5] min-[56rem]:w-full min-[56rem]:max-w-[34rem] min-[56rem]:justify-self-end min-[80rem]:mr-[calc(-1*var(--facet-home-gutter))]"
        aria-hidden="true"
        data-facet-hero-visual
    ></div>
</section>

<?php if ($projects !== []): ?>
<section class="facet-home-section facet-home-shell" aria-labelledby="featured-projects">
    <div class="facet-section-head">
        <p class="facet-section-head__index" aria-hidden="true"><?= $view->text($ordinal(1)) ?></p>
        <h2 id="featured-projects" class="facet-section-head__title"><?= $view->text($t->text('home.selectedWork')) ?></h2>
        <p class="facet-section-head__aside">
            <a class="facet-link facet-quiet-action" href="<?= $view->url($projectsHref) ?>"><?= $view->text($t->text('home.seeEveryProject')) ?></a>
        </p>
    </div>

    <?php
    /*
     * Selected work, as panels rather than as a row of equal rectangles.
     *
     * The first project takes the whole width and lays its figure beside its
     * words; the ones after it are half-width and stack figure over words. The
     * asymmetry is the composition doing the editing: a reader can tell the
     * three apart — by size, by proportion and by their own abstract figure —
     * before reading a single paragraph.
     *
     * Every figure is geometry drawn by the stylesheet from the project's own
     * slug. It illustrates nothing and depicts nothing: the corpus documents
     * no screenshot for any of these projects, and an invented interface would
     * be evidence that does not exist.
     *
     * The card contract is unchanged and deliberately so — one `<li>` that is
     * the positioning context, one `<article>`, exactly one anchor, and that
     * anchor's own stretched overlay as the hit area. The panel is a bigger
     * card, not a different mechanism.
     */
    $projectIndex = 0;
    ?>
    <ul class="facet-work mt-[clamp(var(--facet-space-6),4vw,var(--facet-space-8))] grid gap-[clamp(var(--facet-space-4),2.5vw,var(--facet-space-6))]" data-facet-card-grid>
        <?php foreach ($projects as $project): ?>
        <?php
        $projectIndex++;
        $projectSlug = $project->slug()->value();
        $projectPeriod = $project->period();
        $projectStatus = $project->status();
        $technologies = $project->technologies();
        $concepts = $project->concepts();
        // Status and period are optional facts. When the corpus documents
        // neither, the line is not rendered at all rather than left empty.
        $hasMeta = $projectStatus->isSubstantiated() || $projectPeriod !== null;
        $hasTags = $technologies !== [] || $concepts !== [];
        ?>
        <li
            class="facet-card facet-work__item relative rounded-card border border-hairline p-card min-[52rem]:p-[clamp(var(--facet-space-5),2.6vw,var(--facet-space-7))]"
            data-facet-work="<?= $view->attr($projectSlug) ?>"
        >
            <article class="facet-work__body">
                <p class="facet-work__index" aria-hidden="true"><?= $view->text($ordinal($projectIndex)) ?></p>

                <?php
                /*
                 * The figure carries no information, so it is hidden from
                 * assistive technology and holds nothing focusable. It is a
                 * `<div>` and not an `<img>` because there is no image: the
                 * shape is three layers of gradient the stylesheet draws from
                 * the `data-facet-work` slug, which costs no request and
                 * cannot be mistaken for a screenshot of a product.
                 */
                ?>
                <div class="facet-work__figure" aria-hidden="true"></div>

                <div class="facet-work__text">
                    <h3 class="facet-work__title">
                        <a class="facet-link facet-card__link" href="<?= $view->url(LocalizedRoutes::path(RouteCatalog::PROJECTS_SHOW, $locale, ['slug' => $projectSlug])) ?>">
                            <?= $view->text($project->name()) ?>
                        </a>
                    </h3>

                    <?php if ($hasMeta): ?>
                    <p class="facet-work__meta">
                        <?php if ($projectStatus->isSubstantiated()): ?>
                        <span><?= $view->text($t->text('content.status.' . $projectStatus->value)) ?></span>
                        <?php endif; ?>
                        <?php if ($projectPeriod !== null): ?>
                        <time datetime="<?= $view->attr($projectPeriod->start()) ?>"><?= $view->text($periodLabel($projectPeriod)) ?></time>
                        <?php endif; ?>
                    </p>
                    <?php endif; ?>

                    <p class="facet-work__summary"><?= $view->text($project->summary()) ?></p>

                    <?php if ($hasTags): ?>
                    <dl class="facet-work__tags">
                        <?php if ($technologies !== []): ?>
                        <dt><?= $view->text($t->text('projects.technologies')) ?></dt>
                        <dd><?= $view->join($technologies, ' · ') ?></dd>
                        <?php endif; ?>
                        <?php if ($concepts !== []): ?>
                        <dt><?= $view->text($t->text('projects.concepts')) ?></dt>
                        <dd><?= $view->join($concepts, ' · ') ?></dd>
                        <?php endif; ?>
                    </dl>
                    <?php endif; ?>
                </div>
            </article>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php if ($skillsByCategory !== []): ?>
<?php
/*
 * The skills band.
 *
 * The one section that leaves the measure entirely. Each ribbon starts at the
 * page's own gutter and runs off the right edge of the viewport, which is what
 * a strip that never ends is supposed to look like — a list that stopped at a
 * container would read as a list that had been cut.
 *
 * What the server sends is unchanged: a plain wrapping list of every skill in
 * the category, exactly once, inside a labelled section. The ribbon is what a
 * runtime may later make of that list — it measures the set, repeats it enough
 * times to fill the viewport, and lets CSS translate the strip at a constant
 * speed. Nothing about that is required to read the page, and a category with
 * only two names is covered by the same repetition as one with eleven.
 *
 * `$ribbonIndex` only alternates the direction of travel, so adjacent ribbons
 * do not read as one sliding block. It is presentation and carries no claim
 * about the skills it moves.
 */
$ribbonIndex = 0;
?>
<section class="facet-home-section facet-band facet-band-skills" aria-labelledby="skills">
    <div class="facet-section-head facet-home-shell">
        <p class="facet-section-head__index" aria-hidden="true"><?= $view->text($ordinal(2)) ?></p>
        <h2 id="skills" class="facet-section-head__title"><?= $view->text($t->text('home.skills')) ?></h2>
    </div>

    <div class="facet-skills">
        <?php foreach (SkillCategory::cases() as $category): ?>
        <?php $categorySkills = $skillsByCategory[$category->value] ?? []; ?>
        <?php if ($categorySkills !== []): ?>
        <?php $ribbonDirection = $ribbonIndex % 2 === 0 ? 'forward' : 'reverse'; ?>
        <section class="facet-skills__row" aria-labelledby="skills-<?= $view->attr($category->value) ?>">
            <h3 id="skills-<?= $view->attr($category->value) ?>" class="facet-skills__label">
                <?= $view->text($t->text('content.skillCategory.' . $category->value)) ?>
            </h3>
            <div
                class="facet-ribbon"
                data-facet-ribbon
                data-facet-ribbon-direction="<?= $view->attr($ribbonDirection) ?>"
            >
                <div class="flex flex-wrap gap-3 facet-ribbon__track" data-facet-ribbon-track>
                    <ul class="flex flex-wrap gap-3" data-facet-ribbon-set>
                        <?php foreach ($categorySkills as $skill): ?>
                        <li class="rounded-full border border-hairline px-4 py-2 facet-chip"><?= $view->text($skill->name()) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </section>
        <?php $ribbonIndex++; ?>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($experiences !== []): ?>
<section class="facet-home-section facet-home-shell" aria-labelledby="journey">
    <div class="facet-section-head">
        <p class="facet-section-head__index" aria-hidden="true"><?= $view->text($ordinal(3)) ?></p>
        <h2 id="journey" class="facet-section-head__title"><?= $view->text($t->text('home.journey')) ?></h2>
    </div>

    <?php
    /*
     * A plain ordered list in the corpus's own order — the chronology is the
     * sequence of the items, not a layout trick, so it stays readable at any
     * width and with no stylesheet at all.
     *
     * PORT-136 took the rail away and made the list a grid of milestones. The
     * change is composition and nothing else: every entry keeps its dates, its
     * kind, its organisation, its location, its summary and every one of its
     * highlights. What it stops doing is running down half the page in a
     * single column, which is what made a portfolio read as a résumé. Two
     * columns from 58rem, and the year is promoted to the thing you see first,
     * so the chronology survives being read as a grid.
     */
    ?>
    <ol class="facet-journey mt-[clamp(var(--facet-space-6),4vw,var(--facet-space-8))] grid gap-[clamp(var(--facet-space-4),2vw,var(--facet-space-5))] min-[58rem]:grid-cols-2">
        <?php foreach ($experiences as $experience): ?>
        <?php
        $experiencePeriod = $experience->period();
        $highlights = $experience->highlights();
        ?>
        <li class="facet-journey__item">
            <p class="facet-journey__period">
                <time datetime="<?= $view->attr($experiencePeriod->start()) ?>"><?= $view->text($periodLabel($experiencePeriod)) ?></time>
            </p>

            <p class="facet-journey__kind"><?= $view->text($t->text('content.experienceKind.' . $experience->kind()->value)) ?></p>

            <h3 class="facet-journey__title"><?= $view->text($experience->title()) ?></h3>

            <p class="facet-journey__where">
                <?= $view->text($experience->organisation()) ?> — <?= $view->text($experience->location()) ?>
            </p>

            <p class="facet-journey__summary"><?= $view->text($experience->summary()) ?></p>

            <?php if ($highlights !== []): ?>
            <ul class="facet-journey__highlights">
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

<?php
/*
 * The finale.
 *
 * An inked plate in both themes — the one place where light and dark agree —
 * so that the page ends on a surface rather than trailing off into a small
 * paragraph above the footer. The plate reassigns the skin's own semantic
 * colour variables to its dark values inside itself, which is why the heading,
 * the sentence, the button and the focus ring all keep the exact contrast
 * pairs `tools/check-design-system.mjs` already gates.
 *
 * It offers the one action the site actually has, with the label it always
 * had. Nothing about availability, timing or terms is stated here, because the
 * corpus states none of it.
 */
?>
<section class="facet-home-section facet-band facet-band-finale" aria-labelledby="get-in-touch">
    <div class="facet-finale facet-home-shell">
        <p class="facet-section-head__index" aria-hidden="true"><?= $view->text($ordinal(4)) ?></p>
        <h2 id="get-in-touch" class="facet-finale__title"><?= $view->text($t->text('home.finale.title')) ?></h2>
        <p class="facet-finale__lede"><?= $view->text($t->text('home.finale.lede')) ?></p>
        <p class="facet-finale__action">
            <a class="facet-button facet-button--lead px-6 py-3" href="<?= $view->url($contactHref) ?>"><?= $view->text($t->text('home.finale.action')) ?></a>
        </p>
    </div>
</section>
<?php

$content = Html::trusted((string) ob_get_clean());

require dirname(__DIR__) . '/layout.php';
