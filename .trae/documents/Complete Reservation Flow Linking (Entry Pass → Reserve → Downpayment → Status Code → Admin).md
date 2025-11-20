## Overview
Implement a seamless reservation flow for visitors that preserves inputs, validates before proceeding, and finalizes payment and data in the database so the admin panel receives complete records.

## Pages Involved
1. Entry Pass form (`mainpage.php`) → redirects with `entry_pass_id`
2. Reserve page (`reserve.php`) → validates, persists, and routes to downpayment
3. Downpayment page (`downpayment.php`) → shows breakdown, confirms payment
4. Status & Admin (`mainpage.php`, `admin.php`) → user toast + admin review

## Implementation Steps

### 1) Go Back Behavior (Reserve)
- Persist all fields to `sessionStorage` on every input change.
- Restore persisted values on page load and re-select amenity/time UI state.
- Keep `Go Back` using `history.back()` or fall back to `mainpage.php` without clearing inputs.
- JS functions: `persistForm()`, `restoreFormFromSession()`, `goBack()` in `reserve.php`.

### 2) Next Navigation (Reserve → Downpayment)
- Change the main form button label to `Next`.
- Client validation gates Next (amenity, dates, times, hours/persons, non-overlap).
- On successful POST:
  - Save reservation details to `$_SESSION['pending_reservation']` (amenity, dates, times, persons/hours, price, downpayment, `user_id`, `entry_pass_id`).
  - Redirect to `downpayment.php?continue=reserve` (include `entry_pass_id` and `ref_code` if available).
- Add amenity guard so booked dates/times only load after an amenity is selected both client-side and server-side.

### 3) Downpayment Page Behavior
- Read `$_SESSION['pending_reservation']` for data.
- Show full payment breakdown:
  - Hours for Clubhouse/Basketball/Tennis (compute from `start_time → end_time` when same-day; fallback to 1 hour for display).
  - Persons for Pool.
- Replace old button with `Confirm Payment`.
- On POST `Confirm Payment`:
  - Ensure a `ref_code`; generate if missing.
  - Update or insert into `reservations` with amenity, dates, times, persons, price, downpayment, `user_id`, `entry_pass_id`.
  - Set `payment_status='verified'` and `approval_status='pending'`.
  - Clear `$_SESSION['pending_reservation']`.
  - Set flash notice and `ref_code`, redirect to `mainpage.php`.

### 4) Status Code Notification (Main Page)
- Render toast: “Please wait for your status code SMS.” and include `ref_code` if present from flash in `mainpage.php`.

### 5) Database Upload & Admin Intake
- Ensure `reservations` has required columns: `start_time`, `end_time`, `downpayment`, `entry_pass_id` (create if missing via defensive migration helpers).
- Admin panel relies on `approval_status` and `payment_status`; the record appears as pending for review with complete details.

### 6) Validation & Guards
- Client: Disable Next until fields valid; prevent overlap submission.
- Server: Validate date/time ranges and conflicts; amenity guard for availability endpoints.

### 7) Data Integrity Between Steps
- Entry Pass → Reserve: Pass `entry_pass_id` in URL; link personal info via table.
- Reserve → Downpayment: Use `$_SESSION['pending_reservation]` to avoid data loss.
- Downpayment → Admin/Status: Persist complete reservation to DB; show user toast and make record visible in admin.

## Minimal Changes Principle
- Only edit code directly related to the flow: button labels, redirects, session handoff, breakdown rendering, DB update/insert, amenity guards.
- Do not modify unrelated UI/features.

## Verification
- Manual run-through:
  - Submit Entry Pass → observe redirect to `reserve.php?entry_pass_id=...`.
  - Select amenity, dates, times, persons/hours; click Next → redirect to `downpayment.php`.
  - Verify breakdown and ref code; click Confirm Payment → redirect to main page with toast.
  - Check admin panel for a pending reservation record with complete details.
- Edge cases: missing amenity blocks availability; overlap detection prevents submission; time outside operating hours blocked; ref code generated if absent.
