<?php

namespace Modules\IndiaGST\Observers;

use Modules\IndiaGST\Entities\IndiaGstReturnSnapshot;
use Illuminate\Validation\ValidationException;

class IndiaGstReturnSnapshotObserver
{
    public function updating(IndiaGstReturnSnapshot $snapshot): void
    {
        throw ValidationException::withMessages([
            'india_gst' => 'GST credit note / return snapshot is immutable and cannot be updated once locked.'
        ]);
    }

    public function deleting(IndiaGstReturnSnapshot $snapshot): void
    {
        throw ValidationException::withMessages([
            'india_gst' => 'GST credit note / return snapshot is immutable and cannot be deleted.'
        ]);
    }
}
