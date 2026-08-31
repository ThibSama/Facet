# PORT-137 — translation review

Every substantive piece of prose the public site says, French beside English,
with where it appears. French is the canonical text: the English is a
translation *of* it, and the question this document exists to answer is whether
any line of it changed what the French line claimed.

Repeated one- and two-word interface labels are summarised in §1 rather than
listed individually. Everything a reader could quote — the biography, the project
descriptions, the journey, the contact copy — is listed in full.

**What to look for.** Not fluency: whether any English line asserts something the
French line did not. Added expertise, added scope, an added metric, a firmer
commitment, a softer disclaimer. Those are the failures worth catching.

---

## 1. Interface chrome

Short, repeated, and not claims about anything. Listed compactly.

| Where | French | English |
| --- | --- | --- |
| Skip link | Aller au contenu | Skip to content |
| Nav landmark (accessible name) | Navigation principale | Primary |
| Nav | Accueil · Projets · À propos · Contact | Home · Projects · About · Contact |
| Language switch (accessible name) | Langue | Language |
| Language links | FR Français · EN English | FR Français · EN English |
| Theme control (clipped label) | Thème sombre | Dark theme |
| Menu control | Menu | Menu |
| Home — hero actions | Voir tous les projets · Me contacter | View all projects · Get in touch |
| Home — sections | Projets sélectionnés · Compétences · Parcours | Selected work · Skills · Journey |
| Home — work aside | Voir tous les projets | See every project |
| Card fields | Technologies · Concepts | Technologies · Concepts |
| Projects index | Projets | Projects |
| Case study sections | Contexte · Rôle · Technologies et concepts · Résultats · Liens | Context · Role · Stack and ideas · Outcomes · Links |
| About sections | Compétences en détail · Formation et expérience · Ailleurs · Poursuivre | Skills in detail · Background · Elsewhere · Continue |
| About actions | Lire les fiches projets · Page de contact | Read the project write-ups · Contact page |
| Contact form | Nom · Adresse e-mail · Objet · Message · Envoyer le message | Name · Email · Subject · Message · Send message |
| Contact — other ways | Autres moyens de me joindre | Other ways to reach me |
| Honeypot label | Laissez ce champ vide | Leave this field empty |
| Error pages | Page introuvable · Page indisponible · Requête incorrecte · Méthode non autorisée · Valeurs invalides · Quelque chose s'est mal passé · Page pas encore disponible | Page not found · Not available · Bad request · Method not allowed · Invalid values · Something went wrong · Not available yet |
| Error — way out | Retour à la page d'accueil | Back to the home page |
| Satoshi Run | Sauter · Se baisser · Recommencer · Fermer · Score · Record | Jump · Duck · Restart · Close · Score · Best |

### Stored vocabularies, now shown as words

These were previously printed as their raw stored values (`in-progress`,
`language`, `education`) on **both** language versions. They now have a display
name in each. The stored values are unchanged.

| Stored value | French | English |
| --- | --- | --- |
| `in-progress` | En cours | In progress |
| `completed` | Terminé | Completed |
| `archived` | Archivé | Archived |
| `language` | Langages | Languages |
| `framework` | Frameworks | Frameworks |
| `database` | Bases de données | Databases |
| `tooling` | Outils | Tooling |
| `certification` | Certifications | Certifications |
| `education` | Formation | Education |
| `professional` | Expérience professionnelle | Professional |
| `volunteer` | Bénévolat | Volunteer |
| open-ended period | aujourd'hui | present |

---

## 2. Profile

**Where:** the home hero, the About page, and the home and About metadata.

| Field | French (canonical) | English |
| --- | --- | --- |
| Name | Thibault Paul | *unchanged — proper noun* |
| Location | France | *unchanged* |
| Headline | Concepteur développeur d'applications en formation | Application developer in training |

**Summary.**

