<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * Public wiki (/wiki): how StackCard and Semaphore/Ansible work. No authentication on purpose, so the
 * content stays generic (example names and documentation addresses only; a test guards it).
 */
class WikiController extends Controller
{
    /**
     * Pages in reading order: slug => title, short label for the menu, summary and table of contents
     * (anchor => heading). The home page has the slug '' and lives at /wiki.
     *
     * @var array<string, array{title: string, label: string, summary: string, toc: array<string, string>}>
     */
    public const PAGES = [
        '' => [
            'title' => 'Vue d’ensemble',
            'label' => 'Vue d’ensemble',
            'summary' => 'Ce qu’est StackCard, ses briques et le principe « push-only ».',
            'toc' => [
                'quoi' => 'StackCard en bref',
                'briques' => 'Les briques',
                'architecture' => 'Architecture',
                'push-only' => 'Le principe push-only',
                'protections' => 'Protections',
                'lire-la-suite' => 'Par où continuer',
            ],
        ],
        'agent' => [
            'title' => 'L’agent',
            'label' => 'Agent',
            'summary' => 'Installer, enrôler et régler l’agent ; ce qu’il lit et envoie chaque heure.',
            'toc' => [
                'role' => 'Rôle',
                'installation' => 'Installation et enrôlement',
                'cycle' => 'Un passage horaire',
                'sections' => 'Les sections',
                'securite' => 'Sécurité',
                'reglages' => 'Réglages (agent.conf)',
                'commandes' => 'Commandes',
            ],
        ],
        'central' => [
            'title' => 'Le central et l’administration',
            'label' => 'Central et admin',
            'summary' => 'Liste des serveurs, fiche, slug, IP validée, rôles et pages de l’administration.',
            'toc' => [
                'acces' => 'Accès et rôles',
                'liste' => 'La liste des serveurs',
                'cycle-de-vie' => 'Cycle de vie d’un serveur',
                'fiche' => 'La fiche d’un serveur',
                'slug' => 'Le slug',
                'ip' => 'Adresse et IP validée',
                'exclusion' => 'Exclure un serveur d’Ansible',
                'mises-a-jour' => 'Fraîcheur des mises à jour',
                'pages' => 'Domaines, Mises à jour, Commandes',
            ],
        ],
        'api' => [
            'title' => 'L’API de lecture',
            'label' => 'API de lecture',
            'summary' => 'Les routes en lecture seule, leur protection et des exemples.',
            'toc' => [
                'principe' => 'Principe',
                'acces' => 'Obtenir un accès',
                'routes' => 'Les routes',
                'exemples' => 'Exemples',
                'lire' => 'Bien lire les réponses',
                'documentation' => 'Documentation complète',
            ],
        ],
        'ansible-semaphore' => [
            'title' => 'Ansible et Semaphore',
            'label' => 'Ansible et Semaphore',
            'summary' => 'L’inventaire dynamique, la configuration de Semaphore et les playbooks fournis.',
            'toc' => [
                'flux' => 'Comment tout s’enchaîne',
                'inventaire' => 'L’inventaire dynamique',
                'absent' => 'Pourquoi un serveur est absent',
                'semaphore' => 'Configurer Semaphore',
                'compte' => 'Le compte ansible',
                'cles' => 'Clés d’hôte SSH',
                'playbooks' => 'Les playbooks fournis',
            ],
        ],
        'procedures' => [
            'title' => 'Procédures',
            'label' => 'Procédures',
            'summary' => 'Les opérations courantes, pas à pas.',
            'toc' => [
                'cycle' => 'Le cycle des mises à jour',
                'nouveau-serveur' => 'Ajouter un nouveau serveur',
                'maj-agent' => 'Mettre à jour l’agent sur la flotte',
                'maj-serveurs' => 'Mettre les serveurs à jour',
                'crise-cve' => 'Crise CVE',
                'reinstaller' => 'Réinstaller un serveur',
                'ip-changee' => 'Une IP a changé',
                'exclure' => 'Exclure un serveur d’Ansible',
            ],
        ],
    ];

    public function index(): View
    {
        return $this->render('');
    }

    public function show(string $page): View
    {
        abort_unless($page !== '' && array_key_exists($page, self::PAGES), 404);

        return $this->render($page);
    }

    private function render(string $slug): View
    {
        $slugs = array_keys(self::PAGES);
        $position = array_search($slug, $slugs, true);

        return view('wiki.pages.'.($slug === '' ? 'accueil' : $slug), [
            'pages' => self::PAGES,
            'current' => $slug,
            'page' => self::PAGES[$slug],
            'previous' => $slugs[$position - 1] ?? null,
            'next' => $slugs[$position + 1] ?? null,
        ]);
    }

    /** URL of a wiki page from its slug ('' = home). */
    public static function url(string $slug): string
    {
        return $slug === '' ? route('wiki', [], false) : route('wiki.page', $slug, false);
    }
}
