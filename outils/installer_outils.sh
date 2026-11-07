#!/bin/bash
# =============================================================
# Installe les outils de contrôle du portail, en versions FIXÉES,
# dans outils/.cache (dossier ignoré par Git).
# Chaque fichier téléchargé est vérifié par son empreinte SHA-256 :
# un fichier modifié en route est refusé.
#
# À lancer sur un poste de développement (jamais sur le serveur
# de production). Prérequis : php (8.1 ou plus), curl, python3.
# =============================================================
set -euo pipefail

RACINE="$(cd "$(dirname "$0")/.." && pwd)"
CACHE="$RACINE/outils/.cache"
mkdir -p "$CACHE"

telecharger() {   # $1 adresse  $2 fichier  $3 empreinte SHA-256 attendue
    if [ -f "$CACHE/$2" ] && echo "$3  $CACHE/$2" | sha256sum -c --status; then
        echo "  ✓ $2 (déjà présent)"; return 0
    fi
    curl -fsSL -o "$CACHE/$2.tmp" "$1"
    if echo "$3  $CACHE/$2.tmp" | sha256sum -c --status; then
        mv "$CACHE/$2.tmp" "$CACHE/$2"; echo "  ✓ $2"
    else
        rm -f "$CACHE/$2.tmp"; echo "  ✗ $2 : EMPREINTE INCORRECTE, fichier refusé" >&2; exit 1
    fi
}

echo "Outils de contrôle du portail"
telecharger "https://github.com/phpstan/phpstan/releases/download/2.3.5/phpstan.phar" \
    phpstan.phar 4b3ed7850a20b092331bd1040e4f54a1d3c0559b4cf006431073997ebcb4324b
telecharger "https://github.com/vimeo/psalm/releases/download/5.26.1/psalm.phar" \
    psalm.phar 7d68a927dd72d30ec90e574cc114dd44851d9c49e3855e3c33c2bdd021259d1a
telecharger "https://github.com/gitleaks/gitleaks/releases/download/v8.28.0/gitleaks_8.28.0_linux_x64.tar.gz" \
    gitleaks.tar.gz a65b5253807a68ac0cafa4414031fd740aeb55f54fb7e55f386acb52e6a840eb
tar xzf "$CACHE/gitleaks.tar.gz" -C "$CACHE" gitleaks

# Règles Semgrep publiques pour PHP (dossier php/lang, 37 règles), version
# figée au commit 16b9e6d du dépôt semgrep/semgrep-rules.
# Licence « Semgrep Rules License v1.0 » : usage interne seulement, pas de
# redistribution. Elles ne sont donc PAS dans ce dépôt : chaque poste les
# télécharge. L'empreinte porte sur les fichiers extraits (l'archive générée
# par GitHub peut varier d'octets sans que son contenu change).
COMMIT_REGLES=16b9e6d45f10649f6a531ec4838ed9924372c3bc
EMPREINTE_REGLES=16a470ef39a06cea892120619f50e27cf0dad51806a57bc15b4a5a902d28613e
empreinte_regles() {
    (cd "$CACHE/semgrep-rules" && find php/lang -type f | LC_ALL=C sort | xargs sha256sum | sha256sum | cut -d' ' -f1)
}
if [ -d "$CACHE/semgrep-rules/php/lang" ] && [ "$(empreinte_regles)" = "$EMPREINTE_REGLES" ]; then
    echo "  ✓ règles Semgrep publiques PHP (déjà présentes)"
else
    rm -rf "$CACHE/semgrep-rules" && mkdir -p "$CACHE/semgrep-rules"
    if ! curl -fsSL "https://github.com/semgrep/semgrep-rules/archive/$COMMIT_REGLES.tar.gz" \
        | tar xz -C "$CACHE/semgrep-rules" --strip-components=1 \
            "semgrep-rules-$COMMIT_REGLES/php/lang" "semgrep-rules-$COMMIT_REGLES/LICENSE"; then
        rm -rf "$CACHE/semgrep-rules"; echo "  ✗ règles Semgrep : téléchargement impossible" >&2; exit 1
    fi
    if [ "$(empreinte_regles)" = "$EMPREINTE_REGLES" ]; then
        echo "  ✓ règles Semgrep publiques PHP"
    else
        rm -rf "$CACHE/semgrep-rules"; echo "  ✗ règles Semgrep : EMPREINTE INCORRECTE, refusées" >&2; exit 1
    fi
fi

# PHPUnit 9.6 (compatible PHP 8.1) : paquet système (apt install phpunit)
# ou archive officielle https://phar.phpunit.de/phpunit-9.6.phar
command -v phpunit >/dev/null && echo "  ✓ phpunit ($(phpunit --version | head -1))" \
    || echo "  ⚠ phpunit absent : apt install phpunit" >&2

# Semgrep (moteur d'analyse ; les règles sont dans outils/semgrep)
command -v semgrep >/dev/null || python3 -m pip install --user --quiet "semgrep==1.184.0"
echo "  ✓ semgrep ($(semgrep --version))"

echo ""
echo "Activer le contrôle automatique avant chaque enregistrement :"
echo "  git config core.hooksPath outils/hooks"
