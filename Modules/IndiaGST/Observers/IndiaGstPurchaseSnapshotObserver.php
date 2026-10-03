<?php

namespace Modules\IndiaGST\Observers;

use Modules\IndiaGST\Entities\IndiaGstPurchaseSnapshot;
use Illuminate\Validation\ValidationException;

class IndiaGstPurchaseSnapshotObserver
{
    public function updating(IndiaGstPurchaseSnapshot $snapshot): void
    {
        throw ValidationException::withMessages([
            'india_gst' => 'GST purchase snapshot is immutable and cannot be updated once locked.'
        ]);
    }

    public function deleting(IndiaGstPurchaseSnapshot $snapshot): void
    {
        throw ValidationException::withMessages([
            'india_gst' => 'GST purchase snapshot is immutable and cannot be deleted.'
        ]);
    }
}
