<?php

declare(strict_types=1);

namespace Facet\I18n;

/**
 * Every interface string the public site says, in both languages at once.
 *
 * The shape is the point. A translation is one entry holding *both* locales
 * rather than two parallel catalogs, so "the French catalog has a key the
 * English one does not" is not a mistake this file can express: adding a string
 * means writing both halves in the same array literal, and forgetting one is a
 * PHP array missing a key that {@see TranslationCompletenessTest} names.
 *
 * Two rules keep it honest:
 *
 * - **Chrome only.** Nothing here is a fact about the person, the projects or
 *   the career. Every quotable claim on the public site comes from the canonical
 *   corpus in `content/`, whose English text lives in `content/translations/`
 *   beside it. A sentence in this file describes the interface, never the work.
 * - **No fallback.** A key absent from this file is a programming error and
 *   raises {@see MissingTranslationException}. It is never rendered, never
 *   silently replaced by the other language, and never printed as itself: a
 *   visitor cannot be shown `home.work.title`, and a French page cannot quietly
 *   acquire an English heading because one entry was forgotten.
 *
 * Placeholders are `{name}` and are substituted by {@see Translator::text()}.
 * They are never optional: a call that omits one leaves the brace visible in
 * the output, which the completeness test also refuses.
 *
 * @phpstan-type Entry array{fr: string, en: string}
 */
final class Translations
{
    /**
     * @var array<string, array{fr: string, en: string}>|null
     */
    private static ?array $catalog = null;

