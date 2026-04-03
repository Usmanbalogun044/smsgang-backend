<?php

namespace App\Enums;

enum TwilioMessageDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
