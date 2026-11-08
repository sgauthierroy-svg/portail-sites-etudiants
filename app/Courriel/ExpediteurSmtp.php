<?php

declare(strict_types=1);

namespace Portail\Courriel;

use InvalidArgumentException;
use RuntimeException;

/**
 * Envoi par SMTP, connexion chiffrée dès l'ouverture (port 465), certificat du
 * serveur vérifié, authentification par la boîte portail@ (RFC 5321, 4954, 2047).
 *
 * Écrit pour le portail, sans bibliothèque extérieure. Le message est en texte
 * brut UTF-8, encodé en base64 : aucune ligne ne peut commencer par un point,
 * et aucun contenu ne peut injecter d'en-tête.
 */
final class ExpediteurSmtp implements Expediteur
{
    private const DELAI = 10;

    /** @var resource|closed-resource|null */
    private $flux = null;

    /**
     * @param bool $chiffre false seulement pour les tests (faux serveur local)
     */
    public function __construct(
        private readonly string $hote,
        private readonly int $port,
        private readonly string $utilisateur,
        #[\SensitiveParameter] private readonly string $motDePasse,
        private readonly string $nomAffiche = 'Portail Sup\'Sallanches',
        private readonly bool $chiffre = true,
    ) {
    }

    public function envoyer(array $destinataires, string $sujet, string $texte): void
    {
        $message = self::message($this->utilisateur, $this->nomAffiche, $destinataires, $sujet, $texte, time());
        $this->ouvrir();
        try {
            $this->attendre(220);
            $this->commande('EHLO portail.bts-sup-sallanches.com', 250);
            $this->commande('AUTH LOGIN', 334);
            $this->commande(base64_encode($this->utilisateur), 334);
            $this->commande(base64_encode($this->motDePasse), 235, 'authentification refusée (mot de passe de la boîte ?)');
            $this->commande('MAIL FROM:<' . $this->utilisateur . '>', 250);
            foreach ($destinataires as $destinataire) {
                $this->commande('RCPT TO:<' . $destinataire . '>', 250, null, [251]);
            }
            $this->commande('DATA', 354);
            $this->ecrire($message . "\r\n.");
            $this->attendre(250);
            $this->ecrire('QUIT');
        } finally {
            $this->fermer();
        }
    }

    /**
     * Message complet (en-têtes + corps), prêt pour la commande DATA.
     *
     * @param list<string> $destinataires
     */
    public static function message(string $expediteur, string $nomAffiche, array $destinataires, string $sujet, string $texte, int $instant): string
    {
        if ($destinataires === []) {
            throw new InvalidArgumentException('Aucun destinataire.');
        }
        foreach (array_merge([$expediteur], $destinataires) as $adresse) {
            if (filter_var($adresse, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n<>]/', $adresse) === 1) {
                throw new InvalidArgumentException('Adresse e-mail invalide.');
            }
        }
        if (preg_match('/[\r\n]/', $sujet . $nomAffiche) === 1) {
            throw new InvalidArgumentException('Sujet ou nom invalide.');
        }
        $encoder = static fn (string $texte): string => '=?UTF-8?B?' . base64_encode($texte) . '?=';
        $domaine = substr($expediteur, (int) strrpos($expediteur, '@') + 1);
        $entetes = [
            'From: ' . $encoder($nomAffiche) . ' <' . $expediteur . '>',
            'To: ' . implode(', ', $destinataires),
            'Subject: ' . $encoder($sujet),
            'Date: ' . gmdate('D, d M Y H:i:s', $instant) . ' +0000',
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domaine . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'Auto-Submitted: auto-generated',
        ];
        $corps = rtrim(chunk_split(base64_encode(str_replace(["\r\n", "\r"], "\n", $texte)), 76, "\r\n"), "\r\n");

        return implode("\r\n", $entetes) . "\r\n\r\n" . $corps;
    }

    private function ouvrir(): void
    {
        $contexte = stream_context_create(['ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $this->hote, 'SNI_enabled' => true,
        ]]);
        $flux = @stream_socket_client(($this->chiffre ? 'ssl://' : 'tcp://') . $this->hote . ':' . $this->port, $code, $erreur,
            self::DELAI, STREAM_CLIENT_CONNECT, $contexte);
        if ($flux === false) {
            throw new RuntimeException('Connexion au serveur d\'envoi impossible : ' . $erreur);
        }
        stream_set_timeout($flux, self::DELAI);
        $this->flux = $flux;
    }

    private function fermer(): void
    {
        if (is_resource($this->flux)) {
            fclose($this->flux);
        }
        $this->flux = null;
    }

    /** @param list<int> $autres Autres codes acceptés */
    private function commande(string $ligne, int $attendu, ?string $explication = null, array $autres = []): void
    {
        $this->ecrire($ligne);
        $this->attendre($attendu, $explication, $autres);
    }

    private function ecrire(string $ligne): void
    {
        if (!is_resource($this->flux) || fwrite($this->flux, $ligne . "\r\n") === false) {
            throw new RuntimeException('Écriture vers le serveur d\'envoi impossible.');
        }
    }

    /** @param list<int> $autres */
    private function attendre(int $attendu, ?string $explication = null, array $autres = []): void
    {
        $reponse = '';
        do {
            $ligne = is_resource($this->flux) ? fgets($this->flux, 1024) : false;
            if ($ligne === false) {
                throw new RuntimeException('Le serveur d\'envoi ne répond plus.');
            }
            $reponse = $ligne;
        } while (strlen($ligne) > 3 && $ligne[3] === '-');
        $code = (int) substr($reponse, 0, 3);
        if ($code !== $attendu && !in_array($code, $autres, true)) {
            throw new RuntimeException('Réponse inattendue du serveur d\'envoi (' . $code . ')' . ($explication === null ? '' : ' : ' . $explication) . '.');
        }
    }
}