    /**
     * @return array<string, array{fr: string, en: string}>
     */
    public static function all(): array
    {
        return self::$catalog ??= self::build();
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    /**
     * @return array<string, array{fr: string, en: string}>
     */
    private static function build(): array
    {
        return [
            // ------------------------------------------------------- shell
            'shell.skipToContent' => [
                'fr' => 'Aller au contenu',
                'en' => 'Skip to content',
            ],
            'shell.menu' => [
                'fr' => 'Menu',
                'en' => 'Menu',
            ],
            'shell.themeToggle' => [
                'fr' => 'Thème sombre',
                'en' => 'Dark theme',
            ],

            // -------------------------------------------------- navigation
            'nav.label' => [
                'fr' => 'Navigation principale',
                'en' => 'Primary',
            ],
            'nav.home' => [
                'fr' => 'Accueil',
                'en' => 'Home',
            ],
            'nav.projects' => [
                'fr' => 'Projets',
                'en' => 'Projects',
            ],
            'nav.about' => [
                'fr' => 'À propos',
                'en' => 'About',
            ],
            'nav.contact' => [
                'fr' => 'Contact',
                'en' => 'Contact',
            ],

            // --------------------------------------------------- language
            'language.label' => [
                'fr' => 'Langue',
                'en' => 'Language',
            ],
            // The accessible name of one switch link. The language is named in
            // its own language on purpose — see Locale::endonym().
            'language.switchTo' => [
                'fr' => 'Lire ce site en {language}',
                'en' => 'Read this site in {language}',
            ],

            // ------------------------------------------------------- home
            'home.focusAreas' => [
                'fr' => "Domaines d'intérêt",
                'en' => 'Focus areas',
            ],
            'home.viewAllProjects' => [
                'fr' => 'Voir tous les projets',
                'en' => 'View all projects',
            ],
            'home.getInTouch' => [
                'fr' => 'Me contacter',
                'en' => 'Get in touch',
            ],
            'home.selectedWork' => [
                'fr' => 'Projets sélectionnés',
                'en' => 'Selected work',
            ],
            'home.seeEveryProject' => [
                'fr' => 'Voir tous les projets',
                'en' => 'See every project',
            ],
            'home.skills' => [
                'fr' => 'Compétences',
                'en' => 'Skills',
            ],
            'home.journey' => [
                'fr' => 'Parcours',
                'en' => 'Journey',
            ],
            'home.finale.title' => [
                'fr' => 'Me contacter',
                'en' => 'Get in touch',
            ],
            'home.finale.lede' => [
                'fr' => 'La page de contact réunit un formulaire de message et mes liens publics.',
                'en' => 'The contact page carries a message form and my public profile links.',
            ],
            'home.finale.action' => [
                'fr' => 'Écrivez-moi',
                'en' => 'Contact me',
            ],

            // ---------------------------------------------------- projects
            'projects.title' => [
                'fr' => 'Projets',
                'en' => 'Projects',
            ],
            'projects.technologies' => [
                'fr' => 'Technologies',
                'en' => 'Technologies',
            ],
            'projects.concepts' => [
                'fr' => 'Concepts',
                'en' => 'Concepts',
            ],
            'projects.technologiesInline' => [
                'fr' => 'Technologies :',
                'en' => 'Technologies:',
            ],
            'projects.conceptsInline' => [
                'fr' => 'Concepts :',
                'en' => 'Concepts:',
            ],
            'project.context' => [
                'fr' => 'Contexte',
                'en' => 'Context',
            ],
            'project.role' => [
                'fr' => 'Rôle',
                'en' => 'Role',
            ],
            'project.stack' => [
                'fr' => 'Technologies et concepts',
                'en' => 'Stack and ideas',
            ],
            'project.outcomes' => [
                'fr' => 'Résultats',
                'en' => 'Outcomes',
            ],
            'project.links' => [
                'fr' => 'Liens',
                'en' => 'Links',
            ],

            // ------------------------------------------------------- about
            'about.pageTitle' => [
                'fr' => 'À propos',
                'en' => 'About',
            ],
            'about.heading' => [
                'fr' => 'À propos de {name}',
                'en' => 'About {name}',
            ],
            'about.skillsInDetail' => [
                'fr' => 'Compétences en détail',
                'en' => 'Skills in detail',
            ],
            'about.background' => [
                'fr' => 'Formation et expérience',
                'en' => 'Background',
            ],
            'about.elsewhere' => [
                'fr' => 'Ailleurs',
                'en' => 'Elsewhere',
            ],
            'about.continue' => [
                'fr' => 'Poursuivre',
                'en' => 'Continue',
            ],
            'about.readProjects' => [
                'fr' => 'Lire les fiches projets',
                'en' => 'Read the project write-ups',
            ],
            'about.contactPage' => [
                'fr' => 'Page de contact',
                'en' => 'Contact page',
            ],

            // ----------------------------------------------------- contact
            'contact.title' => [
                'fr' => 'Contact',
                'en' => 'Contact',
            ],
            'contact.standing' => [
                'fr' => "Ce que vous écrivez ici est enregistré sur ce site, où je le lis. Rien n'est transmis "
                    . 'ailleurs automatiquement : pour une demande urgente, les liens ci-dessous sont plus rapides.',
                'en' => 'What you write here is stored on this site, where I read it. Nothing is forwarded anywhere '
                    . 'automatically, so for anything urgent the links below are quicker.',
            ],
            'contact.field.name.label' => [
                'fr' => 'Nom',
                'en' => 'Name',
            ],
            'contact.field.name.help' => [
                'fr' => 'Le nom sous lequel vous souhaitez qu’on vous réponde.',
                'en' => 'How you would like to be addressed.',
            ],
            'contact.field.email.label' => [
                'fr' => 'Adresse e-mail',
                'en' => 'Email',
            ],
            'contact.field.email.help' => [
                'fr' => 'L’adresse à laquelle une réponse serait écrite.',
                'en' => 'The address a reply would be written to.',
            ],
            'contact.field.subject.label' => [
                'fr' => 'Objet',
                'en' => 'Subject',
            ],
            'contact.field.subject.help' => [
                'fr' => 'Une ligne indiquant le sujet du message.',
                'en' => 'One line saying what the message is about.',
            ],
            'contact.field.message.label' => [
                'fr' => 'Message',
                'en' => 'Message',
            ],
            'contact.field.message.help' => [
                'fr' => 'Le message lui-même. Texte brut — aucune mise en forme n’est interprétée.',
                'en' => 'The message itself. Plain text — no formatting is interpreted.',
            ],
            'contact.honeypot.label' => [
                'fr' => 'Laissez ce champ vide',
                'en' => 'Leave this field empty',
            ],
            'contact.submit' => [
                'fr' => 'Envoyer le message',
                'en' => 'Send message',
            ],
            'contact.otherWays' => [
                'fr' => 'Autres moyens de me joindre',
                'en' => 'Other ways to reach me',
            ],
            'contact.notice.sent' => [
                'fr' => 'Merci — votre message a été reçu et enregistré sur ce site.',
                'en' => 'Thank you — your message has been received and stored on this site.',
            ],
            'contact.notice.invalid' => [
                'fr' => "Votre message n'a pas été envoyé. Merci de corriger les champs signalés ci-dessous.",
                'en' => 'Your message was not sent. Please correct the fields marked below.',
            ],
            'contact.notice.throttled' => [
                'fr' => 'Cela fait plusieurs messages en peu de temps. Merci de réessayer dans environ {minutes} minutes.',
                'en' => 'That is several messages in a short time. Please try again in about {minutes} minutes.',
            ],
            'contact.notice.storeFailed' => [
                'fr' => "Votre message n'a pas pu être enregistré : il n'a donc pas été reçu. "
                    . 'Merci de réessayer dans un instant, ou d’utiliser l’un des liens ci-dessous.',
                'en' => 'Your message could not be stored, so it has not been received. '
                    . 'Please try again shortly, or use one of the links below.',
            ],

            // The per-field refusals. One entry per reason the validator can
            // return; the validator itself returns the reason, never the prose.
            'contact.error.name.missing' => [
                'fr' => 'Merci d’indiquer un nom auquel adresser une réponse.',
                'en' => 'Please give a name I can address a reply to.',
            ],
            'contact.error.name.tooLong' => [
                'fr' => 'Un nom ne peut pas dépasser {max} caractères.',
                'en' => 'A name can be at most {max} characters.',
            ],
            'contact.error.email.missing' => [
                'fr' => 'Merci d’indiquer une adresse e-mail pour qu’une réponse puisse vous parvenir.',
                'en' => 'Please give an email address so a reply can reach you.',
            ],
            'contact.error.email.tooLong' => [
                'fr' => 'Une adresse e-mail ne peut pas dépasser {max} caractères.',
                'en' => 'An email address can be at most {max} characters.',
            ],
            'contact.error.email.malformed' => [
                'fr' => 'Cela ne ressemble pas à une adresse e-mail.',
                'en' => 'That does not look like an email address.',
            ],
            'contact.error.subject.missing' => [
                'fr' => 'Merci d’indiquer en une ligne l’objet de votre message.',
                'en' => 'Please say in one line what this is about.',
            ],
            'contact.error.subject.tooLong' => [
                'fr' => 'Un objet ne peut pas dépasser {max} caractères.',
                'en' => 'A subject can be at most {max} characters.',
            ],
            'contact.error.message.missing' => [
                'fr' => 'Merci d’écrire un message.',
                'en' => 'Please write a message.',
            ],
            'contact.error.message.tooLong' => [
                'fr' => 'Un message ne peut pas dépasser {max} caractères.',
                'en' => 'A message can be at most {max} characters.',
            ],

            // ------------------------------------------------------ errors
            'error.400.title' => ['fr' => 'Requête incorrecte', 'en' => 'Bad request'],
            'error.400.message' => [
                'fr' => "La requête n'a pas pu être comprise.",
                'en' => 'The request could not be understood.',
            ],
            'error.403.title' => ['fr' => 'Page indisponible', 'en' => 'Not available'],
            'error.403.message' => [
                'fr' => "Cette page n'est pas disponible.",
                'en' => 'This page is not available.',
            ],
            'error.404.title' => ['fr' => 'Page introuvable', 'en' => 'Page not found'],
            'error.404.message' => [
                'fr' => "Cette page n'existe pas.",
                'en' => 'This page does not exist.',
            ],
            'error.405.title' => ['fr' => 'Méthode non autorisée', 'en' => 'Method not allowed'],
            'error.405.message' => [
                'fr' => "Cette page n'accepte pas ce type de requête.",
                'en' => 'This page does not accept that kind of request.',
            ],
            'error.422.title' => ['fr' => 'Valeurs invalides', 'en' => 'Invalid values'],
            'error.422.message' => [
                'fr' => 'Une ou plusieurs valeurs envoyées sont invalides.',
                'en' => 'One or more submitted values are invalid.',
            ],
            'error.500.title' => ['fr' => "Quelque chose s'est mal passé", 'en' => 'Something went wrong'],
            'error.500.message' => [
                'fr' => "La page n'a pas pu être affichée. Merci de réessayer plus tard.",
                'en' => 'The page could not be displayed. Please try again later.',
            ],
            'error.501.title' => ['fr' => 'Page pas encore disponible', 'en' => 'Not available yet'],
            'error.501.message' => [
                'fr' => "Cette page n'est pas encore disponible.",
                'en' => 'This page is not available yet.',
            ],
            'error.backHome' => [
                'fr' => "Retour à la page d'accueil",
                'en' => 'Back to the home page',
            ],
            'error.diagnostics.title' => [
                'fr' => 'Diagnostics',
                'en' => 'Diagnostics',
            ],
            'error.diagnostics.note' => [
                'fr' => "Affiché parce que l'application tourne en mode debug.",
                'en' => 'Shown because the application is running in debug mode.',
            ],

            // --------------------------------- canonical vocabularies, read
            // The corpus stores these as machine values — `in-progress`,
            // `language`, `education` — and the shell used to print the value
            // itself. That was English on a French page and an implementation
            // token on both, so the display name for each case is chrome and
            // belongs here. The stored value is untouched.
            'content.status.in-progress' => ['fr' => 'En cours', 'en' => 'In progress'],
            'content.status.completed' => ['fr' => 'Terminé', 'en' => 'Completed'],
            'content.status.archived' => ['fr' => 'Archivé', 'en' => 'Archived'],

            'content.skillCategory.language' => ['fr' => 'Langages', 'en' => 'Languages'],
            'content.skillCategory.framework' => ['fr' => 'Frameworks', 'en' => 'Frameworks'],
            'content.skillCategory.database' => ['fr' => 'Bases de données', 'en' => 'Databases'],
            'content.skillCategory.tooling' => ['fr' => 'Outils', 'en' => 'Tooling'],
            'content.skillCategory.certification' => ['fr' => 'Certifications', 'en' => 'Certifications'],

            'content.experienceKind.education' => ['fr' => 'Formation', 'en' => 'Education'],
            'content.experienceKind.professional' => ['fr' => 'Expérience professionnelle', 'en' => 'Professional'],
            'content.experienceKind.volunteer' => ['fr' => 'Bénévolat', 'en' => 'Volunteer'],

            'content.period.present' => ['fr' => "aujourd'hui", 'en' => 'present'],

            // --------------------------------------------------------- SEO
            'seo.projects.title' => [
                'fr' => 'Projets — {name}',
                'en' => 'Projects — {name}',
            ],
            'seo.projects.description' => [
                'fr' => 'Les projets de {name}, présentés à partir de leurs informations vérifiées.',
                'en' => 'The projects of {name}, presented from their verified information.',
            ],
            'seo.project.title' => [
                'fr' => '{project} — Projet de {name}',
                'en' => '{project} — Project by {name}',
            ],
            'seo.project.fallbackName' => [
                'fr' => 'Projet',
                'en' => 'Project',
            ],
            'seo.about.title' => [
                'fr' => 'À propos de {name}',
                'en' => 'About {name}',
            ],
            'seo.about.description' => [
                'fr' => '{headline} en {location}. {summary}',
                'en' => '{headline} in {location}. {summary}',
            ],
            'seo.contact.title' => [
                'fr' => 'Contacter {name}',
                'en' => 'Contact {name}',
            ],
            'seo.contact.description' => [
                'fr' => 'Formulaire de contact de {name} et liens publics issus de son profil.',
                'en' => 'Contact form for {name}, and the public links from their profile.',
            ],

            // ------------------------------------------------- Satoshi Run
            // The run's own chrome. "Satoshi Run" is a proper name and is not
            // in this list: it is the same string in both languages.
            'run.jump' => ['fr' => 'Sauter', 'en' => 'Jump'],
            'run.duck' => ['fr' => 'Se baisser', 'en' => 'Duck'],
            'run.restart' => ['fr' => 'Recommencer', 'en' => 'Restart'],
            'run.close' => ['fr' => 'Fermer', 'en' => 'Close'],
            'run.score' => ['fr' => 'Score', 'en' => 'Score'],
            'run.best' => ['fr' => 'Record', 'en' => 'Best'],
            'run.ready' => [
                'fr' => 'Espace pour sauter · ▼ pour se baisser · ramassez les ₿',
                'en' => 'Space to jump · ▼ to duck · collect ₿',
            ],
            'run.over' => [
                'fr' => 'Attrapé. Appuyez sur Recommencer, ou R, pour repartir.',
                'en' => 'Caught. Press Restart, or R, to run again.',
            ],
            'run.record' => [
                'fr' => 'Nouveau record.',
                'en' => 'New best score.',
            ],
        ];
    }
}
