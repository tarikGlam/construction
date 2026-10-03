<?php

namespace App\Http\Controllers;

use App\Models\WhatsappSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Barryvdh\DomPDF\Facade\Pdf;

class WhatsappController extends Controller
{

    /** WhatsApp Settings */
    public function settings()
    {
        $settings = WhatsappSetting::firstOrCreate([]);
        return view('backend.whatsapp.settings', compact('settings'));
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'phone_number_id' => 'nullable|string',
            'business_account_id' => 'nullable|string',
            'permanent_access_token' => 'nullable|string',
            'message_types' => 'nullable|array',
        ]);

        $settings = WhatsappSetting::first();
        $settings->update([
            'phone_number_id' => $data['phone_number_id'],
            'business_account_id' => $data['business_account_id'],
            'permanent_access_token' => $data['permanent_access_token'],
            'message_types' => isset($data['message_types']) ? implode(',', $data['message_types']) : null,
        ]);

        return redirect()->back()->with('message', __('db.Data updated successfully'));
    }

     // 🔹 Template list page
    public function templates()
    {
        $settings = WhatsappSetting::first();
        $asset_id = null;
        if (!$settings || empty($settings->business_account_id) || empty($settings->permanent_access_token)) {
            $templates = [];
            session()->now('not_permitted', __('db.whatsapp_credentials_missing'));
            return view('backend.whatsapp.templates', compact('templates', 'asset_id'));
        }

        $asset_id = $settings->business_account_id;
        $templates = $settings->getTemplates();

        if (isset($templates['error'])) {
            $error = $templates['error'];
            $templates = [];
            session()->now('not_permitted', $error);
            return view('backend.whatsapp.templates', compact('templates', 'asset_id'));
        }

        return view('backend.whatsapp.templates', compact('templates', 'asset_id'));
    }

    // 🔹 Delete template
    public function deleteTemplate($name)
    {
        $settings = WhatsappSetting::first();
        $result = $settings->deleteTemplate($name);

        return back()->with($result['success'] ? 'message' : 'not_permitted', $result['message']);
    }

    /** Send Message */
    public function sendPage(Request $request)
    {
        $receivers = [];

        // // User
        // $users = DB::table('users')->select('name', 'phone')->get();
        // if ($users->count()) {
        //     $receivers['Users'] = $users;
        // }

        // // Employee
        // $employees = DB::table('employees')->select('name', 'phone_number as phone')->get();
        // if ($employees->count()) {
        //     $receivers['Employees'] = $employees;
        // }

        // // Biller
        // $billers = DB::table('billers')->select('name', 'phone_number as phone')->get();
        // if ($billers->count()) {
        //     $receivers['Billers'] = $billers;
        // }

        // Supplier
        $suppliers = DB::table('suppliers')->whereNotNull('wa_number')->select('name', 'wa_number as phone')->get();
        if ($suppliers->count()) {
            $receivers['Suppliers'] = $suppliers;
        }

        // Customer
        $customers = DB::table('customers')->whereNotNull('wa_number')->select('name', 'wa_number as phone')->get();
        if ($customers->count()) {
            $receivers['Customers'] = $customers;
        }

        $selectedGroup = $request->get('group');
        $selectedPhone = $request->get('phone');

        $settings = WhatsappSetting::first();
        if (!$settings || empty($settings->business_account_id) || empty($settings->permanent_access_token)) {
            $templates = [];
            session()->now('not_permitted', __('db.whatsapp_credentials_missing'));
            return view('backend.whatsapp.send', compact('templates', 'receivers', 'selectedGroup', 'selectedPhone'));
        }

        $templates = $settings->getTemplates();

        if (isset($templates['error'])) {
            $error = $templates['error'];
            $templates = [];
            session()->now('not_permitted', $error);
            return view('backend.whatsapp.send', compact('templates', 'receivers', 'selectedGroup', 'selectedPhone'));
        }

        return view('backend.whatsapp.send', compact('templates', 'receivers', 'selectedGroup', 'selectedPhone'));
    }

    public function sendMessage(Request $request)
    {
        $data = $request->validate([
            'receiver_phone' => 'required|array',
            'receiver_phone.*' => 'required|regex:/^[0-9]+$/',
            'template_info' => 'nullable',
            'message' => 'nullable|string',
            'attachment' => 'nullable|file|max:10240',
            'attachment_type' => 'nullable|in:image,document',
            'html_content' => 'nullable|string',
            'is_optional' => 'nullable|boolean',
        ]);

        $phoneNumbers = $data['receiver_phone'];
        $isOptional = $request->boolean('is_optional');
        $settings = WhatsappSetting::first();
        if (!$settings || empty($settings->phone_number_id) || empty($settings->permanent_access_token)) {
            if ($isOptional) {
                return response()->json([
                    'status' => 'skipped',
                    'message' => __('db.whatsapp_credentials_missing'),
                ], 200);
            }

            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'message' => __('db.whatsapp_credentials_missing'),
                    'error' => __('db.whatsapp_credentials_missing'),
                ], 422);
            }

            return back()->with('not_permitted', __('db.whatsapp_credentials_missing'));
        }

        $type = 'text';
        $messageContent = null;
        $tempPath = null;

        // -----------------------------------------------------
        // 🔥 CASE 1: Html content → Auto-generate PDF → Send
        // -----------------------------------------------------
        if (!empty($data['html_content'])) {
            // hidden-print কে PDF-এ hide করার জন্য
            $cleanHtml = str_replace('class="hidden-print"', 'style="display:none;"', $data['html_content']);
            //Generate temp PDF
            $tempPath = storage_path("app/temp_file_" . Str::random(8) . ".pdf");
            PDF::setOptions([
                'isRemoteEnabled' => true,
                'isHtml5ParserEnabled' => true,
            ])->loadHTML($cleanHtml)->save($tempPath);
            //PDF::loadHTML($data['html_content'])->save($tempPath);

            //Convert PDF to UploadedFile
            $pdfFile = new UploadedFile(
                $tempPath,
                $data['message'].'.pdf',
                'application/pdf',
                null,
                true
            );

            //Prepare message content for WhatsApp
            $type = 'document';
            $messageContent = [
                'file' => $pdfFile,
                'caption' => $data['message'] ?? null,
            ];
        }
        // ✅ If template selected
        else if (!empty($data['template_info'])) {
                $type = 'template';
                list($messageContent['name'], $messageContent['lang_code']) = explode('|', $data['template_info']);
        }
        else if ($request->hasFile('attachment')) {
            $type = $data['attachment_type'] === 'image' ? 'image' : 'document';
            $messageContent = [
                'file' => $request->file('attachment'),
                'caption' => $data['message'] ?? null,
            ];
        }
        else {
            $type = 'text';
            $messageContent = $data['message'] ?? null;
        }

        try {
            $result = $settings->sendMessage($phoneNumbers, $type, $messageContent);
        } finally {
            if ($tempPath && is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
        $isSuccess = (bool) ($result['success'] ?? false);

        if ($isOptional) {
            if ($isSuccess) {
                return response()->json([
                    'status' => 'success',
                    'message' => $result['message'] ?? 'WhatsApp message sent successfully',
                ], 200);
            }

            return response()->json([
                'status' => 'warning',
                'message' => $result['message'] ?? __('db.fail_sent_message'),
            ], 200);
        }

        if ($request->has('_from_form') || (!$request->ajax() && !$request->wantsJson())) {
            // 6️⃣ Response for standard browser form
            if ($isSuccess) {
                return back()->with('message', $result['message']);
            } else {
                return back()->with('not_permitted', $result['message'] ?? __('db.fail_sent_message'));
            }
        }

        // AJAX / API caller: return distinct status codes
        if ($isSuccess) {
            return response()->json([
                'status' => 'success',
                'message' => $result['message'] ?? 'WhatsApp message sent successfully',
            ], 200);
        }

        return response()->json([
            'message' => $result['message'] ?? __('db.fail_sent_message'),
            'error' => $result['message'] ?? __('db.fail_sent_message'),
        ], 502);
    }
}
