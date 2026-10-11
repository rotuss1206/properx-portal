<?php

namespace App\Nova\Actions;

/**
 * Member: download the certificate of their own profile.
 */
class DownloadMyCertificate extends CertificateAction
{
    public $name = 'Download my certificate';

    public function uriKey(): string
    {
        return 'download-my-certificate';
    }
}
