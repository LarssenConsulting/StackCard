<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** Command-line reference: install, update, run, check and remove the agent; maintain the central. */
class Commands extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCommandLine;

    protected static ?string $navigationLabel = 'Commandes';

    protected static ?string $title = 'Commandes utiles';

    protected static ?string $slug = 'commandes';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.commands';

    /**
     * Groups of commands, each `['title', 'description'?, 'commands' => [[label, command], …]]`.
     * No secret here: tokens stay `<jeton>` placeholders (a real one is shown once, on the server page).
     *
     * @return list<array{title: string, description?: string, commands: list<array{0: string, 1: string}>}>
     */
    public function groups(): array
    {
        $url = rtrim((string) config('app.url'), '/');
        $host = preg_replace('#^https?://#', '', $url);
        $script = (string) config('discover.agent_install_url');

        $groups = [
            [
                'title' => 'Agent : installer et mettre à jour',
                'description' => 'Sur le serveur à inventorier. Le jeton se génère sur la fiche du serveur (valable 24 h, à usage unique).',
                'commands' => [
                    ['Installer, ou mettre à jour un agent déjà installé (répondre « non » à « Mettre à jour le jeton ? » pour garder la configuration)', "curl -fsSL {$script} | sudo sh"],
                    ['Installer et enrôler sans questions (Ansible, plusieurs serveurs)', "curl -fsSL {$script} | sudo DISCOVER_URL={$host} DISCOVER_TOKEN=<jeton> sh"],
                    ['Enrôler ou réenrôler un agent déjà installé', "sudo discover-agent enroll --url {$url} --token <jeton>"],
                    ['Installer les outils optionnels (intégrité, services à redémarrer, nftables)', 'sudo apt install debsums needrestart nftables'],
                ],
            ],
            [
                'title' => 'Agent : lancer et relancer',
                'commands' => [
                    ['Envoyer un inventaire tout de suite', 'sudo discover-agent run'],
                    ['Lancer un passage identique à celui du timer (même bac à sable systemd)', 'sudo systemctl start discover-agent'],
                    ['Relancer le timer horaire', 'sudo systemctl restart discover-agent.timer'],
                    ['Suspendre les envois', 'sudo systemctl disable --now discover-agent.timer'],
                    ['Reprendre les envois', 'sudo systemctl enable --now discover-agent.timer'],
                ],
            ],
            [
                'title' => 'Agent : vérifier',
                'commands' => [
                    ['Version, dernier envoi, dernière erreur', 'sudo discover-agent status'],
                    ['Voir ce qui serait envoyé, sans l’envoyer', 'sudo discover-agent collect | less'],
                    ['Idem avec les sections hebdomadaires (lent, jusqu’à 5 min)', 'sudo discover-agent collect --full | less'],
                    ['Prochain passage du timer', 'systemctl list-timers discover-agent.timer'],
                    ['Journal des derniers passages', 'journalctl -u discover-agent.service -n 100 --no-pager'],
                ],
            ],
            [
                'title' => 'Agent : désinstaller',
                'commands' => [
                    ['Désinstaller en gardant la configuration (/etc/discover-agent)', 'sudo apt remove discover-agent'],
                    ['Désinstaller et supprimer configuration et état', 'sudo apt purge discover-agent'],
                ],
            ],
        ];

        if (auth()->user()?->isAdmin() === true) {
            $groups[] = [
                'title' => 'Central : maintenance',
                'description' => 'Dans le dossier central/ du serveur qui héberge le central, en tant que propriétaire des fichiers (jamais en root).',
                'commands' => [
                    ['Mettre à jour le central (pull, dépendances, migrations, caches)', './deploy.sh'],
                    ['Relire le .env après une modification', 'php artisan config:clear && php artisan optimize'],
                    ['Créer un compte (admin si c’est le premier)', 'php artisan make:filament-user'],
                    ['Purger les anciens inventaires (planifié chaque jour à 03:00)', 'php artisan discover:prune-snapshots'],
                    ['Relire la dernière version publiée de l’agent', 'php artisan cache:forget discover.agent_latest_version'],
                ],
            ];
        }

        return $groups;
    }
}
