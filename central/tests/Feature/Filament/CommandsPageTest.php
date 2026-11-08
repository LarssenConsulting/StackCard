<?php

use App\Filament\Pages\Commands;
use App\Models\User;

function commandsAccount(string $role): User
{
    return User::factory()->create(['role' => $role, 'app_authentication_secret' => 'test-secret']);
}

it('shows the agent commands with the configured install script and central address', function () {
    config([
        'app.url' => 'https://central.example.org',
        'discover.agent_install_url' => 'https://downloads.example.org/install.sh',
    ]);
    $this->actingAs(commandsAccount(User::ROLE_USER), 'web');

    $this->get(Commands::getUrl())
        ->assertOk()
        ->assertSee('curl -fsSL https://downloads.example.org/install.sh | sudo sh')
        ->assertSee('DISCOVER_URL=central.example.org')
        ->assertSee('sudo discover-agent enroll --url https://central.example.org --token &lt;jeton&gt;', false)
        ->assertSee('sudo discover-agent run')
        ->assertSee('sudo systemctl disable --now discover-agent.timer')
        ->assertSee('sudo apt purge discover-agent');
});

it('lists the page in the navigation for every account', function () {
    $this->actingAs(commandsAccount(User::ROLE_USER), 'web');

    $this->get(Commands::getUrl())->assertSee(Commands::getUrl(), false);
});

it('shows the central maintenance commands to admins only', function () {
    $this->actingAs(commandsAccount(User::ROLE_USER), 'web');
    $this->get(Commands::getUrl())->assertDontSee('./deploy.sh');

    $this->actingAs(commandsAccount(User::ROLE_ADMIN), 'web');
    $this->get(Commands::getUrl())
        ->assertSee('./deploy.sh')
        ->assertSee('php artisan make:filament-user');
});
