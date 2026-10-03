<?php

namespace Modules\IndiaGST\Observers;

use Modules\IndiaGST\Entities\IndiaGstPurchaseReturnSnapshot;
use Illuminate\Validation\ValidationException;

class IndiaGstPurchaseReturnSnapshotObserver
{
    public function updating(IndiaGstPurchaseReturnSnapshot $snapshot): void
    {
        throw ValidationException::withMessages([
            'india_gst' => 'GST purchase return / adjustment snapshot is immutable and cannot be updated once locked.'
        ]);
    }

    public function deleting(IndiaGstPurchaseReturnSnapshot $snapshot): void
    {
        throw ValidationException::withMessages([
            'india_gst' => 'GST purchase return / adjustment snapshot is immutable and cannot be deleted.'
        ]);
    }
}
