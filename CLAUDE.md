# CLAUDE.md — Projet « Letterboxd multi-médias »

Ce fichier est lu par Claude Code au début de chaque session. Garde-le à jour : c'est la source de vérité sur les conventions du projet.

## Le projet

Application web de suivi culturel : les utilisateurs tiennent un journal (vu / lu / joué), notent, critiquent, créent des listes, suivent d'autres membres et interagissent (commentaires, likes).

Types d'œuvres : films, séries (animés japonais compris), livres, BD, mangas, jeux vidéo.

## Stack

- PHP 8.4+ (8.5 en local), Symfony 8.1 (`symfony/webapp-pack`), Doctrine ORM — vérifier `composer.json` avant de supposer une version
- Front : Twig + Symfony UX (Turbo, Stimulus, Live Components), AssetMapper (pas de Node), Tailwind via `symfonycasts/tailwind-bundle`
- Base de données : PostgreSQL hébergé sur Supabase (via Doctrine uniquement, **pas** de SDK Supabase côté PHP)
- Fichiers : Supabase Storage via son endpoint compatible S3 + Flysystem
- Asynchrone : Symfony Messenger (imports d'APIs externes, notifications, génération du fil d'actu)
- Hébergement : Upsun

## Commandes

```bash
symfony serve -d                         # serveur local
symfony console doctrine:migrations:diff # générer une migration
symfony console doctrine:migrations:migrate
symfony console messenger:consume async -vv
vendor/bin/phpunit                       # tests
vendor/bin/phpstan analyse               # analyse statique (niveau max)
vendor/bin/php-cs-fixer fix              # style PHP
vendor/bin/twig-cs-fixer lint --fix templates/
vendor/bin/rector process --dry-run
composer audit                           # vulnérabilités des dépendances
```

Avant de déclarer une tâche terminée : tests, PHPStan et linters doivent passer.

Configuration des outils : `phpstan.dist.neon` (niveau max, extensions Symfony/Doctrine/PHPUnit ; le conteneur est lu depuis `var/cache/dev`, lancer `symfony console cache:clear` si PHPStan ne voit pas un service), `rector.php`, `.php-cs-fixer.dist.php` (`@Symfony`). Un hook (`.claude/hooks/quality-check.sh`) lance PHP-CS-Fixer, PHPStan et Twig-CS-Fixer après chaque modification.

Les conseils génériques Symfony du squelette sont dans @AGENTS.md ; en cas de conflit, ce fichier-ci prime (la persistance, l'interface et l'authentification sont déjà tranchées ci-dessus).

## Architecture

```
src/
  Catalog/        # Work (entité commune) + Film, Series, Book, Comic, Manga, VideoGame
                  # ExternalId (source, externalId) : TMDB, AniList, IGDB, OpenLibrary, GoogleBooks
  Import/         # Un client par source (HttpClient + cache + RateLimiter), messages Messenger
  Tracking/       # LogEntry (vu/lu/joué, date, note, relecture/revisionnage), Review
  Lists/          # UserList, UserListItem (ordonné)
  Social/         # Follow, Like, Comment, Report (signalement), ActivityFeed
  User/           # User, Profile, avatar, préférences, export/suppression RGPD
  Security/       # Voters, authenticators
  Moderation/     # Traitement des signalements, sanctions
templates/        # Un dossier par domaine, composants Twig dans templates/components/
```

- Chaque domaine contient ses entités, repositories, contrôleurs et services (pas de `src/Entity` ni `src/Controller` globaux). Doctrine mappe tout `src/` (préfixe `App`) et les routes sont découvertes par attribut partout dans `src/`. Les factories Foundry vont dans `src/Factory/`, les stories dans `src/Story/`.
- Contrôleurs fins : la logique va dans des services. Pas de logique métier dans Twig.
- Une entité = un domaine. Les relations entre domaines passent par l'identifiant ou par `Work`.
- Les appels aux APIs externes ne se font **jamais** pendant une requête HTTP utilisateur : toujours via Messenger, avec mise en cache des résultats en base.
- Respecter les conditions des APIs (attribution TMDB obligatoire, quotas IGDB/AniList).

## Règles de sécurité (non négociables)

