<?php

namespace Modules\VCardNfc\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\GeneralSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\VCardNfc\Entities\VCardProfile;

class VCardProfileController extends Controller
{
    private const SOCIAL_PLATFORMS = ['facebook', 'linkedin', 'instagram', 'x', 'youtube'];

    public function index()
    {
        $profiles = VCardProfile::with(['socialLinks', 'linkedUser', 'employee'])
            ->withCount([
                'events as profile_views_count' => fn ($q) => $q->where('event_type', 'profile_view'),
                'events as nfc_taps_count' => fn ($q) => $q->where('event_type', 'nfc_tap'),
                'events as qr_scans_count' => fn ($q) => $q->where('event_type', 'qr_scan'),
                'events as contact_saves_count' => fn ($q) => $q->where('event_type', 'contact_save'),
            ])
            ->latest()
            ->get();

        return view('vcardnfc::profiles.index', compact('profiles'));
    }

    public function create()
    {
        return view('vcardnfc::profiles.form', [
            'profile' => new VCardProfile(['is_active' => true]),
            'socialLinks' => collect(),
            'platforms' => self::SOCIAL_PLATFORMS,
            'linkedPerson' => null,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateProfile($request);
        $data['user_id'] = auth()->id();
        $data = array_merge($data, $this->resolvePersonLink($request->input('linked_person')));

        $profile = null;

        DB::transaction(function () use ($request, $data, &$profile) {
            if ($request->hasFile('profile_photo')) {
                $data['profile_photo'] = $request->file('profile_photo')->store('vcards/profiles', 'public');
            }

            $profile = VCardProfile::create($data);
            $this->syncSocialLinks($profile, $request->input('social_links', []));
        });

        return redirect()->route('vcardnfc.profiles.edit', $profile)->with('message', 'vCard profile created successfully.');
    }

    public function edit(VCardProfile $profile)
    {
        $profile->load(['socialLinks', 'linkedUser', 'employee.designation']);

        return view('vcardnfc::profiles.form', [
            'profile' => $profile,
            'socialLinks' => $profile->socialLinks->keyBy('platform'),
            'platforms' => self::SOCIAL_PLATFORMS,
            'linkedPerson' => $this->linkedPersonSummary($profile),
        ]);
    }

    public function update(Request $request, VCardProfile $profile)
    {
        $data = $this->validateProfile($request, $profile);
        $data = array_merge($data, $this->resolvePersonLink($request->input('linked_person')));

        DB::transaction(function () use ($request, $data, $profile) {
            if ($request->boolean('remove_profile_photo') && $profile->profile_photo) {
                Storage::disk('public')->delete($profile->profile_photo);
                $data['profile_photo'] = null;
            }

            if ($request->hasFile('profile_photo')) {
                if ($profile->profile_photo) {
                    Storage::disk('public')->delete($profile->profile_photo);
                }
                $data['profile_photo'] = $request->file('profile_photo')->store('vcards/profiles', 'public');
            }

            $profile->update($data);
            $this->syncSocialLinks($profile, $request->input('social_links', []));
        });

        return redirect()->route('vcardnfc.profiles.edit', $profile)->with('message', 'vCard profile updated successfully.');
    }

    public function destroy(VCardProfile $profile)
    {
        if ($profile->profile_photo) {
            Storage::disk('public')->delete($profile->profile_photo);
        }
        $profile->delete();

        return redirect()->route('vcardnfc.profiles.index')->with('message', 'vCard profile deleted.');
    }

    public function personSearch(Request $request)
    {
        $term = trim((string) $request->query('q'));
        if (mb_strlen($term) < 2) {
            return response()->json(['results' => []]);
        }

        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $term) . '%';

        $users = User::query()
            ->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('company_name', 'like', $like);
            })
            ->when(Schema::hasColumn('users', 'is_deleted'), fn ($q) => $q->where('is_deleted', false))
            ->when(Schema::hasColumn('users', 'is_active'), fn ($q) => $q->where('is_active', true))
            ->limit(10)
            ->get(['id', 'name', 'email', 'phone', 'company_name'])
            ->map(fn ($user) => [
                'id' => 'user:' . $user->id,
                'type' => 'user',
                'label' => $user->name . ' — User' . ($user->email ? ' — ' . $user->email : ''),
            ]);

