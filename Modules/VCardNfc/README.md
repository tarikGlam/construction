# SalePro vCard + NFC MVP

## Included
- Digital vCard profile CRUD
- Photo, contact details, short bio and social links
- Public mobile-friendly profile URL
- Downloadable VCF contact
- QR code SVG generation using SalePro's existing `simplesoftwareio/simple-qrcode` dependency
- Permanent NFC token URL with profile assignment/reassignment and enable/disable
- Basic counters: profile views, NFC taps, QR scans, contact saves
- SaaS tenancy middleware compatibility
- Admin/owner-only management routes (role_id <= 2); public card routes remain public

## Install / upgrade
1. Keep the module enabled in `modules_statuses.json` (`VCardNfc: true`).
2. Run normal SalePro migrations (`php artisan migrate`).
3. Clear application caches (`php artisan optimize:clear`) after deploying code.
4. Ensure the existing public storage link is present (`public/storage -> storage/app/public`) for profile photos.
5. Open **vCard / NFC** from the sidebar.

## NFC usage
Create an NFC card record in SalePro, then write the generated `/n/{token}` URL to the physical NFC card/tag using any standard NFC writing app. The physical card does not need to be rewritten when the SalePro profile assignment changes.

## Deliberately excluded from MVP
Leads/CRM, products/services, card ordering, payment links, custom domains, page builder/themes, staff sales attribution, advanced analytics, geolocation tracking.

## Schema naming convention

The module deliberately uses `vcard_*` table names and the foreign key `vcard_profile_id`.
Because Laravel would otherwise infer names such as `v_card_profiles` and `v_card_profile_id`
from the `VCard*` PHP class names, every entity now declares its table explicitly and every
relationship declares its foreign key explicitly. Do not change these relationships back to
implicit Eloquent defaults.

## SalePro person linking (MVP refinement)

Profiles can optionally link to an existing SalePro User and/or HR Employee. The linked-person selector searches both sources over AJAX. Selecting a person copies available name, designation, company, phone, WhatsApp, email, and address into the vCard form while retaining an independent profile snapshot that can be edited before save.

The profile keeps `linked_user_id` and `employee_id` separately. The pre-existing `user_id` remains the profile creator for backward compatibility.

The public slug is generated from the name in the browser, remains editable, is checked over AJAX for availability, and is still protected by server-side validation plus the database unique index.

Phone and WhatsApp fields use the same `intl-tel-input` approach already used by SalePro customer forms and are stored in normalized international form when the browser library can resolve the country code.

### Upgrade

For an installation that already ran the original vCard/NFC migrations, run:

```bash
php artisan migrate
php artisan optimize:clear
```

The `2026_09_26_000005_add_person_links_to_vcard_profiles_table.php` migration adds the optional SalePro person references.
