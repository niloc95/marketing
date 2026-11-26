"""
WebScheduler Local: online bookings for your practice. The copy, in one place.

Rendered by ../playbook/build_playbook.py's build_pdf (see build_bookings_guide.py),
so it shares the playbook's type, palette, badge and page guards, like the profile
guide and the Verified brochure.

A public guide for practices (medical first), published on /resources. It follows
one fictional patient, Thabo Nkosi, at one fictional practice, Fernwood Dental
Studio (`php spark demo:fernwood`, is_demo): from the profile to a booking, and
back months later as a returning patient. Every screenshot is of that demo.

Every limit and rule here is one the app enforces, written against the code on
26 Nov 2026:

  who can book       BookingSetupService::whyNot(), listing_has_premium()  (Verified)
  booking rules      Config\\Bookings $settingDefaults / $settingRanges
  place columns      FreeTimesBoard, docs/bookings/decisions.md T33
  returning patient  ReturningPatientService (T37): CODE_MINUTES, LINK_MINUTES
  reminders          Config\\BookingNotifications, notifications.reminder_primary
  questions          Config\\Bookings::CUSTOM_FIELDS, CUSTOM_FIELD_TYPES
  team               TeamService::MAX_PROVIDERS, T36 (reception, notices)
  services           CatalogueService::MAX_SERVICES, MAX_DURATION
  deposits           Payments\\DepositService, payments.hold_minutes

When one of those changes, change the constant below and rebuild.

Copy rules (CLAUDE.md): "practice or business", "patients", "online bookings",
"Book online"; Discover, never "Search", for our own feature; never "listing";
no long dashes and no hyphenated words. Bookings is never free: it comes with
Verified. Paying never moves a profile up in discovery.
"""

from pathlib import Path

EDITION = "November 2026"

DOC_TITLE = "WebScheduler Local: Online Bookings for Your Practice"
DOC_AUTHOR = "WebScheduler (Pty) Ltd"
DOC_SUBJECT = ("How patients book a practice online on WebScheduler Local, from the profile "
               "to the appointment, how returning patients book again with one emailed code, "
               "and what the practice gets: calendar, team, reminders and patient records")
DOC_KEYWORDS = ("WebScheduler Local, online bookings, medical practice, dentist, doctor, "
                "appointments, returning patients, reminders, Verified, South Africa")

RUNNING_FOOTER = "WEBSCHEDULER LOCAL · ONLINE BOOKINGS FOR YOUR PRACTICE"

HOST = "local.webscheduler.co.za"
ADD_URL = f"https://{HOST}/add-profile"
DEMO = f"{HOST}/directory/fernwood-dental-studio"
VERTICALS = {"general": {"audience": None}}

# Prices and limits, as the code and the live /pricing.json have them today.
PRICE = "R60"                # /pricing.json za.verified.display (26 Nov 2026)
YEARLY_PRICE = "R625"        # /pricing.json za.verified.displayYearly
HORIZON_DAYS = 60            # business.future_limit default, 1 to 730
CHANGE_HOURS = 24            # business.reschedule and business.cancel defaults
REMINDER_HOURS = 24          # notifications.reminder_primary 1440 minutes
CODE_MINUTES = 5             # ReturningPatientService::CODE_MINUTES
LINK_MINUTES = 10            # ReturningPatientService::LINK_MINUTES
PROVEN_MINUTES = 30          # ReturningPatientService::VERIFIED_MINUTES
QUESTIONS = 6                # Config\Bookings::CUSTOM_FIELDS
PEOPLE = 50                  # TeamService::MAX_PROVIDERS
SERVICES = 100               # CatalogueService::MAX_SERVICES
LONGEST_HOURS = 12           # CatalogueService::MAX_DURATION 720 minutes
DEPOSIT_HOLD = 30            # payments.hold_minutes default

SHOTS = Path(__file__).resolve().parent / "shots"


def shot(name: str, caption: str = "") -> dict:
    return {"path": str(SHOTS / f"{name}.webp"), "caption": caption}


def cta_url(source: str, medium: str) -> str:
    return f"{ADD_URL}?utm_source={source}&utm_medium=bookings-guide&utm_campaign=bookings-guide-2026"


