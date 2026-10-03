<?php

namespace Modules\VCardNfc\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\VCardNfc\Entities\NfcCard;
use Modules\VCardNfc\Entities\VCardEvent;
use Modules\VCardNfc\Entities\VCardProfile;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class PublicVCardController extends Controller
{
    public function show(Request $request, string $slug)
    {
        $profile = VCardProfile::where('slug', $slug)->where('is_active', true)->with('socialLinks')->firstOrFail();
        $source = in_array($request->query('source'), ['qr', 'nfc', 'direct'], true) ? $request->query('source') : 'direct';

        $this->track($request, $profile, $source === 'qr' ? 'qr_scan' : 'profile_view', $source);

        return view('vcardnfc::public.profile', compact('profile'));
    }

    public function nfc(Request $request, string $token)
    {
        $card = NfcCard::where('token', $token)->where('is_active', true)->with('profile')->firstOrFail();
        abort_unless($card->profile && $card->profile->is_active, 404);

        $this->track($request, $card->profile, 'nfc_tap', 'nfc', $card->id);

        return redirect()->route('vcardnfc.public.show', ['slug' => $card->profile->slug, 'source' => 'nfc']);
    }

    public function vcf(Request $request, string $slug)
    {
        $profile = VCardProfile::where('slug', $slug)->where('is_active', true)->firstOrFail();
        $this->track($request, $profile, 'contact_save', $request->query('source', 'direct'));

        $content = $this->generateVcf($profile);

        return response($content, 200, [
            'Content-Type' => 'text/vcard; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $profile->slug . '.vcf"',
        ]);
    }

    public function qr(Request $request, string $slug)
    {
        $profile = VCardProfile::where('slug', $slug)->where('is_active', true)->firstOrFail();
        $url = route('vcardnfc.public.show', ['slug' => $profile->slug, 'source' => 'qr']);
        $svg = QrCode::format('svg')->size(320)->margin(1)->generate($url);

        $headers = ['Content-Type' => 'image/svg+xml'];
        if ($request->boolean('download')) {
            $headers['Content-Disposition'] = 'attachment; filename="' . $profile->slug . '-qr.svg"';
        }

        return response((string) $svg, 200, $headers);
    }

    private function track(Request $request, VCardProfile $profile, string $type, ?string $source = null, ?int $cardId = null): void
    {
        VCardEvent::create([
            'vcard_profile_id' => $profile->id,
            'nfc_card_id' => $cardId,
            'event_type' => $type,
            'source' => in_array($source, ['qr', 'nfc', 'direct'], true) ? $source : 'direct',
            'ip_hash' => $request->ip() ? hash('sha256', config('app.key') . '|' . $request->ip()) : null,
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }

    private function generateVcf(VCardProfile $profile): string
    {
        $lines = ['BEGIN:VCARD', 'VERSION:3.0'];
        $lines[] = 'FN:' . $this->escape($profile->name);
        $lines[] = 'N:;' . $this->escape($profile->name) . ';;;';

        if ($profile->company) $lines[] = 'ORG:' . $this->escape($profile->company);
        if ($profile->designation) $lines[] = 'TITLE:' . $this->escape($profile->designation);
        if ($profile->phone) $lines[] = 'TEL;TYPE=CELL:' . $this->escape($profile->phone);
        if ($profile->whatsapp) $lines[] = 'TEL;TYPE=CELL,WhatsApp:' . $this->escape($profile->whatsapp);
        if ($profile->email) $lines[] = 'EMAIL;TYPE=INTERNET:' . $this->escape($profile->email);
        if ($profile->website) $lines[] = 'URL:' . str_replace(["\r", "\n"], '', $profile->website);
        if ($profile->address) $lines[] = 'ADR;TYPE=WORK:;;' . $this->escape($profile->address) . ';;;;';
        if ($profile->bio) $lines[] = 'NOTE:' . $this->escape($profile->bio);
        $lines[] = 'URL;TYPE=PREF:' . route('vcardnfc.public.show', $profile->slug);

        if ($profile->profile_photo && Storage::disk('public')->exists($profile->profile_photo)) {
            $mime = Storage::disk('public')->mimeType($profile->profile_photo) ?: 'image/jpeg';
            $type = str_contains($mime, 'png') ? 'PNG' : 'JPEG';
            $lines[] = 'PHOTO;ENCODING=b;TYPE=' . $type . ':' . base64_encode(Storage::disk('public')->get($profile->profile_photo));
        }

        $lines[] = 'END:VCARD';

        return implode("\r\n", array_map([$this, 'foldLine'], $lines)) . "\r\n";
    }

    private function escape(?string $text): string
    {
        return str_replace(["\\", ",", ";", "\r\n", "\r", "\n"], ["\\\\", "\\,", "\\;", "\\n", "\\n", "\\n"], (string) $text);
    }

    private function foldLine(string $line): string
    {
        if (strlen($line) <= 75) return $line;
        $parts = [];
        while (strlen($line) > 75) {
            $cut = 75;
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) $cut--;
            $parts[] = substr($line, 0, $cut);
            $line = ' ' . substr($line, $cut);
        }
        $parts[] = $line;
        return implode("\r\n", $parts);
    }
}
