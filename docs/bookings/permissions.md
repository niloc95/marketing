# Roles, permissions and scoping

## How a request finds its business

- Business pages live under `/business`. The signed in account picks a business. If it
  belongs to more than one, a switcher is shown. The chosen `listing_id` goes into the
  session. **Every request re checks** that the account holds an active membership for
  that business before the controller runs (`businessauth` filter), so a stale session
  cannot reach a business after removal.
- API requests carry a key bound to one membership. The key decides the business, and a
  `listing_id` parameter can never widen it.
- Public booking pages find the business from the profile slug in the URL. They only show
  it when the profile is published, `bookings_enabled = 1`, the global flag is on, and the
  profile belongs to the site serving the request.

## Roles (per business)

| Role | Who |
|---|---|
| `admin` | the owner who turned bookings on, plus anyone they make admin |
| `provider` | a person customers book (tied to an `xs_booking_providers` row) |
| `staff` | reception or assistants who book for assigned providers |

One account can hold one role per business, and different roles in different businesses.
Bookings' "multi role" (admin + provider at once) becomes: an `admin` member who is also
linked to a provider row. Booking scope follows the provider link, and permissions follow
the role.

## Permission matrix

Ported from Bookings `UserPermissionModel::ROLE_PERMISSIONS`, extended for areas Bookings
gated only by route. It lives in `Config\BookingPermissions`.

| Permission | admin | provider | staff |
|---|---|---|---|
| business settings, notification rules, templates, integrations | ✓ | | |
| manage members (invite, roles, remove) | ✓ | staff only | |
| manage services and categories | ✓ | ✓ | |
| manage providers, hours, blocked times | ✓ | own | |
| view all appointments and calendars | ✓ | | |
| view own calendar and appointments | ✓ | ✓ | |
| view assigned providers' calendars | ✓ | | ✓ |
| book, reschedule, cancel appointments | ✓ | own | assigned |
| manage customers | ✓ | own customers | assigned providers' customers |
| delete customers | ✓ | | |
| analytics | ✓ | own | |
| payments: view, mark paid | ✓ | own | |
| API keys | ✓ | | |
| audit log | ✓ | | |
| turn bookings off | ✓ | | |

"Own" means rows where `provider_id` is the member's provider. "Assigned" means providers
in `xs_booking_staff_assignments` with status active. "Own customers" means customers with
an appointment for that provider (Bookings' `CustomerAppointmentService` rule).

## Where it is enforced

- `BookingAuthorization` service: `can($member, $permission)`, `providerScope($member)`
  (null = all, otherwise a list of ids), `canManageAppointment($member, $appointment)`.
  This is the one place, ported from Bookings' `AuthorizationService` and
  `CustomerAppointmentService`.
- `businessrole:admin,provider` route filter for coarse gates. Services check the fine ones
  again, so an API route and a page route cannot disagree.
- Calendar, search, exports and analytics take `providerScope()` and never trust a
  `provider_id` from the request (Bookings' `CalendarController` rule).

## Relation to Local's own access

- `/manage` (owner magic link) stays for profile editing. "Turn on bookings" there creates
  the owner's account and an `admin` membership. Later, `/manage` becomes a section of
  `/business`.
- Platform admin (`/admin`, Cloudflare Access + password) can see every business's booking
  setup and switch bookings off. Customer data and appointment contents are not shown by
  default (decision D12). It never signs in as a member.