> **FR** — Après un BTS SIO option SLAM, je poursuis mon parcours en Bachelor
> Concepteur Développeur d'Applications à la Foreach Academy. Je m'intéresse
> particulièrement au développement d'applications, à la blockchain et au
> hardware, et j'aime comprendre en profondeur le fonctionnement des systèmes
> avant de concevoir une solution. Autonome et rigoureux, j'accorde une grande
> importance à la qualité du travail et au partage.

> **EN** — After a BTS SIO (SLAM option), I am continuing my studies in a
> Bachelor's programme in Application Design and Development at Foreach Academy.
> I am particularly interested in application development, blockchain and
> hardware, and I like to understand how systems work in depth before designing a
> solution. Independent and rigorous, I care a great deal about the quality of my
> work and about sharing it.

*Note for review:* "Bachelor Concepteur Développeur d'Applications" appears here
as a descriptive phrase inside a sentence rather than as the diploma's name, so
it is glossed. Where it is the **title** of the qualification — in the Journey
and Background lists — it is left in French. See §5.

**Focus areas.**

| French | English |
| --- | --- |
| Développement d'applications | Application development |
| Blockchain | Blockchain |
| Hardware | Hardware |

**Portrait description** (accessible name of the empty media slot):
*Portrait de Thibault Paul.* → *Portrait of Thibault Paul.*

---

## 3. Projects

Names, technologies, dates, status, links and ordering are canonical and
identical in both languages. Only the prose below is translated.

### Kushim

| | |
| --- | --- |
| **Summary FR** | Application de suivi et d'analyse de portefeuille d'investissement, construite comme un ensemble de services Rust autour d'une base PostgreSQL. |
| **Summary EN** | An investment portfolio tracking and analysis application, built as a set of Rust services around a PostgreSQL database. |

> **Context FR** — Kushim centralise des portefeuilles, enregistre des
> opérations, permet de rechercher des actifs et de consulter des synthèses, des
> positions et des instantanés historiques. Le dépôt délimite explicitement ce
> que le projet n'est pas : ni courtier, ni plateforme d'exécution, ni banque, ni
> prestataire de paiement, ni fournisseur de données de marché.

> **Context EN** — Kushim brings portfolios together, records operations, and
> allows assets to be searched and summaries, positions and historical snapshots
> to be consulted. The repository explicitly states what the project is not: not
> a broker, not an execution platform, not a bank, not a payment provider, and
> not a market data provider.

**Role.** *Projet personnel : architecture des services, développement et
rédaction de la documentation technique.* → *Personal project: service
architecture, development and technical documentation.*

**Concepts.**

| French | English |
| --- | --- |
| Découpage en services séparés | Separate services |
| API métier synchrone | Synchronous business API |
| Traitements différés par worker | Deferred processing by worker |
| Instantanés et reconstruction d'historique | Snapshots and history rebuilding |
| Contrats de données documentés | Documented data contracts |
| Tests de fumée automatisés | Automated smoke tests |

**Outcomes.**

| French | English |
| --- | --- |
| Le point d'étape « démonstration MVP locale » est atteint. | The "local MVP demonstration" milestone has been reached. |
| La chaîne backend de bout en bout est démontrable localement via un test de fumée automatisé de 18 assertions. | The end-to-end backend chain can be demonstrated locally through an automated smoke test of 18 assertions. |
| Le dépôt qualifie explicitement le projet d'orienté MVP et non prêt pour la production. | The repository explicitly describes the project as MVP-oriented and not production-ready. |

**Link label.** *Dépôt GitHub* → *GitHub repository*.
**Media description.** *Vue d'ensemble de l'application Kushim.* → *Overview of
the Kushim application.*

### Scora

| | |
| --- | --- |
| **Summary FR** | « Second cerveau » personnel appuyé sur une IA exécutée localement, centré sur la mémoire à long terme et la gestion de connaissances. |
| **Summary EN** | A personal "second brain" built on locally run AI, centred on long-term memory and knowledge management. |

