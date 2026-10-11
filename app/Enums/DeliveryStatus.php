<?php

namespace App\Enums;

enum DeliveryStatus: string
{
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Bounced = 'bounced';
    case Failed = 'failed';

    /** Not sent because the member disabled this notification category. */
    case Skipped = 'skipped';
}
