<?php

namespace App\Nova\Actions;

/**
 * Staff: generate (or fetch the cached) certificate of any member.
 */
class GenerateCertificate extends CertificateAction
{
    public $name = 'Generate certificate';

    public function uriKey(): string
    {
        return 'generate-certificate';
    }
}
