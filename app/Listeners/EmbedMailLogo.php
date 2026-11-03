<?php

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;

/**
 * Puts the logo inside every email whose header asks for it (resources/views/vendor/mail/html/
 * header.blade.php): nothing is fetched from outside when the message is opened.
 */
class EmbedMailLogo
{
    public const CONTENT_ID = 'properx-logo';

    public function handle(MessageSending $event): void
    {
        $html = $event->message->getHtmlBody();

        if (is_string($html) && str_contains($html, 'cid:'.self::CONTENT_ID)) {
            $event->message->embedFromPath(public_path('portal/img/logo-color@2x.png'), self::CONTENT_ID, 'image/png');
        }
    }
}
