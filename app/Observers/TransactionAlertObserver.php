<?php

namespace App\Observers;

use App\Models\TelegramAlert;
use App\Models\TelegramSetting;
use App\Services\Telegram\TransactionAlertCatalog;
use Illuminate\Database\Eloquent\Model;

/**
 * Queues a transaction alert when a reported document is posted or reversed.
 *
 * The alert row is written inside the posting's own database transaction, so it rolls back with
 * a failed posting and exists exactly when the document does. Nothing is sent from here:
 * `telegram:send-alerts` sends it within a minute, so Telegram never slows down or breaks a
 * posting. Observed models are listed in TransactionAlertCatalog::MODELS. See docs/TELEGRAM.md.
 */
class TransactionAlertObserver
{
    public function created(Model $model): void
    {
        $this->queue($model, TransactionAlertCatalog::createdEvent($model));
    }

    public function updated(Model $model): void
    {
        $this->queue($model, TransactionAlertCatalog::updatedEvent($model));
    }

    private function queue(Model $model, ?string $event): void
    {
        $type = TransactionAlertCatalog::typeOf($model);

        if ($event === null || $type === null || ! TelegramSetting::current(TelegramSetting::TRANSACTIONS)->wantsAlert($type)) {
            return;
        }

        TelegramAlert::create([
            'document_type' => $type,
            'document_id' => $model->getKey(),
            'event' => $event,
            'context' => TransactionAlertCatalog::context($model, $event),
        ]);
    }
}
