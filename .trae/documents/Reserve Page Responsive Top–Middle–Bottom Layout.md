## Overview
- Rework the reserve page to stack sections in the exact order: Amenities (with description placeholder) → Calendar → Reservation form.
- Change only HTML structure and CSS; keep all JS logic, PHP, DB operations, validations, and calculations untouched.
- Improve spacing, padding, and alignment for clean presentation across mobile, tablet, laptop, and full-width desktop.

## HTML Structure Changes
- Create a single main container that stacks three blocks:
  1. Amenities block: includes the top “Reserve Amenity” description area and the 4 amenity cards list.
  2. Calendar block: the existing calendar markup moved into its own block below amenities.
  3. Reservation block: the existing reservation card and form (date/time, persons, price, submit).
- Remove any default selected amenity in the markup so the description shows a placeholder until user selects.
- Preserve IDs/classes referenced by JS (e.g., `amenitiesList`, `amenityField`, `calendar-body`, `startDateInput`, `endDateInput`, etc.) so event listeners and AJAX keep working.

## CSS Layout & Spacing
- Convert `.layout` to a single-column grid with generous `gap`.
- Define a responsive container `max-width` (e.g., 1400px) centered with consistent side padding; ensure `min-width: 0` on children to prevent overflow.
- Amenities list:
  - Mobile: 1 column.
  - ≥1024px: 2 columns within the amenities block; card sizes and images scale proportionally.
- Calendar block:
  - Full-width card with consistent padding and rounded corners; maintain header controls alignment.
- Reservation block:
  - Keep current card styling; ensure inputs wrap nicely; increase internal `gap` on wider screens to avoid cramping.
- Top description area:
  - Placeholder text visible when no selection; on selection, show amenity name and description.
  - Display amenity image responsively next to text; use existing approach without altering JS logic.
- Spacing refinements:
  - Increase vertical spacing between the three blocks at large widths.
  - Adjust internal padding for cards to avoid cramped appearance on wide screens.

## Responsiveness Rules
- Mobile (≤768px): stack all blocks vertically; amenities list 1 column; calendar and reservation full-width.
- Tablet (769–1023px): still stacked vertically; increase card widths and spacing slightly.
- Laptop/Desktop (≥1024px): maintain the top→middle→bottom stacking; widen left/right padding; amenities list 2 columns; calendar and reservation blocks use full content width without overlapping.
- Ultra-wide (≥1440px): further increase container gaps and left block width to maintain visual balance.

## Behavior Preservation
- Do not change or rebind any event listeners; keep `selectAmenityByKey`, availability calculations, time overlap checks, and AJAX endpoints exactly as-is.
- Hidden form fields and submission gating remain unchanged.

## Accessibility & Visual Feedback
- Keep the selected amenity card highlight styling intact.
- Ensure keyboard selection support remains via existing handlers.

## Verification
- Visual check across breakpoints: mobile, tablet, laptop, 1440px+ desktop.
- Confirm no overlaps, clipping, or horizontal scroll; cards and inputs remain readable.
- Smoke test JS-driven flows: amenity selection updates description; calendar renders; availability pill updates; form enables only when valid.

## Deliverables
- Updated `reserve.php` HTML structure to three stacked blocks with no JS/PHP changes.
- Updated CSS inside `reserve.php` to implement the responsive grid, spacing, and description area display.
- No changes to backend logic, DB operations, validations, or calculations.