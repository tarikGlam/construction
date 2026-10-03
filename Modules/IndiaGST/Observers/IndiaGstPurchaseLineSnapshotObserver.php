<?php

namespace Modules\IndiaGST\Observers;

use Modules\IndiaGST\Entities\IndiaGstPurchaseLineSnapshot;
use Illuminate\Validation\ValidationException;

class IndiaGstPurchaseLineSnapshotObserver
{
    public function updating(IndiaGstPurchaseLineSnapshot $snapshot): void
    {
        throw ValidationException::withMessages([
            'india_gst' => 'GST purchase line snapshot is immutable and cannot be updated.'
        ]);
    }

    public function deleting(IndiaGstPurchaseLineSnapshot $snapshot): void
    {
        throw ValidationException::withMessages([
            'india_gst' => 'GST purchase line snapshot is immutable and cannot be deleted.'
        ]);
    }
}