_PAGES = [
    # --- 1. cover -----------------------------------------------------------------
    {
        "id": "cover",
        "cover": True,
        "blocks": [
            {"kind": "lockup", "size_mm": 15},
            {"kind": "gap", "mm": 14},
            {"kind": "eyebrow", "text": "Bookings guide"},
            {"kind": "cover_title", "text": "Patients book you.<br/>[[Right on your profile.]]"},
            {"kind": "cover_sub",
             "text": "Online bookings for your practice, step by step: how a patient finds you, "
                     "picks a time and comes back, and the calendar, reminders and patient "
                     "records your team gets. Included with Verified."},
            {"kind": "shots", "max_h_mm": 92, "items": [shot("profile")]},
            {"kind": "small", "text": "<b>Inside:</b>"},
            {"kind": "small",
             "text": "The patient journey · choosing a service and a person · times by place · "
                     "your details · booked · confirmation and reminders · returning patients · "
                     "your calendar · your team · services and hours · patient records · "
                     "getting started"},
            {"kind": "small", "text": f"WebScheduler (Pty) Ltd · South Africa · {EDITION}"},
        ],
    },

    # --- 2. the journey -------------------------------------------------------------
    {
        "id": "journey",
        "blocks": [
            {"kind": "eyebrow", "text": "01 · The patient journey"},
            {"kind": "h1", "text": "From discovered<br/>[[to booked, in one visit.]]"},
            {"kind": "para",
             "text": "Meet Thabo Nkosi. He needs a check up, so he discovers dentists near him on "
                     "WebScheduler Local and opens Fernwood Dental Studio. He reads about the "
                     "practice, sees the team, and presses <b>Book online</b>. Two minutes later he "
                     "has an appointment, a confirmation in his inbox and a reminder on the way. "
                     "Nobody at the practice picked up a phone."},
            {"kind": "numbered", "items": [
                ("1", "Discover", "Patients find your practice by what they need, where they are, "
                                  "or by a doctor's name."),
                ("2", "Your profile", "Your services, team, places and hours, with a Book online "
                                      "button on every service and every practitioner."),
                ("3", "Pick a time", "Service, then who, then a time. Each of your places shows "
                                     "its own column, so picking a time picks the place."),
                ("4", "Booked", "A confirmation page, an email with a link to change or cancel, and "
                                "a reminder before the appointment."),
                ("5", "Back again", "Next time, \"Booked here before?\" and one emailed code fill "
                                    "in every detail."),
            ]},
            {"kind": "small",
             "text": "Fernwood Dental Studio and Thabo Nkosi are made up. Every screen in this guide is "
                     f"of our demo practice, which you can try yourself at {DEMO}."},
        ],
    },

    # --- 3. service and person -------------------------------------------------------
    {
        "id": "service",
        "blocks": [
            {"kind": "eyebrow", "text": "02 · Choose a service"},
            {"kind": "h1", "text": "What they need.<br/>[[Who they would like to see.]]"},
            {"kind": "para",
             "text": "Thabo starts with the service: a check up and clean, 45 minutes. Then the "
                     "person. He can pick Dr Lindiwe Khumalo by name, or choose <b>Anyone "
                     "available</b> and see every free time across the team. Only the people who "
                     "give that service are offered."},
            {"kind": "shots", "max_h_mm": 100, "items": [
                shot("service", "Services with their length and price, as you set them"),
            ]},
            {"kind": "bullets", "items": [
                "Each service has its own length, and optional time before and after it for "
                "preparing the room.",
                "Prices are optional. \"Medical aid rates\" or \"On request\" work as well.",
                "You decide who gives which service, so a hygienist is never booked for a root canal.",
            ]},
        ],
    },

    # --- 4. times by place -------------------------------------------------------------
    {
        "id": "times",
        "blocks": [
            {"kind": "eyebrow", "text": "03 · Pick a time"},
            {"kind": "h1", "text": "One column<br/>[[for every place you work.]]"},
            {"kind": "para",
             "text": "A practitioner who works at your rooms on Monday and at a hospital on Thursday "
                     "no longer confuses anyone. Each place gets its own column with its name and "
                     "address on top, then only the days with free times. Thabo picks Monday at "
                     "09:30 in Parkhurst, and the place comes with the time."},
            {"kind": "shots", "max_h_mm": 105, "items": [
                shot("times", "Free times in one column per place, a week at a time"),
            ]},
            {"kind": "bullets", "items": [
                "Earlier and Later move a week at a time.",
                f"Patients can book up to {HORIZON_DAYS} days ahead, or what you choose, from 1 day "
                "to 2 years.",
                "Set a minimum notice, so nobody books the next ten minutes.",
                "Times follow your profile's opening hours, each practitioner's own hours, time off "
                "and public holidays.",
            ]},
        ],
    },

    # --- 5. details and booked ------------------------------------------------------------
    {
        "id": "details",
        "blocks": [
            {"kind": "eyebrow", "text": "04 · Your details, then booked"},
            {"kind": "h1", "text": "Name, email, phone.<br/>[[And your own questions.]]"},
            {"kind": "para",
             "text": "Thabo fills in his name, email and phone, and answers the practice's question: "
                     "his medical aid. He accepts the booking terms and presses "
                     "Book. The time is his, and nobody else can take it in the meantime."},
            {"kind": "shots", "max_h_mm": 88, "items": [
                shot("details", "The details step, with the chosen time on top"),
                shot("booked", "Booked: the confirmation page"),
            ]},
            {"kind": "bullets", "items": [
                f"Up to {QUESTIONS} questions of your own: text, a choice, a tick box, a number, a "
                "code or a South African ID number.",
                "Answers you mark as sensitive never appear in an email or a message.",
                "Your own terms, or a link to them, before Book.",
            ]},
        ],
    },

    # --- 6. emails -----------------------------------------------------------------------
    {
        "id": "emails",
        "blocks": [
            {"kind": "eyebrow", "text": "05 · Confirmation and reminders"},
            {"kind": "h1", "text": "Fewer empty chairs.<br/>[[Fewer calls to the front desk.]]"},
            {"kind": "para",
             "text": "The confirmation lands at once, in your practice's name. A reminder follows "
                     f"{REMINDER_HOURS} hours before, and a second one if you want it. Both carry a "
                     "link to change or cancel, so a patient who cannot make it frees the time for "
                     "someone else instead of simply not arriving."},
            {"kind": "shots", "max_h_mm": 100, "items": [
                shot("email-confirm", "The confirmation"),
                shot("phone-email", "The same email on a phone"),
            ]},
            {"kind": "bullets", "items": [
                f"Patients can change or cancel online up to {CHANGE_HOURS} hours before, or the "
                "time you choose. Set it to never and they call you instead.",
                "Every message can be edited on the Messages page, and replies go to your practice.",
                "WhatsApp messages too, through your own WhatsApp Business account.",
            ]},
        ],
    },

    # --- 7. returning patients -------------------------------------------------------------
    {
        "id": "returning",
        "blocks": [
            {"kind": "eyebrow", "text": "06 · Returning patients"},
            {"kind": "h1", "text": "Booked here before?<br/>[[One code, and they are done.]]"},
            {"kind": "para",
             "text": "Six months later Thabo is due again. He picks a time, and above the form he "
                     "sees <b>Booked here before?</b> He enters his email and gets a 6 digit code. "
                     "He types it in, and his name, phone and answers are filled in from last time. "
                     "He checks them and books."},
            {"kind": "shots", "max_h_mm": 78, "items": [
                shot("returning", "Booked here before?"),
                shot("code-email", "The code, by email"),
                shot("filled", "Details filled in"),
            ]},
            {"kind": "table", "widths": [0.3, 0.7],
             "header": ["PRIVATE BY DESIGN", "HOW"],
             "rows": [
                 ["Nothing is given away", "The answer is the same whether or not the email has booked "
                                           "before, so nobody can test who your patients are."],
                 ["Short lived", f"The code works for {CODE_MINUTES} minutes, the link in the same email "
                                 f"for {LINK_MINUTES}. Using either ends both."],
                 ["Your practice only", "Only your own record of the patient is used, never another "
                                        "practice's."],
                 ["Sensitive answers stay out", "Answers you mark as sensitive are never filled in "
                                                "for them."],
             ]},
        ],
    },

    # --- 8. calendar -----------------------------------------------------------------------
    {
        "id": "calendar",
        "blocks": [
            {"kind": "eyebrow", "text": "07 · Your calendar"},
            {"kind": "h1", "text": "Every booking,<br/>[[already on the calendar.]]"},
            {"kind": "para",
             "text": "Thabo's appointment is in Dr Khumalo's column before anyone looks. The day view "
                     "has one column per practitioner, the week and month views show the whole "
                     "practice, and bookings taken by phone go on the same calendar in a few clicks."},
            {"kind": "shots", "max_h_mm": 105, "items": [
                shot("calendar", "The day: one column for each practitioner"),
            ]},
            {"kind": "bullets", "items": [
                "Move a booking by picking a new time; the patient is told.",
                "Mark each visit completed, or a no show.",
                "Email a patient straight from the appointment.",
                "Your dashboard shows today, who is waiting and the week ahead.",
            ]},
        ],
    },

    # --- 9. team ---------------------------------------------------------------------------
    {
        "id": "team",
        "blocks": [
            {"kind": "eyebrow", "text": "08 · Your team"},
            {"kind": "h1", "text": "Doctors, reception,<br/>[[each with their own view.]]"},
            {"kind": "para",
             "text": "Everyone signs in with their own email. A practitioner sees their own diary. "
                     "Reception books for the doctors they look after. You decide who gets a notice "
                     "when a patient books, so a doctor can leave it all to the front desk."},
            {"kind": "shots", "max_h_mm": 92, "items": [
                shot("dashboard", "The dashboard: today for each practitioner"),
            ]},
            {"kind": "table", "widths": [0.26, 0.74],
             "header": ["ROLE", "WHAT THEY DO"],
             "rows": [
                 ["Admin", "Everything: services, team, settings, every diary."],
                 ["Practitioner", "Their own diary and patients. The same person as on your profile's "
                                  "team, with one record for both."],
                 ["Reception", "Books and moves appointments for the practitioners they look after."],
             ]},
            {"kind": "small", "text": f"Up to {PEOPLE} people who can be booked."},
        ],
    },

    # --- 10. services, hours, records ---------------------------------------------------------
    {
        "id": "records",
        "blocks": [
            {"kind": "eyebrow", "text": "09 · Patients and services"},
            {"kind": "h1", "text": "Every patient,<br/>[[with their history.]]"},
            {"kind": "para",
             "text": "Each patient who books gets a record: contact details, answers, every past and "
                     "upcoming appointment, and how often they did not arrive. Thabo's shows his "
                     "two past check ups and the two visits he booked online."},
            {"kind": "shots", "max_h_mm": 86, "items": [
                shot("customer", "A patient's record: details and every visit"),
            ]},
            {"kind": "table", "widths": [0.3, 0.7],
             "header": ["SET UP ONCE", "WHAT YOU DECIDE"],
             "rows": [
                 ["Services", f"Up to {SERVICES}, from 5 minutes to {LONGEST_HOURS} hours, each with "
                              "who gives it and where."],
                 ["Hours", "Your profile's hours, or each person's own, with several periods a day and "
                           "a place for each."],
                 ["Time off", "For one person or the whole practice."],
                 ["Deposits", f"Optional, paid to your own PayFast or Paystack account. The time is held "
                              f"for {DEPOSIT_HOLD} minutes while the patient pays. We take no fee."],
             ]},
            {"kind": "small",
             "text": "Patients can ask for a copy of their record, and you can export it for them "
                     "(POPIA)."},
        ],
    },

    # --- 11. getting started ------------------------------------------------------------
    {
        "id": "start",
        "blocks": [
            {"kind": "eyebrow", "text": "10 · Getting started"},
            {"kind": "h1", "text": "Take your first booking<br/>[[this afternoon.]]"},
            {"kind": "para",
             "text": "Online bookings come with Verified: "
                     f"{PRICE} a month or {YEARLY_PRICE} a year, and your practice profile stays "
                     "free either way. Once Verified, turn bookings on from your owner area."},
            {"kind": "numbered", "items": [
                ("1", "Create your practice profile", f"{HOST}/add-profile. Free, about twenty "
                                                       "minutes. The Profile guide walks you through it."),
                ("2", "Get Verified", "Upload your documents; we check them and switch Verified on."),
                ("3", "Turn on bookings", "Your services come across from your profile, and you are "
                                          "the first person who can be booked."),
                ("4", "Add your team and places", "Each practitioner, their hours and where they work."),
                ("5", "Check your messages", "Read the confirmation and reminder, and add your own "
                                             "questions and terms."),
                ("6", "Book yourself", "Make a test booking on your own profile, then cancel it from "
                                       "the email."),
            ]},
            {"kind": "small",
             "text": "Online bookings never move a profile up in discovery results. A complete "
                     "profile does."},
        ],
    },

    # --- 12. back cover ------------------------------------------------------------------
    {
        "id": "close",
        "back_cover": True,
        "blocks": [
            {"kind": "gap", "mm": 10},
            {"kind": "badge", "size_mm": 38},
            {"kind": "gap", "mm": 10},
            {"kind": "eyebrow", "text": "WEBSCHEDULER LOCAL"},
            {"kind": "h1", "text": "Be discovered.<br/>[[Then get booked.]]"},
            {"kind": "para",
             "text": "A practice profile patients can find, a Book online button they can use at "
                     "any hour, and a calendar your whole team works from. Try the demo practice, "
                     f"then create your own: {DEMO}"},
            {"kind": "gap", "mm": 6},
            {"kind": "qr", "size_mm": 44, "caption": "Scan to create your free practice profile"},
            {"kind": "gap", "mm": 4},
            {"kind": "h3", "text": f"{HOST}/add-profile"},
            {"kind": "gap", "mm": 10},
            {"kind": "small", "text": f"WebScheduler (Pty) Ltd · South Africa · {EDITION}"},
        ],
    },
]


def pdf_pages(vertical: str = "general") -> list:
    return _PAGES
