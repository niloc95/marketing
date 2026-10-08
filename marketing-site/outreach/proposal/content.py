"""
WebScheduler Local business proposal — the copy, in one place.

Rendered by ../playbook/build_playbook.py's build_pdf (see build_proposal.py),
so it shares the playbook's type, palette, badge and cover photographs.

The page order follows the app's home page (app/Views/directory/home.php): an
opening, the "Your business. Your services. ..." backbone, then one moment per
pillar with a profile preview beside it, Verified as an interruption after
Locations, and the free-profile close. Opportunities gets two pages, because
the Jobs board is the part no other local platform here does both ways.

Every claim is one the live site makes: /add-profile, /verified, /faq,
/compare, /jobs and /jobs/post, and the terms. It is a snapshot, not a feed —
when a price, a limit or a feature changes there, change it here. Copy rules:
never call the product a directory; "business profile", not "listing";
locations, team and jobs are Verified Business features and never presented
as free; the badge does not move anyone up the results. The profile previews
are invented examples and are labelled as such.
"""

EDITION = "October 2026"

DOC_TITLE = "WebScheduler Local — Business Proposal"
DOC_AUTHOR = "WebScheduler (Pty) Ltd"
DOC_SUBJECT = "A local business discovery and visibility platform for South African businesses"
DOC_KEYWORDS = ("WebScheduler Local, business profile, local visibility, Verified Business, "
                "jobs board, South Africa, small business, professionals, mobile businesses")

RUNNING_FOOTER = "WEBSCHEDULER LOCAL · BUSINESS PROPOSAL"

CTA_BARE = "local.webscheduler.co.za/add-profile"
CTA_URL = "https://local.webscheduler.co.za/add-profile"
JOBS_URL = "https://local.webscheduler.co.za/jobs"
PLAYBOOK_URL = "https://local.webscheduler.co.za/assets/playbook/webscheduler-local-visibility-playbook.pdf"

# The renderer has one edition; the playbook's per-trade editions do not apply.
VERTICALS = {"general": {"audience": None}}

PRICE_VERIFIED = "R29.99"
ORANGE = "#F77F00"


def cta_url(source: str, medium: str) -> str:
    return f"{CTA_URL}?utm_source={source}&utm_medium=proposal&utm_campaign=local-proposal-2026"


def _link(url: str, text: str) -> str:
    return f'<link href="{url}" color="{ORANGE}">{text}</link>'


