<?php

namespace App\Enums;

enum ActivationStatus: string
{
    case Requested = 'requested';
    case NumberReceived = 'number_received';
    case WaitingSms = 'waiting_sms';
    case SmsReceived = 'sms_received';
    case Completed = 'completed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
