<?php

namespace App\Enums;

enum TwilioSubscriptionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case RenewalDue = 'renewal_due';
    case Grace = 'grace';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Released = 'released';
}