_PAGES = [
    # --- 1. opening ---------------------------------------------------------
    {
        "id": "cover",
        "cover": True,
        "blocks": [
            {"kind": "lockup", "size_mm": 15},
            {"kind": "gap", "mm": 18},
            {"kind": "eyebrow", "text": "Business proposal"},
            {"kind": "cover_title", "text": "A local presence<br/>[[that keeps working.]]"},
            {"kind": "cover_sub",
             "text": "WebScheduler Local is a local business discovery and visibility platform built "
                     "for South Africa — for businesses, professionals, service businesses, mobile "
                     "businesses, and the people they hire."},
            {"kind": "gap", "mm": 4},
            {"kind": "banner", "height_mm": 66},
            {"kind": "gap", "mm": 9},
            {"kind": "small", "text": f"WebScheduler (Pty) Ltd · {EDITION}"},
        ],
    },

    # --- 2. the backbone ----------------------------------------------------
    {
        "id": "backbone",
        "blocks": [
            {"kind": "gap", "mm": 14},
            {"kind": "stack",
             "text": "[[Your]] business.<br/>[[Your]] services.<br/>[[Your]] locations.<br/>"
                     "[[Your]] people.<br/>[[Your]] opportunities."},
            {"kind": "gap", "mm": 6},
            {"kind": "statement", "text": "A business isn't just a name in a list."},
            {"kind": "para",
             "text": "It's the people behind it. The services it provides. The places it operates. "
                     "And the opportunities it creates. A WebScheduler Local business profile is your "
                     "local presence online: one place where customers discover what you do, "
                     "understand whether you are right for them, and contact you."},
            {"kind": "label", "text": "In this proposal"},
            {"kind": "small",
             "text": "Why local search matters · your business · your services · your locations · "
                     "Verified Business · your people · your opportunities: hiring, and work coming "
                     "to you · who it suits · how it compares · what it costs · getting started"},
        ],
    },

    # --- 3. why it matters ----------------------------------------------------
    {
        "id": "why",
        "blocks": [
            {"kind": "eyebrow", "text": "Why it matters"},
            {"kind": "h1", "text": "People don't want another advert.<br/>[[They want a local answer.]]"},
            {"kind": "para",
             "text": "Customers search for the problem they have, near them, at the moment they need "
                     "it. The businesses that win that moment are the ones whose details are complete, "
                     "consistent and easy to find."},
            {"kind": "table", "widths": [0.26, 0.37, 0.37],
             "header": ["", "PAID SOCIAL", "LOCAL SEARCH"],
             "rows": [
                 ["When it reaches someone", "While they are browsing something else.",
                  "While they are looking for what you sell."],
                 ["Who chooses the audience", "You do, by guessing at interests.",
                  "The customer does, by typing the search."],
                 ["What it costs to keep going", "Reach stops when the spend stops.",
                  "Effort rather than media spend. The profile stays up."],
                 ["How much it can explain", "One creative and a few seconds of attention.",
                  "Services, area, hours and contact details in full."],
             ]},
            {"kind": "eyebrow", "text": "Why it compounds"},
            {"kind": "statement",
             "text": "Search engines trust a business they can corroborate: the same name, address, "
                     "phone number and services, stated the same way in more than one independent "
                     "place."},
            {"kind": "para",
             "text": "Claim your Google Business Profile first. A WebScheduler Local business profile "
                     "then gives South African customers another route to you, and gives search "
                     "engines one more consistent, well-structured source that agrees with it."},
        ],
    },

    # --- 4. business + services ----------------------------------------------
    {
        "id": "business-services",
        "blocks": [
            {"kind": "gap", "mm": 4},
            {"kind": "moment", "num": "01", "label": "Your business",
             "title": "Tell people what you do.<br/>Show them who you are.<br/>[[Let them find you.]]",
             "text": "What you do, your story, your photos and your opening hours, on a profile "
                     "customers find in search, on the map and in your category — with every way "
                     "to reach you, including <i>Chat on WhatsApp</i> and <i>Book online</i> buttons. "
                     "Free.",
             "preview": {"name": "Thabo's Plumbing", "meta": "Plumber · Fourways, Gauteng",
                         "label": "Example profile",
                         "rows": [("Opening hours", "Mon–Sat, 7am–6pm"),
                                  ("Service area", "Fourways, Lonehill"),
                                  ("Reviews", "4.8 · 23 reviews"),
                                  ("Contact", "Call, WhatsApp, Book")]},
             "after_mm": 16},
            {"kind": "moment", "num": "02", "label": "Your services", "flip": True,
             "title": "Make the services<br/>you provide<br/>[[discoverable.]]",
             "text": "The services you offer, named the way customers search for them, with prices "
                     "if you want them — so “burst geyser Fourways” finds you, not only “plumber”. "
                     "Free.",
             "preview": {"name": "Thabo's Plumbing", "meta": "Services",
                         "label": "Example profile",
                         "rows": [("Geyser repair", "from R850"),
                                  ("Burst pipes, same day", "from R650"),
                                  ("Blocked drains", "from R550"),
                                  ("Leak detection", "Quote")]}},
        ],
    },

    # --- 5. locations + the Verified interruption -----------------------------
    {
        "id": "locations-verified",
        "blocks": [
            {"kind": "gap", "mm": 4},
            {"kind": "moment", "num": "03", "label": "Your locations", "tag": "With Verified",
             "title": "One business.<br/>Multiple practices.<br/>[[One local presence.]]",
             "text": "Every branch or practice with its own address, map pin, contact details and "
                     "hours — up to 6 more on one profile, each described to Google as a business "
                     "location in its own right. A free profile shows your main address, or the "
                     "suburbs you travel to.",
             "preview": {"name": "Northside Dental", "meta": "Dentist · 3 practices",
                         "label": "Example profile",
                         "rows": [("Sandton", "Until 17:00"),
                                  ("Randburg", "Until 17:00"),
                                  ("Fourways", "Sat 08:00–12:00")]},
             "after_mm": 14},
            {"kind": "eyebrow", "text": f"Verified Business · {PRICE_VERIFIED} a month"},
            {"kind": "h1", "text": "People want to know<br/>[[who they are dealing with.]]"},
            {"kind": "para",
             "text": "A green Verified Business badge on your profile, and beside your name in every "
                     "search result, means a person here has checked your company registration "
                     "document and the owner's ID. It also adds your other locations, your people, "
                     "your job vacancies and replies to requests for work."},
            {"kind": "numbered", "items": [
                ("01", "SEND TWO DOCUMENTS",
                 "When you sign up or any time after. Stored privately, never shown on your profile."),
                ("02", "WE REVIEW THEM",
                 "Usually within two working days. <b>Nothing to pay until you are approved.</b>"),
                ("03", "CANCEL ANY TIME",
                 "One button. The badge stays for the month you paid for; your profile stays as it is."),
            ]},
            {"kind": "small",
             "text": "The badge checks identity, not quality — not qualifications, licences, insurance "
                     "or the standard of anyone's work — and it does not move a business up the "
                     "results. We sell the check, not the ranking."},
        ],
    },

    # --- 6. people -------------------------------------------------------------
    {
        "id": "people",
        "blocks": [
            {"kind": "gap", "mm": 4},
            {"kind": "moment", "num": "04", "label": "Your people", "tag": "With Verified",
             "flip": True,
             "title": "Meet the people<br/>[[behind the business.]]",
             "text": "Up to 12 team members, each with a photo, their role, their qualifications "
                     "and their areas of expertise — so a customer who was referred to a person, not "
                     "a business, lands in the right place.",
             "preview": {"name": "Northside Dental", "meta": "Our team",
                         "label": "Example profile",
                         "rows": [("Dr N. Mokoena", "Dentist"),
                                  ("Dr A. Pillay", "Orthodontist"),
                                  ("L. van Wyk", "Oral hygienist"),
                                  ("T. Dlamini", "Practice manager")]},
             "after_mm": 14},
            {"kind": "eyebrow", "text": "More searches find you"},
            {"kind": "statement",
             "text": "A search for one of your people by name — or for something only one of them "
                     "does — brings up your business too."},
            {"kind": "para",
             "text": "For a practice, a firm or a salon, customers often remember the person before "
                     "the business. Showing your team turns every one of them into another way in. "
                     "Qualifications appear as you enter them; the badge checks the business, not "
                     "each qualification."},
            {"kind": "label", "text": "Especially useful for"},
            {"kind": "small",
             "text": "Medical and dental practices · law and accounting firms · salons, spas and "
                     "studios · training providers and schools · any business where clients ask "
                     "for someone by name"},
        ],
    },

    # --- 7. opportunities: hiring ----------------------------------------------
    {
        "id": "opportunities-hiring",
        "blocks": [
            {"kind": "eyebrow", "text": "05 · Your opportunities"},
            {"kind": "cover_title", "text": "Jobs. Requests.<br/>[[Services needed.]]"},
            {"kind": "para",
             "text": "The " + _link(JOBS_URL, "Jobs board") + " brings two kinds of opportunity "
                     "together: vacancies posted straight from local business profiles, and people "
                     "looking for someone to do a job. It turns your profile from something customers "
                     "read into something that brings people and work to you."},
            {"kind": "gap", "mm": 3},
            {"kind": "moment", "num": "05", "label": "Hiring", "tag": "With Verified",
             "title": "Post a vacancy<br/>[[from your profile.]]",
             "text": "Advertise a job in minutes from your dashboard. Applicants see your full "
                     "profile — your services, your people, your reviews — before they apply.",
             "preview": {"name": "Dental assistant", "meta": "Northside Dental · Sandton, Gauteng",
                         "label": "Example post · job",
                         "rows": [("Job type", "Full-time"),
                                  ("Salary a month", "R9 000–R12 000"),
                                  ("Closes", "In 30 days"),
                                  ("Apply", "Online form")]},
             "after_mm": 8},
            {"kind": "table", "widths": [0.3, 0.7],
             "header": ["WHAT YOU GET", "WHY IT MATTERS"],
             "rows": [
                 ["Seen on Google", "Set up so eligible vacancies can appear in Google's job search. "
                                    "Posts that show pay get more applicants."],
                 ["Live straight away", "Posts from a business profile normally go live immediately."],
                 ["Applications, privately", "Applicants apply through a form and we pass it on by "
                                             "email — your address is never shown. Or send them to "
                                             "your own careers page. We keep no CVs."],
                 ["No stale posts", "A post closes after 30 days unless you choose a date, and we "
                                    "email you before it closes so you can renew it."],
                 ["A board people trust", "Every post carries a never-pay-to-apply warning, and a "
                                          "post visitors report is hidden until we have looked at "
                                          "it — so genuine employers are not drowned out by scams."],
             ]},
        ],
    },

    # --- 8. opportunities: work coming to you ----------------------------------
    {
        "id": "opportunities-requests",
        "blocks": [
            {"kind": "gap", "mm": 4},
            {"kind": "moment", "num": "05", "label": "Work coming to you", "tag": "With Verified",
             "flip": True,
             "title": "Someone nearby<br/>[[needs what you do.]]",
             "text": "Anyone can post a request for a service on the Jobs board, free. When one "
                     "matches your category and province, we email it to a small number of "
                     "businesses — verified businesses first.",
             "preview": {"name": "Burst geyser, needed today", "meta": "Service request · Fourways",
                         "label": "Example post · service needed",
                         "rows": [("Category", "Plumber"),
                                  ("Province", "Gauteng"),
                                  ("Replies", "Limited"),
                                  ("Your move", "Reply online")]},
             "after_mm": 12},
            {"kind": "label", "text": "How a request becomes work"},
            {"kind": "numbered", "items": [
                ("01", "A CUSTOMER POSTS",
                 "They describe the job and where it is. Posting a request is free, and the public "
                 "never pays to use the board."),
                ("02", "WE ALERT MATCHING BUSINESSES",
                 "By category and province, verified businesses first. Alerts are on by default and "
                 "switch off with one click."),
                ("03", "YOU REPLY",
                 "From your dashboard. Each request takes only a limited number of replies, so being "
                 "first matters."),
                ("04", "THEY CHOOSE",
                 "Your reply is passed on by email. Your profile — services, prices, photos, reviews "
                 "and people — does the rest."),
            ]},
            {"kind": "eyebrow", "text": "The difference"},
            {"kind": "statement",
             "text": "No pay-per-lead. No bidding. Replying is part of Verified Business, at "
                     f"{PRICE_VERIFIED} a month."},
        ],
    },

    # --- 9. who it suits ---------------------------------------------------------
    {
        "id": "who-its-for",
        "blocks": [
            {"kind": "eyebrow", "text": "Built for your kind of business"},
            {"kind": "h1", "text": "One platform,<br/>[[many ways of working.]]"},
            {"kind": "para",
             "text": "People search for the problem they have, in their own words. A complete profile "
                     "answers the specific search as well as the category. Here is what works hardest "
                     "for each kind of business."},
            {"kind": "table", "widths": [0.27, 0.73],
             "header": ["IF YOU ARE", "WHAT WORKS HARDEST FOR YOU"],
             "rows": [
                 ["<b>A professional practice</b><br/>doctors, dentists, attorneys, accountants, "
                  "tax practitioners",
                  "A complete, factual profile — the kind of presence professional codes are most "
                  "comfortable with. With Verified Business: each practitioner shown with their role "
                  "and qualifications, and found by name."],
                 ["<b>A service business</b><br/>salons, spas, studios, clinics, tutors",
                  "Services with prices, a <i>Book online</i> button, photographs of your work and "
                  "reviews you can reply to."],
                 ["<b>A mobile or home-based business</b><br/>plumbers, electricians, mobile "
                  "hairdressers, cleaners",
                  "Choose “I travel to customers”: your profile shows your town and the suburbs you "
                  "serve, and keeps your street address private. A WhatsApp button — and, with "
                  "Verified Business, requests for work from people nearby."],
                 ["<b>A business with branches</b><br/>practices, franchises, multi-site clinics",
                  "With Verified Business: up to 6 more locations on one profile, each with its own "
                  "address, phone, map pin and hours."],
                 ["<b>An employer</b><br/>any business that is hiring",
                  "With Verified Business: vacancies on the Jobs board, set up for Google's job "
                  "search, posted straight from your profile."],
                 ["<b>A sole trader or freelancer</b>",
                  "A free profile as an individual, with your services, your area and every way to "
                  "reach you — no website needed."],
                 ["<b>Based outside South Africa</b>",
                  "Welcome on a monthly <b>International Profile</b> subscription, billed in rand; "
                  "the price is shown before you pay."],
             ]},
        ],
    },

    # --- 10. how it compares ------------------------------------------------------
    {
        "id": "compare",
        "blocks": [
            {"kind": "eyebrow", "text": "How it compares"},
            {"kind": "h1", "text": "Where we stand out,<br/>[[and where we fit.]]"},
            {"kind": "para",
             "text": "Next to Google Business Profile and the South African directories. Free means "
                     f"on every free profile; Verified means it needs Verified Business, {PRICE_VERIFIED} "
                     "a month."},
            {"kind": "table", "widths": [0.25, 0.27, 0.24, 0.24],
             "header": ["FEATURE", "WEBSCHEDULER LOCAL", "GOOGLE BUSINESS PROFILE", "SA DIRECTORIES"],
             "rows": [
                 ["Free business profile", "Free, for a South African business", "Yes",
                  "A basic listing is free; better placement is paid"],
                 ["Services, with prices", "Free", "Yes", "Varies"],
                 ["Customer reviews", "Free; checked before they go up, and you can reply", "Yes",
                  "Varies"],
                 ["Online booking", "Free <i>Book online</i> button to your own booking page",
                  "Booking link, or Reserve with Google", "Varies"],
                 ["Multiple locations", "Verified: up to 6 more on one profile",
                  "A separate profile for each location", "Usually a separate listing each"],
                 ["Staff profiles, searchable by name", "Verified: up to 12 people", "Partly",
                  "Varies"],
                 ["Job vacancies", "Verified: set up for Google's job search", "No",
                  "Not a core feature"],
                 ["Requests for work from the public", "Free to post; replying is Verified",
                  "Not in South Africa", "Some sites charge per lead"],
                 ["Document-checked verification", "Verified: registration and owner's ID, checked "
                                                   "by a person", "Partly", "Varies"],
             ]},
            {"kind": "small",
             "text": "Checked on 3 October 2026 from each company's own help pages; other platforms "
                     "change their features. Full comparison: "
                     + _link("https://local.webscheduler.co.za/compare", "local.webscheduler.co.za/compare") + "."},
            {"kind": "eyebrow", "text": "Not instead of Google"},
            {"kind": "statement",
             "text": "Your website is owned, Google is controlled, paid social is rented. We are the "
                     "independent, consistent profile that supports the first two."},
        ],
    },

    # --- 11. what it costs ------------------------------------------------------------
    {
        "id": "pricing",
        "blocks": [
            {"kind": "eyebrow", "text": "What it costs"},
            {"kind": "h1", "text": "Start free.<br/>[[Add only what earns its place.]]"},
            {"kind": "table", "widths": [0.52, 0.22, 0.26],
             "header": ["", "FREE PROFILE", "VERIFIED BUSINESS"],
             "rows": [
                 ["Found in search, on the map and in your category", "Included", "Included"],
                 ["Your own profile page, structured for Google", "Included", "Included"],
                 ["Services, with prices", "Included", "Included"],
                 ["Phone, email, website, social links, WhatsApp, Book online", "Included", "Included"],
                 ["Address and map pin, or the suburbs you travel to", "Included", "Included"],
                 ["A logo and up to 8 photos", "Included", "Included"],
                 ["Customer reviews, with your replies", "Included", "Included"],
                 ["Monthly report: views and where your leads came from", "Included", "Included"],
                 ["Verified Business badge, documents checked", "—", "Included"],
                 ["Up to 6 more branches or practices", "—", "Included"],
                 ["Up to 12 team members, found by name", "—", "Included"],
                 ["Post job vacancies, set up for Google's job search", "—", "Included"],
                 ["Reply to requests for work, alerted first", "—", "Included"],
                 ["<b>Price</b>", "<b>R0, always</b>", f"<b>{PRICE_VERIFIED} a month</b>"],
             ]},
            {"kind": "para",
             "text": "<b>Free:</b> any business with a South African address. No monthly fee. No "
                     "subscription. No obligation. No commission, and no card required. "
                     "<b>Verified Business:</b> optional; you pay only after your documents are "
                     "approved, monthly, and you can cancel any time. "
                     "<b>International Profile:</b> for a business based outside South Africa, "
                     "monthly in rand, with the price shown before you pay."},
            {"kind": "small",
             "text": "Results are ordered by distance when a customer shares their location, and "
                     "otherwise by how complete a profile is — and everything that counts towards "
                     "complete is free. Nothing you buy changes your position."},
        ],
    },

    # --- 12. close -------------------------------------------------------------------
    {
        "id": "close",
        "back_cover": True,
        "blocks": [
            {"kind": "gap", "mm": 4},
            {"kind": "eyebrow", "text": "Get started"},
            {"kind": "cover_title", "text": "Your business should be<br/>[[easy to find.]]"},
            {"kind": "para",
             "text": "A free business profile covers your business information, services, contact "
                     "details, address and map location, photos and opening hours. No monthly fee. "
                     "No subscription. No obligation."},
            {"kind": "numbered", "items": [
                ("01", "CREATE YOUR PROFILE", "A few minutes. Confirm your email address and it is live."),
                ("02", "COMPLETE IT", "Services, area, hours and photos — every field is one less "
                                      "question a customer has to ask."),
                ("03", "MAKE EVERY SOURCE AGREE", "One name, one address, one phone number, the same "
                                                  "as your Google Business Profile."),
                ("04", "ADD VERIFIED WHEN IT FITS", "When your branches, your people, your vacancies "
                                                    "or new work are part of how you grow."),
            ]},
            {"kind": "gap", "mm": 3},
            {"kind": "qr", "size_mm": 30, "caption": "Scan to create your free business profile"},
            {"kind": "gap", "mm": 3},
            {"kind": "h3", "text": _link(cta_url("pdf", "proposal"), CTA_BARE)},
            {"kind": "small", "text": "{{CONTACT}}"},
        ],
    },
]


