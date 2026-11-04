# Copy

Local's copy rules (CLAUDE.md, listing-service skill) apply to every string a customer or
business owner reads: pages, emails, SMS, WhatsApp templates, JS messages, error text,
the dashboard. Bookings' own wording is **rewritten, not carried over**. Code identifiers
(routes, classes, keys such as `no_show`) stay as they are.

## Hard rules

- No "—" and no hyphenated words. Proper names such as KwaZulu-Natal are fine.
- "Business profile", never "listing" or "directory".
- Our discovery feature is **Discover**, never "Search". Inside the dashboard, finding
  your own customers or appointments is "Find" ("Find a customer").
- On .app: no rand figures, and nothing that says "built for South Africa".
- Nothing may suggest that taking bookings, paying for anything, or having more bookings
  moves a business up in discovery results.

## Words

| Use | Not |
|---|---|
| Book online, Book now | Schedule, Reserve |
| booking (what the customer makes), appointment (in the business calendar) | session, slot booking |
| time (on screen: "Choose a time") | slot |
| team member, or the person's name | provider (internal word only) |
| reception, assistant | staff (in UI copy; the role key stays `staff`) |
| time off | blocked time, block-out |
| no show | no-show |
| sign in, sign out | log in, log-in, login (as a verb) |
| email | e-mail |
| online (video) | virtual, tele-health |
| deposit | down payment, pre-payment |
| reschedule, change your booking | re-schedule |
| reminder | follow-up |
| set up (verb), setup (noun) | set-up |
| day to day, one off | day-to-day, one-off |

## Statuses (labels)

| Key | Label | Customer sees |
|---|---|---|
| `pending` | Waiting for confirmation | "We have your request. {Business} will confirm it." |
| `confirmed` | Confirmed | "Your booking is confirmed." |
| `completed` | Completed | (no message) |
| `cancelled` | Cancelled | "Your booking is cancelled." |
| `no_show` | No show | (no message by default) |

## Email subjects (customer)

| Event | Subject |
|---|---|
| request received (pending) | Your booking request with {Business} |
| confirmed | Confirmed: {Service} with {Business} on {Day date} at {time} |
| rescheduled | New time: {Service} with {Business} on {Day date} at {time} |
| cancelled | Cancelled: {Service} with {Business} on {Day date} |
| reminder | Reminder: {Service} with {Business} {tomorrow or on Day date} at {time} |
| payment received | Deposit received for your booking with {Business} |

Every customer email:

- is sent by WebScheduler Local on behalf of the business, with Reply-To set to the business
- carries the business name, address (if public), the "change or cancel" link and an
  unsubscribe link for reminders
- has times in the business's own timezone, with the zone named when it differs from
  the customer's site

## Rewrites of Bookings strings found so far

| Bookings | Local |
|---|---|
| "Book an appointment" | "Book online" |
| "Select a slot" | "Choose a time" |
| "Any provider" | "Anyone available" |
| "No slots available" | "No times left on this day. Try another day." |
| "Appointment booked successfully" | "Booked. We sent the details to {email}." |
| "Your session will expire" | "You will be signed out soon" |
| "Log Out" | "Sign out" |
| "Stay Logged In" | "Stay signed in" |
| "Customer Management" | "Customers" |
| "User Management" | "Team" |
| "Blocked periods" | "Closed days" |
| "Provider schedule" | "Working hours" |
| "Self-service" | "Change or cancel online" |

The full list is built in each phase as screens are ported. A test in Phase 1
(`BookingCopyTest`) scans `app/Views/business/`, `app/Views/emails/booking-*` and the
dashboard bundle's message file for "—", hyphenated words outside an allow list, and
"Search", like the checks Local already runs on its public copy.
