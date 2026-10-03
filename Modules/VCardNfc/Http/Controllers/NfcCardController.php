<?php

namespace Modules\VCardNfc\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\VCardNfc\Entities\NfcCard;
use Modules\VCardNfc\Entities\VCardProfile;

class NfcCardController extends Controller
{
    public function index()
    {
        $cards = NfcCard::with('profile')->latest()->get();
        $profiles = VCardProfile::orderBy('name')->get();

        return view('vcardnfc::nfc.index', compact('cards', 'profiles'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
            'vcard_profile_id' => ['nullable', 'exists:vcard_profiles,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        NfcCard::create([
            'label' => $data['label'] ?? null,
            'vcard_profile_id' => $data['vcard_profile_id'] ?? null,
            'is_active' => $request->boolean('is_active', true),
            'token' => $this->uniqueToken(),
        ]);

        return redirect()->route('vcardnfc.nfc.index')->with('message', 'NFC card created. Write its NFC URL to the physical card/tag.');
    }

    public function update(Request $request, NfcCard $card)
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
            'vcard_profile_id' => ['nullable', 'exists:vcard_profiles,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $card->update([
            'label' => $data['label'] ?? null,
            'vcard_profile_id' => $data['vcard_profile_id'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('vcardnfc.nfc.index')->with('message', 'NFC card updated.');
    }

    public function destroy(NfcCard $card)
    {
        $card->delete();
        return redirect()->route('vcardnfc.nfc.index')->with('message', 'NFC card deleted.');
    }

    private function uniqueToken(): string
    {
        do {
            $token = Str::random(24);
        } while (NfcCard::where('token', $token)->exists());

        return $token;
    }
}