# Who the last page names. The web build is published on the site, so it
# carries the company's general contact details. The print build is the one
# sent to clients by email, and carries the founder's own. build_proposal.py
# sets PROFILE before each build.
PROFILE = "web"

_GUIDE = "Read the free guide: " + _link(PLAYBOOK_URL, "The local visibility playbook (PDF)")

CONTACT = {
    "web": (_link("mailto:za_admin@webscheduler.co.za", "za_admin@webscheduler.co.za")
            + " · +27 76 829 7070<br/>"
            "WebScheduler (Pty) Ltd · Reg. 2026/138798/07 · Delta Road, Eltonhill, 2196<br/>"
            + _GUIDE),
    "print": ("<b>Nilo Cara</b>, Founder · "
              + _link("mailto:nilo.cara@webscheduler.co.za", "nilo.cara@webscheduler.co.za")
              + " · +27 82 529 2242<br/>"
              "WebScheduler (Pty) Ltd · Reg. 2026/138798/07 · 21 Delta Road, Eltonhill, "
              "Sandton, 2196<br/>"
              + _GUIDE),
}


def pdf_pages(vertical: str = "general") -> list:
    contact = CONTACT[PROFILE]
    return [{**page, "blocks": [{**b, "text": contact} if b.get("text") == "{{CONTACT}}" else b
                                for b in page["blocks"]]}
            for page in _PAGES]
