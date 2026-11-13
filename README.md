# Letterboxd multi-médias

Application Symfony 8.1 de suivi culturel (films, séries, livres, BD, mangas, jeux vidéo). Les conventions du projet sont dans [CLAUDE.md](CLAUDE.md).

## Prérequis

- PHP 8.4+ et Composer (`brew install php composer`)
- Symfony CLI (`brew install symfony-cli/tap/symfony-cli`)
- `jq` (utilisé par le hook Claude Code)
- Une base PostgreSQL : projet Supabase **de développement** (ou PostgreSQL local)

## Installation

```bash
composer install
```

Crée `.env.local` (jamais commité) avec l'URL de la base de dev Supabase, en connexion directe ou « session pooler » (port 5432) :

```dotenv
DATABASE_URL="postgresql://postgres.<ref>:<mot-de-passe>@aws-0-<région>.pooler.supabase.com:5432/postgres?serverVersion=17&charset=utf8&sslmode=require"
```

```bash
symfony console doctrine:migrations:migrate
symfony serve -d
```

### Base de test (PostgreSQL local)

Les tests tournent sur un PostgreSQL local, jamais sur Supabase (URL dans `.env.test`, base `app_test`) :

```bash
brew install postgresql@17
brew services start postgresql@17
/opt/homebrew/opt/postgresql@17/bin/psql -h 127.0.0.1 -d postgres -c "CREATE ROLE app WITH LOGIN PASSWORD 'app' CREATEDB;"
symfony console doctrine:database:create --env=test
```

Après chaque nouvelle migration : `symfony console doctrine:migrations:migrate --env=test -n`.

## Qualité

```bash
vendor/bin/phpunit
vendor/bin/phpstan analyse
vendor/bin/php-cs-fixer fix
vendor/bin/twig-cs-fixer lint --fix templates/
vendor/bin/rector process --dry-run
composer audit
```

## Claude Code

```
CLAUDE.md                        # conventions du projet, lues à chaque session
AGENTS.md                        # conseils Symfony génériques du squelette (CLAUDE.md prime)
.mcp.json                        # serveur MCP Supabase (projet de dev, lecture seule)
.claude/settings.json            # permissions + hook qualité
.claude/hooks/quality-check.sh   # PHP-CS-Fixer, PHPStan, Twig-CS-Fixer après chaque modification
.claude/agents/                  # symfony-security-reviewer, doctrine-reviewer, ux-a11y-reviewer, test-writer
```

Étapes à faire une fois par poste :

1. Dans `.mcp.json`, remplacer `REMPLACE_PAR_TON_PROJECT_REF_DE_DEV` par l'identifiant du projet Supabase **de développement**, puis dans `claude` : `/mcp` → `supabase` → « Authenticate ».
2. Installer les plugins (dans `claude` en terminal) :
   ```
   /plugin install security-guidance@claude-plugins-official
   /plugin install code-review@claude-plugins-official
   /plugin install pr-review-toolkit@claude-plugins-official
   /plugin install php-lsp@claude-plugins-official
   /plugin install frontend-design@claude-plugins-official
   /plugin install playwright@claude-plugins-official
   /plugin install commit-commands@claude-plugins-official
   ```
   `php-lsp` nécessite Intelephense : `npm install -g intelephense`.

### Au quotidien

- Nouvelle fonctionnalité : passer en mode Plan, décrire le besoin, valider le plan.
- Après l'implémentation : « Lance les agents symfony-security-reviewer et doctrine-reviewer sur ce qui vient d'être fait. »
- Après une modification d'interface : « Lance ux-a11y-reviewer. »
- Si des tests manquent : « Utilise test-writer pour la fonctionnalité X. »
- Avant une pull request : `/code-review`.

### À savoir

- Le hook bloque tout `|raw` dans Twig. Si un cas légitime apparaît (contenu déjà nettoyé), Claude doit le justifier.
- Faire évoluer `CLAUDE.md` à chaque décision d'architecture.