- Toute action sur une ressource (modifier une critique, supprimer un commentaire, voir une liste privée…) passe par un **Voter** (`#[IsGranted('EDIT', subject: 'review')]`). Jamais de simple comparaison d'ID dans un contrôleur.
- CSRF sur tous les formulaires et sur toutes les actions POST/DELETE déclenchées en Stimulus/Turbo.
- **Jamais** de filtre `|raw` dans Twig sur du contenu utilisateur. Les critiques en Markdown sont converties avec `league/commonmark` (HTML désactivé) puis passées dans `symfony/html-sanitizer`.
- Uploads : vérification du type MIME réel, taille maximale, renommage aléatoire, redimensionnement serveur. Jamais d'URL de stockage construite à partir d'une saisie utilisateur.
- RateLimiter sur : connexion, inscription, réinitialisation du mot de passe, commentaires, likes, signalements, recherche.
- Mots de passe : `PasswordHasher` de Symfony (auto). Vérification d'e-mail obligatoire (`verify-email-bundle`), réinitialisation via `reset-password-bundle`.
- En-têtes de sécurité et CSP via NelmioSecurityBundle.
- Aucun secret dans le code ou dans Git : `.env.local` en local, `symfony console secrets:set` ou variables Upsun en production.
- Supabase : RLS activée sur **toutes** les tables (sans règle) pour neutraliser l'API PostgREST publique. Ne jamais utiliser ni exposer la clé `service_role` côté front.
- Requêtes : uniquement via Doctrine (QueryBuilder/DQL avec paramètres). Pas de SQL concaténé.

## Base de données

- Toute modification de schéma = une migration Doctrine relue. Pas de `schema:update --force`.
- RLS : chaque migration qui crée une table ajoute `ALTER TABLE … ENABLE ROW LEVEL SECURITY;`. Ne pas oublier les tables créées hors entités : `doctrine_migration_versions` et `messenger_messages` (transport Doctrine, `auto_setup=0` → créée par une migration, pas par `messenger:setup-transports`).
- `DATABASE_URL` réelle uniquement dans `.env.local` ; `.env` ne contient que des valeurs factices.
- Index sur toutes les clés étrangères et colonnes filtrées ou triées (fil d'actu, recherche, notes).
- Pas de requêtes N+1 : jointures explicites (`addSelect`) ou requêtes dédiées. Pagination obligatoire sur toute liste qui peut grandir (fil d'actu, critiques, commentaires).
- Connexion Supabase : connexion directe ou « session pooler » (port 5432), pas le pooler en mode transaction.
- Recherche : commencer avec `pg_trgm` / la recherche plein texte de PostgreSQL.

## UX et interface

- Mobile first. Toutes les pages sont utilisables au clavier et respectent WCAG 2.2 AA (contrastes, labels, focus visible, `alt` sur les affiches).
- Chaque liste a un état vide utile (« Aucune critique pour l'instant — soyez le premier »).
- Actions rapides sans rechargement (noter, liker, ajouter à une liste) via Turbo Frames / Live Components, avec un comportement de repli sans JavaScript quand c'est possible.
- Spoilers : une critique peut être marquée « contient des spoilers » et reste masquée jusqu'au clic.
- Vocabulaire adapté au type d'œuvre : « vu » (film/série), « lu » (livre/BD/manga), « joué » (jeu).
- Messages d'erreur de formulaire clairs et en français, à côté du champ concerné.
- Interface en français, textes dans les fichiers de traduction (`translations/`), jamais en dur.

## Tests

- Chaque fonctionnalité livre ses tests : unitaires pour les services, fonctionnels (WebTestCase) pour les contrôleurs, y compris les cas « accès refusé ».
- Données de test avec Zenstruck Foundry ; isolation avec DAMA DoctrineTestBundle.
- Base de test : PostgreSQL 17 local (Homebrew), base `app_test`, URL dans `.env.test`. Jamais Supabase (`tests/TestDatabaseTest.php` le vérifie). Après une nouvelle migration : `symfony console doctrine:migrations:migrate --env=test -n`.
- Les clients d'APIs externes sont testés avec `MockHttpClient`, jamais contre les vraies APIs.
- Parcours critiques (inscription, noter une œuvre, écrire une critique) couverts en end-to-end avec Playwright.

## Manière de travailler

1. Pour toute fonctionnalité non triviale : passer en mode Plan, proposer le plan, attendre validation.
2. Développer par tranches verticales : entité + migration → service → contrôleur → Twig → tests.
3. Après chaque fonctionnalité, lancer les agents `symfony-security-reviewer` et `doctrine-reviewer` ; pour toute modification d'interface, `ux-a11y-reviewer`.
4. Commits petits et conventionnels (`feat:`, `fix:`, `refactor:`…), un sujet par commit.
5. En cas de doute sur une API Symfony, vérifier la documentation de la version installée plutôt que de supposer.
