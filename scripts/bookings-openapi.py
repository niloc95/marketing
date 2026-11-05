"""The online bookings API's OpenAPI file (docs/bookings/openapi.json).

    python3 scripts/bookings-openapi.py docs/bookings/openapi.json

Edit this, not the JSON, then run it. BookingIntegrationsTest checks the file
against the /api/v1 routes and their scopes.
"""
import json, sys

def ref(n): return {"$ref": f"#/components/schemas/{n}"}
def env(schema, meta=None):
    props = {"data": schema, "meta": meta or {"type": "object"}}
    return {"type": "object", "required": ["data", "meta"], "properties": props}
def resp(desc, schema): return {"description": desc, "content": {"application/json": {"schema": schema}}}
ERR = {"401": {"$ref": "#/components/responses/Unauthenticated"}, "403": {"$ref": "#/components/responses/Forbidden"}, "429": {"$ref": "#/components/responses/RateLimited"}}
def errs(*codes):
    out = dict(ERR)
    for c in codes:
        out[str(c)] = {"$ref": {"404": "#/components/responses/NotFound", "422": "#/components/responses/Invalid"}[str(c)]}
    return out
PAGE = {"type": "object", "properties": {"page": {"type": "integer"}, "per_page": {"type": "integer"}, "pages": {"type": "integer"}, "total": {"type": "integer"}}}
def q(name, desc, schema=None, required=False):
    return {"name": name, "in": "query", "required": required, "description": desc, "schema": schema or {"type": "string"}}
ID = {"name": "id", "in": "path", "required": True, "schema": {"type": "integer"}}
def op(opid, summary, scope, desc, responses, params=None, body=None, tag=None):
    o = {"operationId": opid, "summary": summary, "description": desc + f"\n\nScope: `{scope}`.", "tags": [tag], "x-scope": scope, "responses": responses}
    if params: o["parameters"] = params
    if body: o["requestBody"] = {"required": True, "content": {"application/json": {"schema": body}, "application/x-www-form-urlencoded": {"schema": body}}}
    return o

money = {"type": ["object", "null"], "properties": {"amount_cents": {"type": "integer"}, "currency": {"type": "string", "example": "ZAR"}}}
when_body = {"type": "object", "description": "Send `start`, or `date` and `time`.", "properties": {
    "start": {"type": "string", "format": "date-time", "example": "2030-01-07T10:00:00+02:00", "description": "ISO 8601 with an offset."},
    "date": {"type": "string", "format": "date", "description": "In the business's time zone."},
    "time": {"type": "string", "example": "10:00", "description": "In the business's time zone."}}}