> **Context FR** — Scora explore des usages d'IA privée et locale : conserver une
> mémoire à long terme, organiser une base de connaissances personnelle et
> l'interroger par récupération augmentée, sans confier ces données à un service
> tiers.

> **Context EN** — Scora explores private, local uses of AI: keeping a long-term
> memory, organising a personal knowledge base and querying it through
> retrieval-augmented generation, without handing that data to a third-party
> service.

**Role.** *Projet personnel : conception et développement.* → *Personal project:
design and development.*

**Concepts.** Second cerveau personnel → Personal second brain · Mémoire à long
terme → Long-term memory · Génération augmentée par récupération →
Retrieval-augmented generation · Gestion de connaissances → Knowledge management
· Traitements d'IA locaux et privés → Local, private AI processing

**Media description.** *Illustration du projet Scora.* → *Illustration of the
Scora project.*

### Biogazen

| | |
| --- | --- |
| **Summary FR** | Outil web interne destiné aux salariés d'une unité de méthanisation, qui organise zones, procédures et contenus opératoires pas à pas. |
| **Summary EN** | An internal web tool for the staff of a biogas plant, organising zones, procedures and step-by-step operating content. |

> **Context FR** — L'outil rassemble le contenu d'exploitation d'une unité de
> méthanisation : un découpage par zones, des procédures et des contenus
> décrivant les opérations étape par étape, une recherche pour retrouver
> l'information et une administration à accès contrôlé pour la maintenir.

> **Context EN** — The tool gathers the operating content of a biogas plant: a
> breakdown by zone, procedures and content describing operations step by step, a
> search to find information again, and an access-controlled administration area
> to keep it up to date.

*Note for review:* "unité de méthanisation" is rendered "biogas plant", which is
the ordinary English term for the facility. "Anaerobic digestion plant" is the
more literal alternative if the more technical register is wanted.

**Role.** *Conception et développement de l'outil.* → *Design and development of
the tool.*

**Concepts.** Outil web interne → Internal web tool · Organisation par zones →
Organisation by zone · Procédures pas à pas → Step-by-step procedures · Recherche
dans le contenu → Search within the content · Administration à accès contrôlé →
Access-controlled administration

**Media description.** *Illustration de l'outil Biogazen.* → *Illustration of the
Biogazen tool.*

### Eszter

| | |
| --- | --- |
| **Summary FR** | Site vitrine avec un parcours de réservation intégré, orienté PHP et MySQL pour un hébergement Hetzner. |
| **Summary EN** | A showcase site with an integrated booking journey, built around PHP and MySQL for hosting at Hetzner. |

> **Context FR** — Le projet vise un site vitrine doté d'un parcours de
> réservation adossé à un calendrier, assorti de besoins de notification. La
> direction technique retenue est PHP et MySQL, pour un hébergement chez Hetzner.

> **Context EN** — The project aims at a showcase site with a booking journey
> backed by a calendar, together with notification requirements. The chosen
> technical direction is PHP and MySQL, hosted at Hetzner.

**Role.** *Projet personnel : conception et développement.* → *Personal project:
design and development.*

**Concepts.** Site vitrine → Showcase site · Parcours de réservation → Booking
journey · Calendrier de disponibilités → Availability calendar · Notifications →
Notifications

**Link label.** *Dépôt GitHub* → *GitHub repository*.
**Media description.** *Illustration du site vitrine Eszter.* → *Illustration of
the Eszter showcase site.*

### Math L'home

| | |
| --- | --- |
| **Summary FR** | Futur site vitrine pour Mathilde. Le projet est prévu mais n'est pas encore créé. |
| **Summary EN** | A future showcase site for Mathilde. The project is planned but has not been created yet. |

> **Context FR** — Le projet est identifié comme prévu : aucun périmètre, aucune
> technologie, aucune échéance et aucun contenu ne sont arrêtés à ce jour. Cette
> fiche restera minimale tant qu'aucun élément vérifiable ne vient l'étoffer.

