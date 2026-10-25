<?php

namespace App\Enums;

/**
 * Where a cheque stands — derived by App\Services\Accounting\ChequeRegister, never stored:
 * WAITING (in hand or issued, not yet through the bank), CLEARED (marked cleared, or its bank line
 * ticked in a reconciliation), BOUNCED (marked bounced; its document was reversed), VOID (its
 * document was reversed for another reason).
 */
enum ChequeStatus: string
{
    case Waiting = 'WAITING';
    case Cleared = 'CLEARED';
    case Bounced = 'BOUNCED';
    case Void = 'VOID';

    /**
     * The display name, in the reader's language. Never save it — see App\Support\RecordText.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::Waiting => 'Waiting',
            self::Cleared => 'Cleared',
            self::Bounced => 'Bounced',
            self::Void => 'Void',
        });
    }
}