schemas = {
  "Error": {"type": "object", "required": ["error"], "properties": {"error": {"type": "object", "required": ["message", "code"], "properties": {
      "message": {"type": "string"}, "code": {"type": "string", "enum": ["unauthenticated", "invalid_key", "bookings_off", "plan_required", "rate_limited", "insufficient_scope", "not_found", "invalid", "refused"]},
      "details": {"type": "object", "properties": {"fields": {"type": "object", "additionalProperties": {"type": "string"}}}}}}}},
  "Money": money,
  "Business": {"type": "object", "properties": {"id": {"type": "integer"}, "name": {"type": "string"}, "profile_url": {"type": "string"}, "booking_url": {"type": "string"},
      "timezone": {"type": "string", "example": "Africa/Johannesburg"}, "currency": {"type": "string"},
      "booking_rules": {"type": "object", "properties": {"slot_step_min": {"type": "integer"}, "minimum_notice_min": {"type": "integer"}, "buffer_min": {"type": "integer"}, "future_limit_days": {"type": "integer"}, "default_status": {"type": "string"}}}}},
  "Service": {"type": "object", "properties": {"id": {"type": "integer"}, "name": {"type": "string"}, "description": {"type": ["string", "null"]}, "duration_min": {"type": "integer"},
      "price": money, "deposit_pct": {"type": "integer"}, "category_id": {"type": ["integer", "null"]},
      "delivery_modes": {"type": "array", "items": {"type": "string", "enum": ["onsite", "online_zoom", "online_jitsi"]}}, "provider_ids": {"type": "array", "items": {"type": "integer"}}}},
  "Category": {"type": "object", "properties": {"id": {"type": "integer"}, "name": {"type": "string"}}},
  "Provider": {"type": "object", "description": "A team member who can be booked.", "properties": {"id": {"type": "integer"}, "name": {"type": "string"}, "title": {"type": ["string", "null"]}, "bookable": {"type": "boolean"}, "service_ids": {"type": "array", "items": {"type": "integer"}, "description": "Only on GET /providers/{id}."}}},
  "Location": {"type": "object", "properties": {"id": {"type": ["integer", "null"], "description": "null is the profile's main address."}, "name": {"type": "string"}, "main": {"type": "boolean"}}},
  "Slot": {"type": "object", "properties": {"start": {"type": "string", "format": "date-time"}, "end": {"type": "string", "format": "date-time"}, "local_time": {"type": "string", "example": "10:00"}, "provider_ids": {"type": "array", "items": {"type": "integer"}}}},
  "CustomerSummary": {"type": "object", "properties": {"id": {"type": "integer"}, "first_name": {"type": "string"}, "last_name": {"type": "string"}, "email": {"type": ["string", "null"]}, "phone": {"type": ["string", "null"]}}},
  "Customer": {"allOf": [ref("CustomerSummary"), {"type": "object", "properties": {"address": {"type": ["string", "null"]}, "notes": {"type": ["string", "null"]}, "created_at": {"type": "string", "format": "date-time"}, "updated_at": {"type": "string", "format": "date-time"}}}]},
  "CustomerInput": {"type": "object", "properties": {k: {"type": "string"} for k in ["first_name", "last_name", "email", "phone", "address", "notes"]}},
  "Appointment": {"type": "object", "properties": {
      "id": {"type": "integer"}, "reference": {"type": "string", "example": "WSB-BOOK-000123"},
      "status": {"type": "string", "enum": ["pending", "confirmed", "completed", "cancelled", "no_show"]},
      "start": {"type": "string", "format": "date-time", "description": "UTC."}, "end": {"type": "string", "format": "date-time", "description": "UTC."},
      "local_start": {"type": "string", "format": "date-time"}, "local_end": {"type": "string", "format": "date-time"}, "timezone": {"type": "string"},
      "service": {"type": "object", "properties": {"id": {"type": "integer"}, "name": {"type": "string"}, "duration_min": {"type": "integer"}}},
      "provider": {"type": "object", "properties": {"id": {"type": "integer"}, "name": {"type": "string"}}},
      "customer": {"oneOf": [ref("CustomerSummary"), {"type": "null"}]},
      "location": {"type": "object", "properties": {"id": {"type": ["integer", "null"]}, "name": {"type": "string"}, "address": {"type": "string"}}},
      "delivery_mode": {"type": "string", "enum": ["onsite", "online_zoom", "online_jitsi"]}, "video_link": {"type": ["string", "null"]},
      "price": money, "deposit": {"type": ["object", "null"], "properties": {"status": {"type": "string", "enum": ["due", "paid", "refunded", "expired", "refund_due"]}, "amount_cents": {"type": "integer"}, "currency": {"type": "string"}}},
      "notes": {"type": ["string", "null"]}, "source": {"type": "string", "enum": ["business", "public", "api"]},
      "created_at": {"type": "string", "format": "date-time"}, "updated_at": {"type": "string", "format": "date-time"}}},
  "AppointmentInput": {"allOf": [when_body, {"type": "object", "required": ["service_id", "provider_id"], "properties": {
      "service_id": {"type": "integer"}, "provider_id": {"type": "integer"},
      "customer_id": {"type": "integer", "description": "An existing customer. Or send customer."},
      "customer": ref("CustomerInput"),
      "status": {"type": "string", "enum": ["pending", "confirmed"], "description": "Confirmed when left out."},
      "delivery_mode": {"type": "string", "enum": ["onsite", "online_zoom", "online_jitsi"], "description": "One the service offers. The service's first when left out."},
      "notes": {"type": "string"}}}]},
  "WebhookEvent": {"type": "object", "properties": {
      "id": {"type": "string", "example": "evt_5f2b9c0e7d1a4b3c8e6f0a12"},
      "type": {"type": "string", "enum": ["appointment.created", "appointment.rescheduled", "appointment.confirmed", "appointment.pending", "appointment.completed", "appointment.no_show", "appointment.cancelled", "deposit.paid", "ping"]},
      "created": {"type": "string", "format": "date-time"},
      "business": {"type": "object", "properties": {"id": {"type": "integer"}, "name": {"type": "string"}}},
      "data": {"type": "object", "properties": {"appointment": ref("Appointment"), "payment": {"type": "object", "description": "Only on deposit.paid.", "properties": {"reference": {"type": "string"}, "gateway": {"type": "string"}, "amount_cents": {"type": "integer"}, "currency": {"type": "string"}, "test": {"type": "boolean"}}}}}}},
}

