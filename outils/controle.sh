#!/bin/bash
# =============================================================
# Contrôle complet du code du portail.
# Une version ne doit être enregistrée ou copiée sur le serveur
# que si ce script se termine par « TOUS LES CONTRÔLES SONT AU VERT ».
#
#   bash outils/controle.sh
#
# Outils installés par : bash outils/installer_outils.sh
# =============================================================
set -uo pipefail
export LC_ALL=C.UTF-8
export SEMGREP_ENABLE_VERSION_CHECK=0
export SEMGREP_SEND_METRICS=off

RACINE="$(cd "$(dirname "$0")/.." && pwd)"
CACHE="$RACINE/outils/.cache"
cd "$RACINE" || exit 1

ECHECS=0
etape() {   # $1 libellé, puis la commande
    local libelle="$1"; shift
    # Alignement en caractères (et non en octets, à cause des accents)
    local largeur=$(( 56 - ${#libelle} ))
    printf '  %s%*s' "$libelle" "$largeur" ''
    local sortie
    if sortie=$("$@" 2>&1); then
        echo "✓"
    else
        echo "✗"
        echo "$sortie" | sed 's/^/      /' | tail -40
        ECHECS=$((ECHECS + 1))
    fi
}

syntaxe_php() {
    local fichier erreurs=0
    while IFS= read -r -d '' fichier; do
        php -l "$fichier" >/dev/null || erreurs=1
    done < <(find app public bin config tests -name '*.php' -print0 2>/dev/null)
    return $erreurs
}

echo "============================================================"
echo "  CONTRÔLE DU PORTAIL - $(date '+%d/%m/%Y %H:%M')"
echo "============================================================"
etape "1. Syntaxe PHP"                                 syntaxe_php
etape "2. Tests automatiques (PHPUnit)"                phpunit --no-coverage
etape "3. Erreurs de logique (PHPStan, niveau max)"    php "$CACHE/phpstan.phar" analyse --no-progress --memory-limit=1G
etape "4. Analyse stricte (Psalm, niveau 1)"           php "$CACHE/psalm.phar" --no-progress --no-cache --show-info=false
etape "5. Données dangereuses (Psalm, taint)"          php "$CACHE/psalm.phar" --taint-analysis --no-progress --no-cache
etape "6. Règles de sécurité : vérification"           semgrep --test --metrics=off outils/semgrep
etape "7. Règles de sécurité : analyse du code"        semgrep scan --metrics=off --quiet --error --config outils/semgrep/regles-portail.yml app public bin config
etape "8. Secrets dans les fichiers (gitleaks)"        "$CACHE/gitleaks" dir --no-banner --redact .
etape "9. Secrets dans l'historique (gitleaks)"        "$CACHE/gitleaks" git --no-banner --redact .

echo "------------------------------------------------------------"
if [ "$ECHECS" -eq 0 ]; then
    echo "  TOUS LES CONTRÔLES SONT AU VERT ✓"
else
    echo "  $ECHECS CONTRÔLE(S) EN ÉCHEC ✗ : ne pas enregistrer cette version"
fi
echo "============================================================"
exit "$ECHECS"