> **Context EN** — The project is recorded as planned: no scope, no technology, no
> deadline and no content have been settled to date. This entry will stay minimal
> for as long as nothing verifiable fills it out.

**Role.** *Projet personnel : conception et développement à venir.* → *Personal
project: design and development to come.*

**Concepts.** Site vitrine → Showcase site.
**Media description.** *Illustration du futur site vitrine Math L'home.* →
*Illustration of the future Math L'home showcase site.*

---

## 4. Skills

Names and categories are canonical. Only each summary is translated.

| Skill | French | English |
| --- | --- | --- |
| PHP | Langage du projet d'atelier professionnel Clinique LPFS et du portfolio Facet. | The language of the Clinique LPFS professional workshop project and of the Facet portfolio. |
| Java | Langage travaillé pendant la formation, notamment sur les projets Morpion Android et Calculatrice. | A language worked with during my studies, notably on the Android Tic-tac-toe and Calculator projects. |
| Python | Langage abordé dans le cadre de la programmation orientée objet pendant la formation. | A language covered as part of object-oriented programming during my studies. |
| JavaScript | Utilisé dans la version précédente du portfolio et dans le projet Clinique LPFS. | Used in the previous version of the portfolio and in the Clinique LPFS project. |
| TypeScript | Utilisé dans les projets EszterGyori, Kushim et Facet. | Used in the EszterGyori, Kushim and Facet projects. |
| Rust | Langage principal des services backend de Kushim. | The main language of Kushim's backend services. |
| HTML | Langage de balisage de la version précédente du portfolio. | The markup language of the previous version of the portfolio. |
| Symfony | Framework PHP abordé pendant la formation. | A PHP framework covered during my studies. |
| Tailwind CSS | Utilisé dans la version précédente du portfolio et dans Facet. | Used in the previous version of the portfolio and in Facet. |
| Next.js | Framework du front d'EszterGyori et du site vitrine de Kushim. | The framework of the EszterGyori front end and of Kushim's showcase site. |
| React | Utilisé dans l'application authentifiée de Kushim et dans le front d'EszterGyori. | Used in Kushim's authenticated application and in the EszterGyori front end. |
| Axum | Framework HTTP Rust des services de Kushim. | The Rust HTTP framework of Kushim's services. |
| MySQL | Système de gestion de base de données abordé pendant la formation. | A database management system covered during my studies. |
| PostgreSQL | Base de données des services de Kushim. | The database of Kushim's services. |
| Redis | Utilisé dans l'infrastructure de Kushim. | Used in Kushim's infrastructure. |
| Composer | Gestionnaire de dépendances PHP utilisé par Facet. | The PHP dependency manager used by Facet. |
| Docker | Utilisé pour l'environnement local de Kushim et d'EszterGyori. | Used for the local environment of Kushim and EszterGyori. |
| Git | Gestion de versions de l'ensemble des projets. | Version control across every project. |
| GitHub | Hébergement des dépôts et intégration continue. | Repository hosting and continuous integration. |
| Node.js | Exécution des outils de build et de l'API de contenu d'EszterGyori. | Runs the build tooling and the EszterGyori content API. |
| npm | Gestionnaire de paquets des chaînes de build front. | The package manager of the front-end build chains. |
| Webpack | Compilation des actifs de la version précédente du portfolio. | Asset compilation for the previous version of the portfolio. |
| Vite | Chaîne de build des actifs de Facet. | Facet's asset build chain. |
| PHPUnit | Tests automatisés de Facet. | Facet's automated tests. |
| PHPStan | Analyse statique de Facet, configurée au niveau 8. | Facet's static analysis, configured at level 8. |
| SqlDBM | Outil de modélisation de bases de données utilisé pendant la formation. | A database modelling tool used during my studies. |
| Certification Pix | Certification Pix obtenue sur l'année scolaire 2021-2022. | Pix certification obtained during the 2021-2022 school year. |
| Atelier RGPD de la CNIL | Attestation de suivi de l'atelier RGPD proposé par la CNIL. | Certificate of completion for the GDPR workshop offered by the CNIL. |

