<?php

namespace App\Http\Controllers;

use App\Models\NotificationSetting;
use Illuminate\Http\Request;
use App\Models\User;
use App\Notifications\SendNotification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class NotificationController extends Controller
{
    public function index()
    {
        $role = Role::find(Auth::user()->role_id);
        if ($role->hasPermissionTo('all_notification')) {
            $lims_notification_all = DB::table('notifications')->get();
            return view('backend.notification.index', compact('lims_notification_all'));
        } else
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
    }
    public function store(Request $request)
    {
        $document = $request->document;
        if ($document) {
            $v = Validator::make(
                [
                    'extension' => strtolower($document->getClientOriginalExtension()),
                ],
                [
                    'extension' => 'in:jpg,jpeg,png,gif,pdf,csv,docx,xlsx,txt',
                ]
            );
            if ($v->fails())
                return redirect()->back()->withErrors($v->errors());

            $documentName = date('Ymdhis') . '.' . $document->getClientOriginalExtension();
            $document->move(public_path('documents/notification'), $documentName);
            $request->document_name = $documentName;
        }
        $user = User::find($request->receiver_id);
        $user->notify(new SendNotification($request));
        return redirect()->back()->with('message', __('db.Notification send successfully'));
    }

    public function markAsRead()
    {
        if (Auth::check()) {
            $today = date('Y-m-d');
            foreach (Auth::user()->unreadNotifications as $notification) {
                $reminderDate = $notification->data['reminder_date'] ?? null;
                if (!$reminderDate || $reminderDate <= $today) {
                    $notification->markAsRead();
                }
            }
        }

        return response()->json(['success' => true]);
    }

    public function settings(Request $request)
    {
        $this->authorizeSettings($request);
        $settings = NotificationSetting::all();
        return view('backend.notification.settings', compact('settings'));
    }

    public function updateSettings(Request $request)
    {
        $this->authorizeSettings($request);
        $data = $request->input('settings', []);
        $renderer = app(\App\Services\NotificationTemplateRenderer::class);

        // Pre-validate all submitted templates against event allowlists
        foreach ($data as $id => $values) {
            $setting = NotificationSetting::find($id);
            if (!$setting) {
                continue;
            }

            foreach (['whatsapp_message', 'sms_message', 'mail_message'] as $field) {
                if (!empty($values[$field])) {
                    $invalidTags = $renderer->validateTemplate($setting->event, $values[$field]);
                    if (!empty($invalidTags)) {
                        return redirect()->back()->withInput()->with(
                            'not_permitted',
                            __('db.The placeholder :tag is not supported for event :event', [
                                'tag'   => implode(', ', $invalidTags),
                                'event' => ucwords(str_replace('_', ' ', $setting->event)),
                            ])
                        );
                    }
                }
            }
        }

        foreach ($data as $id => $values) {
            $setting = NotificationSetting::find($id);
            if (!$setting) {
                continue;
            }

            $isFullMatrixRow = !empty($values['matrix_row']);
            $update = [];

            if ($isFullMatrixRow) {
                // When submitted from the full matrix view, unselected checkboxes mean disabled
                $update['notify_in_app'] = isset($values['notify_in_app']);
                $update['notify_mail'] = isset($values['notify_mail']);
                $update['notify_whatsapp'] = isset($values['notify_whatsapp']);
                $update['notify_sms'] = isset($values['notify_sms']);
            } else {
                // Partial update: only update toggles that are explicitly supplied
                if (array_key_exists('notify_in_app', $values)) {
                    $update['notify_in_app'] = (bool) $values['notify_in_app'];
                }
                if (array_key_exists('notify_mail', $values)) {
                    $update['notify_mail'] = (bool) $values['notify_mail'];
                }
                if (array_key_exists('notify_whatsapp', $values)) {
                    $update['notify_whatsapp'] = (bool) $values['notify_whatsapp'];
                }
                if (array_key_exists('notify_sms', $values)) {
                    $update['notify_sms'] = (bool) $values['notify_sms'];
                }
            }

            if (array_key_exists('recipients', $values)) {
                $update['recipients'] = $values['recipients'];
            }

            if (array_key_exists('mail_message', $values)) {
                $update['mail_message'] = $values['mail_message'] !== '' ? $values['mail_message'] : null;
            }

            if (array_key_exists('whatsapp_message', $values)) {
                $update['whatsapp_message'] = $values['whatsapp_message'] !== '' ? $values['whatsapp_message'] : null;
            }

            if (array_key_exists('sms_message', $values)) {
                $update['sms_message'] = $values['sms_message'] !== '' ? $values['sms_message'] : null;
            }

            if (!empty($update)) {
                $setting->update($update);
            }
        }

        return redirect()->back()->with('message', __('db.Notification settings updated successfully!'));
    }

    private function authorizeSettings(Request $request): void
    {
        $user = $request->user();

        // Channel configuration is a system setting, not an operational
        // notification permission. Restrict it to the built-in owner/admin
        // roles until a dedicated settings permission is introduced.
        abort_unless($user && (int) $user->role_id <= 2, 403);
    }
}
