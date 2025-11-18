## Goals
- Make admin approvals/denials work consistently for all request types (guest_forms, reservations, resident_reservations).
- After approval, provide a visible “View QR” button that opens the QR card for the approved ref_code.
- Provide a “View Details” action that shows complete reservation information (amenity, dates, persons, price, status, approval timestamps).
- Keep existing backend logic and security, adding only the missing UI wiring and safe guards.

## Backend Verification (No breaking changes)
- Reuse existing handlers already present in admin.php:
  - Approvals/denials: `approve_request/deny_request`, `approve_reservation/reject_reservation`, `approve_resident_reservation/deny_resident_reservation`.
  - QR generation: `generateQrForGuestForm`, `generateQrForReservation`, `generateQrForResidentReservation`.
  - Ensure column existence functions are called (QR columns ensured for all tables).
- Confirm `status.php` and `qr_view.php` already render approved passes by `ref_code`.

## UI Wiring in admin.php
- Requests/Visitor Requests page
  - Add an “Actions” column with two buttons:
    - Approve: POST to admin.php with `action=approve_request` and the request id; on success refreshes list.
    - Deny: POST to admin.php with `action=deny_request` and the request id.
  - Add “View QR” button that is enabled only when the request is approved (uses guest_forms.ref_code); opens `qr_view.php?code=<ref_code>`.
  - Add “View Details” button; calls existing JS detail builder (get_visitor_details) and opens modal.
- Reservations page
  - Add Approve/Reject buttons: POST to `approve_reservation/reject_reservation` with reservation id; after approval the QR is generated.
  - Add “View QR” button enabled for approved records (reservations.ref_code).
  - Add “View Details” button that calls `showReservationDetails(id)` (already in admin.php) and opens the details modal.
- Resident Reservations page
  - Add Approve/Deny buttons: POST to `approve_resident_reservation/deny_resident_reservation`.
  - Add “View QR” button enabled for approved records (resident_reservations.ref_code).
  - Add “View Details” button reusing resident reservation details block.

## Details Modal Integration
- Use existing admin.php JS that builds details HTML (name, contact, house number, amenity, persons, price, start/end date, created_at, approval_date).
- Ensure the modal opens with the right data source:
  - Visitor details via `get_visitor_details` (guest_forms first, fallback to reservations+entry_passes).
  - Reservation details via a current admin.php data set or a small GET endpoint if missing.

## Guard/QR Flow Confirmation
- Approved items automatically have QR generated and stored in the table’s `qr_path`.
- “View QR” in admin opens the same QR card as the guard/status pages (`qr_view.php?code=<ref_code>`), ensuring consistent verification.

## Search and Cleanliness
- Search bar in admin already filters visible tables.
- Keep the “Clean Sample Data” action to purge sample rows while retaining `houses`.

## Testing Plan
- Approve a visitor request → verify QR is generated and “View QR” opens qr_view with status Approved.
- Deny a visitor request → status updates; “View QR” stays disabled.
- Approve legacy reservation → QR generated and viewable.
- Approve resident reservation → QR generated and viewable.
- Open “View Details” on each item type to verify amenity, dates, persons, price, and approval timestamps.

## Deliverables
- Updated admin.php with Action buttons and QR/Details buttons wired for each listing.
- No changes to existing backend logic besides UI hooks; QR generation remains centralized.
- Verified end-to-end approval → QR visibility → details viewing.

If this plan looks good, I’ll implement the UI hooks in admin.php, wire the details modal calls, and verify all flows end-to-end.