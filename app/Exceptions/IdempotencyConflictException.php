<?php

namespace App\Exceptions;

use Exception;

class IdempotencyConflictException extends Exception
{
    public function render($request)
    {
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'error' => 'idempotency_conflict',
            ], 409);
        }

        return redirect()->back()->with('not_permitted', $this->getMessage())->withInput();
    }
}