paths = {
  "/business": {"get": op("getBusiness", "The business", "catalogue.read", "The business the key belongs to, its time zone and booking rules.", {"200": resp("The business", env(ref("Business"))), **ERR}, tag="Business")},
  "/services": {"get": op("listServices", "Services", "catalogue.read", "Services that can be booked, archived ones left out.", {"200": resp("Services", env({"type": "array", "items": ref("Service")})), **ERR}, tag="Business")},
  "/services/{id}": {"get": op("getService", "One service", "catalogue.read", "One service.", {"200": resp("The service", env(ref("Service"))), **errs(404)}, [ID], tag="Business")},
  "/categories": {"get": op("listCategories", "Service categories", "catalogue.read", "Categories of services.", {"200": resp("Categories", env({"type": "array", "items": ref("Category")})), **ERR}, tag="Business")},
  "/providers": {"get": op("listProviders", "Team members", "catalogue.read", "People who can be booked. A key of a team member or reception sees only the people they work with.", {"200": resp("Team members", env({"type": "array", "items": ref("Provider")})), **ERR}, tag="Business")},
  "/providers/{id}": {"get": op("getProvider", "One team member", "catalogue.read", "One team member, with the services they offer.", {"200": resp("The team member", env(ref("Provider"))), **errs(404)}, [ID], tag="Business")},
  "/locations": {"get": op("listLocations", "Locations", "catalogue.read", "The profile's main address and its branches.", {"200": resp("Locations", env({"type": "array", "items": ref("Location")})), **ERR}, tag="Business")},
  "/availability": {"get": op("getAvailability", "Free times", "availability.read", "Free start times for a service on one day. Leave provider_id out for anyone (admin keys only).", {"200": resp("Free times", env({"type": "array", "items": ref("Slot")}, {"type": "object", "properties": {"date": {"type": "string"}, "timezone": {"type": "string"}, "as": {"type": "string"}}})), **errs(404, 422)},
      [q("service_id", "The service.", {"type": "integer"}, True), q("date", "YYYY-MM-DD in the business's time zone.", {"type": "string", "format": "date"}, True), q("provider_id", "One team member.", {"type": "integer"}),
       q("as", "customer (default): the rules customers book under. business: every free minute in working time.", {"type": "string", "enum": ["customer", "business"]})], tag="Availability")},
  "/appointments": {
      "get": op("listAppointments", "Appointments", "appointments.read", "Appointments, earliest first. A key of a team member or reception sees only the people they work with.", {"200": resp("Appointments", env({"type": "array", "items": ref("Appointment")}, PAGE)), **errs(422)},
          [q("from", "YYYY-MM-DD (business time) or ISO 8601: appointments ending after it."), q("to", "YYYY-MM-DD (to the end of that day) or ISO 8601: appointments starting before it."),
           q("updated_since", "ISO 8601: only appointments changed since then. Handy to keep a copy up to date."), q("status", "One or more, comma separated."),
           q("provider_id", "", {"type": "integer"}), q("customer_id", "", {"type": "integer"}), q("page", "", {"type": "integer", "minimum": 1}), q("per_page", "Up to 100, 25 when left out.", {"type": "integer", "minimum": 1, "maximum": 100})], tag="Appointments"),
      "post": op("createAppointment", "Book an appointment", "appointments.write", "Book as the business does from its calendar: any free time in working hours. The same checks, messages and webhooks as a booking made in the calendar; source is api.", {"201": resp("Booked", env(ref("Appointment"))), **errs(422)}, body=ref("AppointmentInput"), tag="Appointments")},
  "/appointments/{id}": {"get": op("getAppointment", "One appointment", "appointments.read", "One appointment.", {"200": resp("The appointment", env(ref("Appointment"))), **errs(404)}, [ID], tag="Appointments")},
  "/appointments/{id}/reschedule": {"post": op("rescheduleAppointment", "Move an appointment", "appointments.write", "A new time, and optionally a new team member. Upcoming appointments only.", {"200": resp("Moved", env(ref("Appointment"))), **errs(404, 422)}, [ID],
      {"allOf": [when_body, {"type": "object", "properties": {"provider_id": {"type": "integer"}}}]}, tag="Appointments")},
  "/appointments/{id}/status": {"post": op("setAppointmentStatus", "Change the status", "appointments.write", "Confirm, cancel, mark completed or no show.", {"200": resp("Changed", env(ref("Appointment"))), **errs(404, 422)}, [ID],
      {"type": "object", "required": ["status"], "properties": {"status": {"type": "string", "enum": ["pending", "confirmed", "completed", "cancelled", "no_show"]}}}, tag="Appointments")},
  "/appointments/{id}/notes": {"post": op("setAppointmentNotes", "Change the notes", "appointments.write", "The business's notes on the appointment.", {"200": resp("Saved", env(ref("Appointment"))), **errs(404, 422)}, [ID],
      {"type": "object", "properties": {"notes": {"type": ["string", "null"]}}}, tag="Appointments")},
  "/customers": {
      "get": op("listCustomers", "Customers", "customers.read", "Customers, newest first.", {"200": resp("Customers", env({"type": "array", "items": ref("Customer")}, PAGE)), **ERR},
          [q("q", "Name, email or phone."), q("page", "", {"type": "integer"}), q("per_page", "Up to 100.", {"type": "integer"})], tag="Customers"),
      "post": op("createCustomer", "Add a customer", "customers.write", "first_name is required. An email may belong to one customer only.", {"201": resp("Added", env(ref("Customer"))), **errs(422)}, body=ref("CustomerInput"), tag="Customers")},
  "/customers/{id}": {
      "get": op("getCustomer", "One customer", "customers.read", "One customer.", {"200": resp("The customer", env(ref("Customer"))), **errs(404)}, [ID], tag="Customers"),
      "post": op("updateCustomer", "Change a customer", "customers.write", "Only the fields sent change.", {"200": resp("Changed", env(ref("Customer"))), **errs(404, 422)}, [ID], ref("CustomerInput"), tag="Customers")},
}