        $employees = Employee::query()
            ->with('designation:id,name')
            ->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone_number', 'like', $like)
                    ->orWhere('staff_id', 'like', $like);
            })
            ->limit(10)
            ->get(['id', 'name', 'email', 'phone_number', 'staff_id', 'designation_id', 'user_id'])
            ->map(fn ($employee) => [
                'id' => 'employee:' . $employee->id,
                'type' => 'employee',
                'label' => $employee->name . ' — Employee' . ($employee->staff_id ? ' — ' . $employee->staff_id : '') . ($employee->designation?->name ? ' — ' . $employee->designation->name : ''),
            ]);

        return response()->json(['results' => $users->concat($employees)->values()]);
    }

    public function personDetails(Request $request)
    {
        $key = (string) $request->query('person');
        [$type, $id] = $this->parsePersonKey($key);
        $company = optional(GeneralSetting::latest('id')->first())->company_name;

        if ($type === 'employee') {
            $employee = Employee::with(['designation:id,name', 'user:id,name,email,phone,company_name'])
                ->findOrFail($id);

            return response()->json([
                'person' => [
                    'linked_user_id' => $employee->user_id,
                    'employee_id' => $employee->id,
                    'name' => $employee->name,
                    'designation' => $employee->designation?->name,
                    'company' => $employee->user?->company_name ?: $company,
                    'phone' => $employee->phone_number ?: $employee->user?->phone,
                    'whatsapp' => $employee->phone_number ?: $employee->user?->phone,
                    'email' => $employee->email ?: $employee->user?->email,
                    'address' => $employee->address,
                ],
            ]);
        }

        $user = User::findOrFail($id);
        $employee = Employee::with('designation:id,name')->where('user_id', $user->id)->first();

        return response()->json([
            'person' => [
                'linked_user_id' => $user->id,
                'employee_id' => $employee?->id,
                'name' => $employee?->name ?: $user->name,
                'designation' => $employee?->designation?->name,
                'company' => $user->company_name ?: $company,
                'phone' => $employee?->phone_number ?: $user->phone,
                'whatsapp' => $employee?->phone_number ?: $user->phone,
                'email' => $employee?->email ?: $user->email,
                'address' => $employee?->address,
            ],
        ]);
    }

    public function checkSlug(Request $request)
    {
        $slug = Str::slug((string) $request->query('slug'));
        $exclude = (int) $request->query('exclude', 0);

        if ($slug === '') {
            return response()->json(['available' => false, 'slug' => '', 'message' => 'Enter a valid slug.']);
        }

        $exists = VCardProfile::query()
            ->where('slug', $slug)
            ->when($exclude > 0, fn ($q) => $q->whereKeyNot($exclude))
            ->exists();

        return response()->json([
            'available' => !$exists,
            'slug' => $slug,
            'message' => $exists ? 'This public URL is already in use.' : 'Available',
        ]);
    }

    private function validateProfile(Request $request, ?VCardProfile $profile = null): array
    {
        $socialLinks = [];
        foreach ((array) $request->input('social_links', []) as $platform => $url) {
            $socialLinks[$platform] = $this->normalizeUrl($url);
        }

        $request->merge([
            'slug' => Str::slug((string) $request->input('slug')),
            'website' => $this->normalizeUrl($request->input('website')),
            'phone' => $this->normalizePhone($request->input('phone')),
            'whatsapp' => $this->normalizePhone($request->input('whatsapp')),
            'social_links' => $socialLinks,
            'is_active' => $request->boolean('is_active'),
        ]);

        $slugRule = Rule::unique('vcard_profiles', 'slug');
        if ($profile) {
            $slugRule->ignore($profile->id);
        }

        $validated = $request->validate([
            'linked_person' => ['nullable', 'string', 'regex:/^(user|employee):[1-9][0-9]*$/'],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slugRule],
            'name' => ['required', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:80'],
            'whatsapp' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'social_links' => ['nullable', 'array'],
            'social_links.*' => ['nullable', 'url', 'max:255'],
        ]);

        // UI-only selector; actual linked ids are resolved server-side from this value.
        unset($validated['linked_person']);

        return $validated;
    }

    private function resolvePersonLink(?string $key): array
    {
        if (!$key) {
            return ['linked_user_id' => null, 'employee_id' => null];
        }

        [$type, $id] = $this->parsePersonKey($key);

        if ($type === 'employee') {
            $employee = Employee::findOrFail($id);
            return ['linked_user_id' => $employee->user_id, 'employee_id' => $employee->id];
        }

        $user = User::findOrFail($id);
        $employee = Employee::where('user_id', $user->id)->first();

        return ['linked_user_id' => $user->id, 'employee_id' => $employee?->id];
    }

    private function parsePersonKey(string $key): array
    {
        if (!preg_match('/^(user|employee):([1-9][0-9]*)$/', $key, $matches)) {
            abort(422, 'Invalid linked person.');
        }

        return [$matches[1], (int) $matches[2]];
    }

    private function linkedPersonSummary(VCardProfile $profile): ?array
    {
        if ($profile->employee) {
            return [
                'id' => 'employee:' . $profile->employee->id,
                'label' => $profile->employee->name . ' — Employee' . ($profile->employee->staff_id ? ' — ' . $profile->employee->staff_id : ''),
            ];
        }

        if ($profile->linkedUser) {
            return [
                'id' => 'user:' . $profile->linkedUser->id,
                'label' => $profile->linkedUser->name . ' — User' . ($profile->linkedUser->email ? ' — ' . $profile->linkedUser->email : ''),
            ];
        }

        return null;
    }

    private function syncSocialLinks(VCardProfile $profile, array $links): void
    {
        $profile->socialLinks()->delete();
        $sort = 0;

        foreach (self::SOCIAL_PLATFORMS as $platform) {
            $url = $this->normalizeUrl($links[$platform] ?? null);
            if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }

            $profile->socialLinks()->create([
                'platform' => $platform,
                'url' => $url,
                'sort_order' => $sort++,
            ]);
        }
    }

    private function normalizeUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        return preg_match('~^https?://~i', $url) ? $url : 'https://' . $url;
    }

    private function normalizePhone(?string $phone): ?string
    {
        $phone = trim((string) $phone);
        if ($phone === '') {
            return null;
        }

        $phone = preg_replace('/[^0-9+]/', '', $phone);
        return preg_replace('/(?!^)\+/', '', $phone);
    }
}
