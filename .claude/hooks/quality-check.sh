#!/usr/bin/env bash
# Hook PostToolUse : après chaque modification de fichier par Claude,
# corrige le style puis lance l'analyse statique sur le fichier modifié.
# Code de sortie 2 = l'erreur est renvoyée à Claude, qui la corrige.

set -uo pipefail

input="$(cat)"
file="$(printf '%s' "$input" | jq -r '.tool_input.file_path // empty')"

[ -z "$file" ] && exit 0
[ -f "$file" ] || exit 0

cd "${CLAUDE_PROJECT_DIR:-.}" || exit 0

# Ne jamais toucher aux dépendances ni aux fichiers générés
case "$file" in
  */vendor/*|*/var/*|*/node_modules/*|*/public/assets/*) exit 0 ;;
esac

case "$file" in
  *.php)
    if [ -x vendor/bin/php-cs-fixer ]; then
      vendor/bin/php-cs-fixer fix --quiet "$file" >/dev/null 2>&1
    fi
    if [ -x vendor/bin/phpstan ]; then
      if ! out="$(vendor/bin/phpstan analyse --no-progress --error-format=raw --memory-limit=1G "$file" 2>&1)"; then
        printf 'PHPStan a trouvé des erreurs dans %s :\n%s\n' "$file" "$out" >&2
        exit 2
      fi
    fi
    ;;
  *.twig)
    if [ -x vendor/bin/twig-cs-fixer ]; then
      if ! out="$(vendor/bin/twig-cs-fixer lint --fix "$file" 2>&1)"; then
        printf 'Twig-CS-Fixer a trouvé des erreurs dans %s :\n%s\n' "$file" "$out" >&2
        exit 2
      fi
    fi
    # Garde-fou : signaler tout |raw ajouté dans un template
    if grep -nE '\|\s*raw' "$file" >/dev/null 2>&1; then
      printf 'Attention : filtre |raw détecté dans %s. Il est interdit sur du contenu utilisateur (voir CLAUDE.md). Justifie-le ou retire-le.\n' "$file" >&2
      grep -nE '\|\s*raw' "$file" >&2
      exit 2
    fi
    ;;
esac

exit 0