def errresp(d): return {"description": d, "content": {"application/json": {"schema": ref("Error")}}}
spec = {
  "openapi": "3.1.0",
  "info": {"title": "WebScheduler Local online bookings API", "version": "1.0.0",
      "description": "Read and book a business's appointments from your own programs. Each key belongs to one business and acts as the person who made it."},
  "servers": [{"url": "https://local.webscheduler.co.za/api/v1"}],
  "security": [{"apiKey": []}],
  "tags": [{"name": n} for n in ["Business", "Availability", "Appointments", "Customers"]],
  "paths": paths,
  "webhooks": {"bookingEvent": {"post": {"summary": "Something happened to a booking", "description": "Sent to the business's webhook address. Check the WebScheduler-Signature header before trusting it.",
      "parameters": [{"name": "WebScheduler-Signature", "in": "header", "required": True, "schema": {"type": "string", "example": "t=1893484800,v1=5d41402abc4b2a76b9719d911017c592"}},
                     {"name": "WebScheduler-Event", "in": "header", "required": True, "schema": {"type": "string"}},
                     {"name": "WebScheduler-Delivery", "in": "header", "required": True, "schema": {"type": "string"}}],
      "requestBody": {"content": {"application/json": {"schema": ref("WebhookEvent")}}},
      "responses": {"200": {"description": "Any 2xx answer means received. Anything else, or no answer in 10 seconds, is tried again after 1, 5, 30, 120 and 360 minutes."}}}}},
  "components": {
      "securitySchemes": {"apiKey": {"type": "http", "scheme": "bearer", "description": "Authorization: Bearer wsk_..."}},
      "schemas": schemas,
      "responses": {
          "Unauthenticated": errresp("No key, or a key that is unknown, revoked or expired."),
          "Forbidden": errresp("The key lacks the scope, its person's role does not allow it, or the business no longer takes bookings (bookings_off) or its plan has ended (plan_required)."),
          "RateLimited": errresp("More than 120 requests a minute. Wait for Retry-After seconds."),
          "NotFound": errresp("Not found, or not in this key's reach."),
          "Invalid": errresp("Something sent is missing or wrong; details.fields says what."),
      }},
}
json.dump(spec, open(sys.argv[1], "w"), indent=2)
print(len(paths))
