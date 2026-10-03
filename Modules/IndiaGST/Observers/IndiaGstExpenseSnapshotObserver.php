<?php

namespace Modules\IndiaGST\Observers;

use Modules\IndiaGST\Entities\IndiaGstExpenseSnapshot;
use Illuminate\Validation\ValidationException;

class IndiaGstExpenseSnapshotObserver
{
    public function updating(IndiaGstExpenseSnapshot $snapshot): void
    {
        throw ValidationException::withMessages([
            'india_gst' => 'GST expense snapshot is immutable and cannot be updated once locked.'
        ]);
    }

    public function deleting(IndiaGstExpenseSnapshot $snapshot): void
    {
        throw ValidationException::withMessages([
            'india_gst' => 'GST expense snapshot is immutable and cannot be deleted.'
        ]);
    }
}