*Notes for review:* "pendant la formation" is rendered "during my studies"
throughout, which reads naturally and keeps the same referent. "Morpion" is
rendered "Tic-tac-toe", the game's English name. "RGPD" is rendered "GDPR", the
regulation's English initialism, while the workshop's own name — *Atelier RGPD de
la CNIL* — is kept as the official title.

---

## 5. Journey / Background

**Titles, institutions, locations and dates are not translated.** These are
official French qualifications and the establishments that award them; rendering
them under an invented English name would state a credential that does not
exist. Only each summary and its highlights are translated. **This is the
decision most worth a second opinion.**

### Bachelor Concepteur Développeur d'Applications — Foreach Academy, France, 2025 —

> **FR** — Parcours en développement web et applications, dans une école
> spécialisée dans les métiers du développement avec un fort accent mis sur la
> pratique et les technologies web modernes.

> **EN** — A programme in web and application development, at a school specialised
> in development professions with a strong emphasis on practice and modern web
> technologies.

| French highlight | English |
| --- | --- |
| Cursus centré sur la réalisation de projets concrets et le travail en équipe. | A curriculum centred on building concrete projects and on working as a team. |
| Approfondissement de l'architecture d'applications, des bonnes pratiques de code et des workflows modernes : Git, intégration continue, déploiement. | Deeper work on application architecture, coding good practice and modern workflows: Git, continuous integration, deployment. |

### BTS SIO option SLAM — LTPES Ensemble Saint-Luc, Cambrai, 2022 – 2024

> **FR** — Services Informatiques aux Organisations, option Solutions Logicielles
> et Applications Métiers : conception et implémentation de solutions logicielles
> répondant aux besoins des organisations.

> **EN** — Information Services for Organisations, SLAM option (software solutions
> and business applications): designing and implementing software solutions that
> answer the needs of organisations.

| French highlight | English |
| --- | --- |
| Maîtrise des étapes du cycle de vie d'un projet informatique, de l'analyse des besoins à la maintenance évolutive. | Command of the stages of an IT project's life cycle, from needs analysis to evolutionary maintenance. |
| Projet d'atelier professionnel Clinique LPFS réalisé en équipe. | Clinique LPFS professional workshop project, carried out as a team. |

*Note for review:* the summary expands the acronyms as a gloss, keeping "SLAM"
as the option's name. The alternative is to leave the French expansion intact.

### DCG — Diplôme de Comptabilité et de Gestion — Lycée Edouard Gand, Amiens, 2021 – 2022

> **FR** — Formation en comptabilité et gestion : principes comptables, gestion
> financière, droit des affaires et économie.

> **EN** — Training in accounting and management: accounting principles, financial
> management, business law and economics.

| French highlight | English |
| --- | --- |
| Analyse d'états financiers et élaboration de budgets. | Analysis of financial statements and preparation of budgets. |
| Certification Pix obtenue sur l'année scolaire 2021-2022. | Pix certification obtained during the 2021-2022 school year. |

### Baccalauréat STMG option Gestion et Finance — Lycée général Pierre de la Ramée, Saint-Quentin, 2018 – 2021

> **FR** — Formation initiale en gestion d'entreprise et en finances : gestion
> financière, comptabilité, droit des affaires et économie.

> **EN** — Initial training in business management and finance: financial
> management, accounting, business law and economics.

| French highlight | English |
| --- | --- |
| Bases en gestion d'entreprise, finance et comptabilité. | Foundations in business management, finance and accounting. |

---

## 6. Contact

**Standing explanation**, above the form.

> **FR** — Ce que vous écrivez ici est enregistré sur ce site, où je le lis. Rien
> n'est transmis ailleurs automatiquement : pour une demande urgente, les liens
> ci-dessous sont plus rapides.

