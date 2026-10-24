<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A loan reminder already sent, so `loans:remind` never sends it twice.
 */
class LoanReminder extends Model
{
    protected $fillable = ['loan_id', 'due_date', 'days_before', 'sent_at'];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'days_before' => 'integer',
            'sent_at' => 'datetime',
        ];
    }
}
