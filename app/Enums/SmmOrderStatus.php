<?php

namespace App\Enums;

enum SmmOrderStatus: string
{
    case PendingProviderConfirmation = 'pending_provider_confirmation';
    case Pending = 'Pending';
    case InProgress = 'In progress';
    case Partial = 'Partial';
    case Completed = 'Completed';
    case Failed = 'Failed';
    case FailedAtProvider = 'failed_at_provider';
    case Cancelled = 'Cancelled';

    public static function providerTracked(): array
    {
        return [
            self::Pending->value,
            self::InProgress->value,
            self::Partial->value,
        ];
    }

    public static function terminal(): array
    {
        return [
            self::Completed->value,
            self::Cancelled->value,
            self::Failed->value,
            self::FailedAtProvider->value,
        ];
    }
}
