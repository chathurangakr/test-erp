<?php

namespace App\Http\Controllers\Accounting;

use App\Actions\Accounting\CancelJournalEntry;
use App\Actions\Accounting\PostJournalEntry;
use App\Actions\Accounting\ReverseJournalEntry;
use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * The journal lifecycle: post, reverse, cancel.
 *
 * Separate from JournalEntryController because these are business events, not form saves.
 * Each delegates to an action, so the REST API a Flutter client will use can reuse exactly
 * the same rules.
 */
class JournalEntryActionController extends Controller
{
    public function post(JournalEntry $journal, PostJournalEntry $action): RedirectResponse
    {
        $action->handle($journal, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Journal entry posted.')]);

        return to_route('journals.show', $journal);
    }

    public function reverse(JournalEntry $journal, ReverseJournalEntry $action): RedirectResponse
    {
        $reversal = $action->handle($journal, auth()->id());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Journal entry reversed by :reference.', ['reference' => $reversal->journal_number]),
        ]);

        return to_route('journals.show', $reversal);
    }

    public function cancel(JournalEntry $journal, CancelJournalEntry $action): RedirectResponse
    {
        $action->handle($journal, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Draft journal entry cancelled.')]);

        return to_route('journals.show', $journal);
    }
}
