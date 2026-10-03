<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedOnDomainException;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array
     */
    protected $dontFlash = [
        'password',
        'password_confirmation',
        'webhook_secret',
    ];

    /**
     * Report or log an exception.
     *
     * @param  \Throwable  $exception
     * @return void
     *
     * @throws \Throwable
     */
    public function report(Throwable  $exception)
    {
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Exception  $exception
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \Throwable
     */
    public function render($request, Throwable $exception)
    {
        if ($exception instanceof ZatcaPhase2WorkflowUnavailable) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
            }

            return redirect()->back()->with('not_permitted', $exception->getMessage());
        }

        if ($exception instanceof TenantCouldNotBeIdentifiedOnDomainException) {
            return redirect($request->getScheme(). '://' . env('CENTRAL_DOMAIN'));
        }

        if (($request->expectsJson() || $request->ajax())
            && !$this->isHttpException($exception)
            && !($exception instanceof \Illuminate\Validation\ValidationException)
            && !($exception instanceof \Illuminate\Auth\AuthenticationException)
            && !($exception instanceof \Illuminate\Auth\Access\AuthorizationException)
            && !($exception instanceof \Illuminate\Database\Eloquent\ModelNotFoundException)
        ) {
            $message = __('db.We could not complete this action. Please try again.');
            if ($message === 'db.We could not complete this action. Please try again.') {
                $message = 'We could not complete this action. Please try again.';
            }

            return response()->json([
                'success' => false,
                'message' => $message,
            ], 500);
        }

        return parent::render($request, $exception);
    }

}