> **EN** — What you write here is stored on this site, where I read it. Nothing is
> forwarded anywhere automatically, so for anything urgent the links below are
> quicker.

*The claim is identical in both: receipt and storage, and no promise to reply.*

**Field help.**

| Field | French | English |
| --- | --- | --- |
| Nom / Name | Le nom sous lequel vous souhaitez qu'on vous réponde. | How you would like to be addressed. |
| Adresse e-mail / Email | L'adresse à laquelle une réponse serait écrite. | The address a reply would be written to. |
| Objet / Subject | Une ligne indiquant le sujet du message. | One line saying what the message is about. |
| Message | Le message lui-même. Texte brut — aucune mise en forme n'est interprétée. | The message itself. Plain text — no formatting is interpreted. |

**Form-level outcomes.**

| French | English |
| --- | --- |
| Merci — votre message a été reçu et enregistré sur ce site. | Thank you — your message has been received and stored on this site. |
| Votre message n'a pas été envoyé. Merci de corriger les champs signalés ci-dessous. | Your message was not sent. Please correct the fields marked below. |
| Cela fait plusieurs messages en peu de temps. Merci de réessayer dans environ {minutes} minutes. | That is several messages in a short time. Please try again in about {minutes} minutes. |
| Votre message n'a pas pu être enregistré : il n'a donc pas été reçu. Merci de réessayer dans un instant, ou d'utiliser l'un des liens ci-dessous. | Your message could not be stored, so it has not been received. Please try again shortly, or use one of the links below. |

**Per-field refusals.**

| French | English |
| --- | --- |
| Merci d'indiquer un nom auquel adresser une réponse. | Please give a name I can address a reply to. |
| Un nom ne peut pas dépasser {max} caractères. | A name can be at most {max} characters. |
| Merci d'indiquer une adresse e-mail pour qu'une réponse puisse vous parvenir. | Please give an email address so a reply can reach you. |
| Une adresse e-mail ne peut pas dépasser {max} caractères. | An email address can be at most {max} characters. |
| Cela ne ressemble pas à une adresse e-mail. | That does not look like an email address. |
| Merci d'indiquer en une ligne l'objet de votre message. | Please say in one line what this is about. |
| Un objet ne peut pas dépasser {max} caractères. | A subject can be at most {max} characters. |
| Merci d'écrire un message. | Please write a message. |
| Un message ne peut pas dépasser {max} caractères. | A message can be at most {max} characters. |

---

## 7. Home finale and SEO sentences

| Where | French | English |
| --- | --- | --- |
| Home finale, lede | La page de contact réunit un formulaire de message et mes liens publics. | The contact page carries a message form and my public profile links. |
| Home finale, action | Écrivez-moi | Contact me |
| Projects — title | Projets — Thibault Paul | Projects — Thibault Paul |
| Projects — description | Les projets de Thibault Paul, présentés à partir de leurs informations vérifiées. | The projects of Thibault Paul, presented from their verified information. |
| Case study — title | {projet} — Projet de Thibault Paul | {project} — Project by Thibault Paul |
| About — title | À propos de Thibault Paul | About Thibault Paul |
| About — description | {headline} en France. {summary} | {headline} in France. {summary} |
| Contact — title | Contacter Thibault Paul | Contact Thibault Paul |
| Contact — description | Formulaire de contact de Thibault Paul et liens publics issus de son profil. | Contact form for Thibault Paul, and the public links from their profile. |

The home page's own title and description are the profile's name, headline and
summary, so they are translated by §2 and are not restated here.

---

## 8. Satoshi Run

| French | English |
| --- | --- |
| Espace pour sauter · ▼ pour se baisser · ramassez les ₿ | Space to jump · ▼ to duck · collect ₿ |
| Attrapé. Appuyez sur Recommencer, ou R, pour repartir. | Caught. Press Restart, or R, to run again. |
| Nouveau record. | New best score. |

The name **Satoshi Run** is the same in both languages.
