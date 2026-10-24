<?php

use App\Filament\Auth\AppAuthentication;
use App\Models\User;
use Filament\Facades\Filament;

it('uses the app authentication that fixes the QR code', function () {
    $providers = Filament::getPanel('admin')->getMultiFactorAuthenticationProviders();

    expect(collect($providers)->contains(fn ($provider) => $provider instanceof AppAuthentication))->toBeTrue();
});

it('renders the QR code as a single data URI', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::factory()->create(), 'web');

    $uri = AppAuthentication::make()->generateQrCodeDataUri('JBSWY3DPEHPK3PXP');

    // Without imagick, Filament wrapped the data URI returned by google2fa-qrcode v4 a second time.
    expect($uri)->toMatch('#^data:image/(svg\+xml|png);base64,#');
    $image = base64_decode(substr($uri, strpos($uri, ',') + 1), true);
    expect($image)->not->toBeFalse()
        ->and(str_starts_with($image, 'data:'))->toBeFalse()
        ->and(str_contains($image, '<svg') || str_starts_with($image, "\x89PNG"))->toBeTrue();
});
