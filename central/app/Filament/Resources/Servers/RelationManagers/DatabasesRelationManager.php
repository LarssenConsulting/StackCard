<?php

namespace App\Filament\Resources\Servers\RelationManagers;

use App\Filament\Resources\Databases\DatabaseResource;
use App\Filament\Resources\Databases\Tables\DatabasesTable;
use App\Models\Server;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class DatabasesRelationManager extends RelationManager
{
    protected static string $relationship = 'databases';

    protected static ?string $title = 'Bases de données';

    protected static ?string $relatedResource = DatabaseResource::class;

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return DatabasesTable::configure($table, withServer: false)
            ->emptyStateHeading(fn () => match (true) {
                $this->mysqlError() !== null => 'MySQL / MariaDB inaccessible pour l’agent',
                $this->getOwnerRecord()->mysql_version !== null => 'Aucune base de données',
                default => 'MySQL / MariaDB non détecté',
            })
            ->emptyStateDescription(fn () => $this->getOwnerRecord()->mysql_version !== null && $this->mysqlError() === null
                ? null
                : self::readerSteps($this->mysqlError()));
    }

    /** The MySQL error of the latest snapshot: the agent could not read the databases. */
    private function mysqlError(): ?string
    {
        /** @var Server $server */
        $server = $this->getOwnerRecord();
        $errors = $server->snapshots()->latest('received_at')->latest('id')->value('payload')['errors'] ?? [];

        return collect($errors)->firstWhere('collector', 'mysql')['message'] ?? null;
    }

    /** Create a read-only MySQL account for the agent when root is not reachable over the socket. */
    private static function readerSteps(?string $error): HtmlString
    {
        $block = fn (string $text) => '<pre style="user-select:all;white-space:pre-wrap;text-align:left;'
            .'margin-top:.25rem;padding:.5rem .75rem;border-radius:.5rem;background:rgb(0 0 0 / .05)"><code>'
            .e($text).'</code></pre>';
        $sql = "CREATE USER 'discover'@'localhost' IDENTIFIED BY '\$PASS'; "
            ."GRANT SHOW DATABASES ON *.* TO 'discover'@'localhost'; "
            ."GRANT SELECT ON *.* TO 'discover'@'localhost';";

        return new HtmlString(
            ($error !== null ? '<p>L’agent signale : <code>'.e($error).'</code></p>' : '')
            .'<p style="margin-top:.5rem">Si MySQL ou MariaDB est installé mais que root n’y accède pas par le socket '
            .'(root avec mot de passe), créez un compte en lecture seule pour l’agent. Sur le serveur :</p>'
            .'<ol style="list-style:decimal;padding-left:1.25rem;text-align:left;display:grid;gap:.75rem;margin-top:.75rem">'
            .'<li>Générez un mot de passe et créez le compte (le mot de passe root MySQL est demandé) :'
            .$block("PASS=\$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9')\nsudo mysql -u root -p -e \"{$sql}\"")
            .'<code>SELECT ON *.*</code> sert aux tailles des bases : MySQL ne compte que les tables '
            .'que le compte peut lire (sans ce droit, toutes les tailles valent 0).</li>'
            .'<li>Donnez ce compte à l’agent (fichier lisible par root seul), dans le même terminal :'
            .$block("printf '[client]\\nuser=discover\\npassword=\"%s\"\\n' \"\$PASS\" \\\n"
                .'  | sudo install -m 0600 -o root -g root /dev/stdin /etc/discover-agent/mysql.cnf')
            .'</li>'
            .'<li>Envoyez un inventaire, puis rechargez cette page :'.$block('sudo discover-agent run').'</li>'
            .'</ol>'
        );
    }
}
