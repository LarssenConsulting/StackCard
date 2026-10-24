<?php

namespace App\Filament\Auth;

use Filament\Auth\MultiFactor\App\AppAuthentication as BaseAppAuthentication;
use SensitiveParameter;

class AppAuthentication extends BaseAppAuthentication
{
    private const SVG_DATA_URI = 'data:image/svg+xml;base64,';

    /**
     * Without imagick, Filament wraps the QR code in a data URI, but google2fa-qrcode v4 already
     * returns one: the browser then gets a data URI of a data URI and shows a broken image.
     */
    public function generateQrCodeDataUri(#[SensitiveParameter] string $secret): string
    {
        $uri = parent::generateQrCodeDataUri($secret);

        if (str_starts_with($uri, self::SVG_DATA_URI)) {
            $inner = base64_decode(substr($uri, strlen(self::SVG_DATA_URI)), true);

            if ($inner !== false && str_starts_with($inner, 'data:image/')) {
                return $inner;
            }
        }

        return $uri;
    }
}
