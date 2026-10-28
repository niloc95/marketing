"""
WebScheduler Local: how to build a business profile. The copy, in one place.

Rendered by ../playbook/build_playbook.py's build_pdf (see build_profile_guide.py),
so it shares the playbook's type, palette, badge and page guards.

A public guide for business owners, published on /resources. Every limit and
rule here is one the app enforces, written against the code on 28 Oct 2026:

  photos          App\\Libraries\\ListingImageProcessor, Listing::GALLERY_MAX
  description     App\\Libraries\\RichText::MAX_PLAIN_LENGTH, DescriptionHelper
  strength        App\\Services\\ListingQualityService, Config\\Directory
  services        App\\Services\\ServiceMenuService, ListingMenuService
  brands          App\\Services\\ListingBrandService
  locations       App\\Services\\PracticeLocationService
  team            App\\Services\\TeamMemberService
  jobs            Config\\JobBoard, JobBoardService
  reviews         Config\\Reviews, ReviewService
  Verified        _verification_pitch.php, _pricing_compare.php

When one of those changes, change it here and rebuild. Caps are constants
below, so a change is made once.

Copy rules (CLAUDE.md): "discover", "discovery results", "discovered on the
platform" for our own feature, "search" only for Google and other people's
search; "business profile", never "listing"; no long dashes and no hyphenated
words. Two promises the app makes and this guide must keep: paying never moves
a business up, and the badge checks identity, not qualifications or quality.
"""

EDITION = "October 2026"

DOC_TITLE = "WebScheduler Local: How to Build a Business Profile Customers Choose"
DOC_AUTHOR = "WebScheduler (Pty) Ltd"
DOC_SUBJECT = ("Creating and completing a business profile on WebScheduler Local: photos, a "
               "description written with ChatGPT, services, brands, and the Verified Business "
               "features: locations, team members, jobs and reviews")
DOC_KEYWORDS = ("WebScheduler Local, business profile, Verified Business, local business discovery, "
                "South Africa, profile photos, ChatGPT, team members, jobs board, reviews")

RUNNING_FOOTER = "WEBSCHEDULER LOCAL · HOW TO BUILD YOUR BUSINESS PROFILE"

HOST = "local.webscheduler.co.za"
ADD_URL = f"https://{HOST}/add-profile"
VERTICALS = {"general": {"audience": None}}

# Caps and settings, as the code has them today.
PRICE = "R29.99"            # Config\Directory::$verifiedMonthlyAmount (live on /verified)
SETUP_PRICE = "R250"        # the profile setup service, once off
SETUP_DAYS = 3              # Config\Directory::$profileSetupDays
GALLERY = 8                 # Listing::GALLERY_MAX
UPLOAD_MB = 10              # ListingImageProcessor::MAX_UPLOAD_BYTES
GIF_MB = 2                  # ListingImageProcessor::MAX_PASSTHROUGH_BYTES
STORED_PX = 1600            # every photo is stored as a 1600px WebP
DESCRIPTION_MAX = 3000      # RichText::MAX_PLAIN_LENGTH
DESC_SHORT, DESC_FULL = 120, 300   # ListingQualityService DESCRIPTION_*_CHARS
SERVICES = 30               # ServiceMenuService::MAX_SERVICES
SERVICE_NAME = 120
MENU_PAGES = 4              # ListingMenuService::MAX_PAGES
SERVICE_AREAS = 15          # DirectoryListingModel::MAX_SERVICE_AREAS
FOCUS = 20                  # areas of focus on the profile
BRANDS = 20                 # ListingBrandService::MAX_BRANDS
BRAND_NAME = 80
LOCATIONS = 6               # PracticeLocationService::MAX_LOCATIONS
TEAM = 12                   # TeamMemberService::MAX_MEMBERS
TEAM_FOCUS = 12
TEAM_BIO = 1200
JOB_DAYS, JOB_MAX_DAYS, JOB_REMIND = 30, 60, 3      # Config\JobBoard
REQUEST_REPLIES = 5
ALERTS_PER_DAY = 3
REVIEW_MIN, REVIEW_MAX, REPLY_MAX = 30, 1500, 1000  # Config\Reviews
REVIEWS_SHOWN = 5
REVIEW_REPORTS = 3
UNCONFIRMED_DAYS = 7
INDEX_BAR, HOME_BAR, TARGET = 40, 55, 65            # Config\Directory quality bars


def N(n: int) -> str:
    """A number as South Africans write it: 3 000, not 3,000."""
    return f"{n:,}".replace(",", " ")


def cta_url(source: str, medium: str) -> str:
    return f"{ADD_URL}?utm_source={source}&utm_medium=profile-guide&utm_campaign=profile-guide-2026"


_PAGES = [
    # --- 1. cover -----------------------------------------------------------------
    {
        "id": "cover",
        "cover": True,
        "blocks": [
            {"kind": "lockup", "size_mm": 15},
            {"kind": "gap", "mm": 16},
            {"kind": "eyebrow", "text": "Profile guide"},
            {"kind": "cover_title", "text": "Build a profile<br/>[[customers choose.]]"},
            {"kind": "cover_sub",
             "text": "A step by step guide to your WebScheduler Local business profile: the photos "
                     "that work, a description written with ChatGPT, your services and brands, and "
                     "everything Verified Business adds."},
            {"kind": "gap", "mm": 2},
            {"kind": "banner", "height_mm": 56},
            {"kind": "gap", "mm": 7},
            {"kind": "small", "text": "<b>Inside:</b>"},
            {"kind": "small",
             "text": "Before you start · profile strength · what you do and how to reach you · "
                     "photos that work · your description with ChatGPT · services · hours and map · "
                     "brands we carry · Verified Business · locations · team members · jobs · "
                     "reviews · your launch checklist"},
            {"kind": "gap", "mm": 3},
            {"kind": "small", "text": f"WebScheduler (Pty) Ltd · South Africa · {EDITION}"},
        ],
    },

    # --- 2. before you start --------------------------------------------------------
    {
        "id": "start",
        "blocks": [
            {"kind": "eyebrow", "text": "01 · Before you start"},
            {"kind": "h1", "text": "Twenty minutes,<br/>[[and these things to hand.]]"},
            {"kind": "para",
             "text": "Your business profile is free, and it stays free. It is one address with your "
                     "services, hours, photos, map and reviews that you can share anywhere. If you "
                     "have no website, a complete profile does the job a website would. Gather these "
                     "first and the form takes about twenty minutes."},
            {"kind": "table", "widths": [0.34, 0.66],
             "header": ["HAVE READY", "WHY"],
             "rows": [
                 ["Your trading name", "Exactly as it appears on your signage, invoices and Google, so "
                                       "customers recognise you."],
                 ["An email address you check", "It identifies your profile. Every edit link and "
                                                "review alert goes to it."],
                 ["Phone and WhatsApp numbers", "Customers call or message from the profile in one tap."],
                 ["Your address", "Puts you on the map and in local discovery. You choose whether it "
                                  "shows publicly."],
                 ["Your opening hours", "Including public holidays, if they differ."],
                 ["A logo and up to 8 photos", "See pages 5 and 6 for what to shoot."],
                 ["Your list of services", "With prices if you want to show them."],
                 ["A few honest answers about you", "ChatGPT turns them into your description "
                                                    "(pages 7 and 8)."],
             ]},
            {"kind": "h3", "text": "Where to go"},
            {"kind": "bullets", "items": [
                f"<b>Create:</b> {HOST}/add-profile. The form is in five numbered sections, with a "
                "tracker that shows which are done.",
                f"<b>Edit later:</b> {HOST}/manage. Enter your email and we send you a link. No "
                "password needed.",
                f"<b>Rather we did it?</b> Our profile setup service completes your profile for "
                f"{SETUP_PRICE} once off, within {SETUP_DAYS} business days. It buys our time, "
                "nothing else: no badge and no higher place.",
            ]},
        ],
    },

    # --- 3. profile strength --------------------------------------------------------
    {
        "id": "strength",
        "blocks": [
            {"kind": "eyebrow", "text": "02 · Profile strength"},
            {"kind": "h1", "text": "Complete beats paid.<br/>[[Every time.]]"},
            {"kind": "para",
             "text": "Discovery results are ordered by how complete a profile is. Your profile "
                     "strength, out of 100, shows on the form as you fill it in and on your Manage "
                     "page, with your biggest wins listed first. Every point is free. Nothing you "
                     "pay for can raise it."},
            {"kind": "table", "widths": [0.34, 0.46, 0.2],
             "header": ["THE QUESTION A CUSTOMER ASKS", "WHAT ANSWERS IT", "POINTS"],
             "rows": [
                 ["<b>Can I place you?</b>", "Category, street address, town, province, a map pin", "25"],
                 ["<b>Can I reach you?</b>", "Phone, a website or social page, an online booking link", "20"],
                 ["<b>Can I size you up?</b>",
                  f"A description of {DESC_SHORT} characters, and more for {DESC_FULL}; a logo; "
                  "up to 5 photos", "30"],
                 ["<b>Do you answer the question?</b>",
                  "Up to 4 services, opening hours for at least 3 days, facilities you tick, "
                  "areas of focus", "25"],
             ]},
            {"kind": "table", "widths": [0.2, 0.8],
             "header": ["SCORE", "WHAT IT MEANS"],
             "rows": [
                 [f"Under {INDEX_BAR}", "Your profile is live and can be discovered on the platform, "
                                        "but we ask Google not to show it yet."],
                 [f"{INDEX_BAR} and up", "Offered to Google and other search engines."],
                 [f"{HOME_BAR} and up", "Can appear in Recently added on the home page."],
                 [f"<b>{TARGET} and up</b>", "<b>The target.</b> A profile customers can choose from "
                                              "without having to call first."],
             ]},
            {"kind": "statement",
             "text": "Paying never moves a business up. Completing its profile does."},
            {"kind": "small",
             "text": "Bands: Needs work under 40, Getting there from 40, Good from 60, Excellent from 80. "
                     "The score is never shown on your public profile."},
        ],
    },

    # --- 4. sections 1 and 2 --------------------------------------------------------
    {
        "id": "basics",
        "blocks": [
            {"kind": "eyebrow", "text": "03 · What you do, and how to reach you"},
            {"kind": "h1", "text": "Be easy to recognise.<br/>[[Be easy to reach.]]"},
            {"kind": "label", "text": "Section 1 · What you do"},
            {"kind": "table", "widths": [0.28, 0.72],
             "header": ["FIELD", "HOW TO GET IT RIGHT"],
             "rows": [
                 ["Profile type", "Most businesses are a business. A club, complex or mall chooses "
                                  "Place or Venue, ticks its facilities, then contacts us to link the "
                                  "businesses inside it."],
                 ["Category", "The one a customer would look for, not the one you would use at a trade "
                              "show. A salon is a salon even if it also sells products. It is worth "
                              "8 points on its own."],
             ]},
            {"kind": "label", "text": "Section 2 · How customers reach you"},
            {"kind": "table", "widths": [0.28, 0.72],
             "header": ["FIELD", "HOW TO GET IT RIGHT"],
             "rows": [
                 ["Business name", "Your trading name only. Do not add places or services to it "
                                   "(“Best Plumber Durban Cheap”): customers trust it less, and "
                                   "your services and area already do that job."],
                 ["Phone", "The number that is actually answered in business hours. Worth 10 points, "
                           "the most of any single field."],
                 ["Alternative phone", "A mobile beside a landline, say. Both show on your profile."],
                 ["WhatsApp", "Adds a Chat on WhatsApp button. Only fill it in if someone replies."],
                 ["Website", "Optional. No website? Leave it blank: your profile is your website."],
                 ["Social pages", "Facebook, Instagram, LinkedIn, TikTok. Paste the page link; for "
                                  "Instagram and TikTok your @handle is enough. Each shows as an icon."],
             ]},
            {"kind": "small",
             "text": "Your name, title and position under Your details are for our records only and "
                     "are never shown on your profile."},
        ],
    },

    # --- 5. photos: the header -------------------------------------------------------
    {
        "id": "photos-band",
        "blocks": [
            {"kind": "eyebrow", "text": "04 · Photos that work"},
            {"kind": "h1", "text": "Your photos are<br/>[[the first thing people see.]]"},
            {"kind": "para",
             "text": "Your profile opens with a band of your photos, side by side, with your name, "
                     "category, rating and today’s hours written over the bottom of them. On a phone "
                     "it shows one photo at a time, and customers swipe. A profile with no photos "
                     "keeps a plain heading, and is easy to scroll past."},
            {"kind": "table", "widths": [0.26, 0.74],
             "header": ["", "WHAT WE ACCEPT, AND WHAT HAPPENS"],
             "rows": [
                 ["<b>Logo</b>", "One. Square works best, on a clear or white background. It sits beside "
                                 "your name and is used when your profile is shared."],
                 ["<b>Gallery</b>", f"Up to {GALLERY} photos."],
                 ["<b>Formats</b>", f"JPEG, PNG, WebP, GIF, BMP or AVIF, up to {UPLOAD_MB} MB each. A GIF "
                                    f"may be up to {GIF_MB} MB."],
                 ["<b>Size</b>", f"We resize every photo to {STORED_PX} pixels and store it small, so "
                                 "upload the best original you have. Never a screenshot."],
                 ["<b>iPhone</b>", "If a photo is refused, it is probably HEIC. Choose Settings, Camera, "
                                   "Formats, Most Compatible, or upload from the Photos picker."],
             ]},
            {"kind": "h3", "text": "Five rules for the header"},
            {"kind": "numbered", "items": [
                ("01", "LANDSCAPE, AND KEEP THE BOTTOM QUIET",
                 "Turn the phone sideways. Your name is written over the bottom third, so keep faces, "
                 "products and signs in the top two thirds."),
                ("02", "YOUR BEST PHOTO FIRST",
                 "The first photo leads on a phone and in link previews. Make it the one that says what "
                 "you do at a glance."),
                ("03", "DAYLIGHT, NOT FLASH",
                 "Shoot near a window or outside in the shade. Wipe the lens. Hold still."),
                ("04", "NO TEXT IN THE PHOTO",
                 "Flyers, price lists and specials go stale and are unreadable on a phone. Put prices "
                 "in your services instead."),
                ("05", "REAL, AND YOURS",
                 "Your premises, your team, your work. Stock photos and images from other websites are "
                 "not allowed, and customers can tell."),
            ]},
        ],
    },

    # --- 6. photos: the shot list ----------------------------------------------------
    {
        "id": "photos-list",
        "blocks": [
            {"kind": "eyebrow", "text": "04 · Photos that work"},
            {"kind": "h1", "text": "Eight photos.<br/>[[Here is the shot list.]]"},
            {"kind": "para",
             "text": "Each photo should answer a question a customer has before they call. Five photos "
                     "earn the full photo points; eight tell the whole story."},
            {"kind": "table", "widths": [0.06, 0.32, 0.62],
             "header": ["", "SHOT", "THE QUESTION IT ANSWERS"],
             "rows": [
                 ["1", "<b>Your work, at its best</b>", "What do you actually do? A finished haircut, a "
                                                        "fitted kitchen, a plated dish."],
                 ["2", "<b>The outside, from the street</b>", "Will I find you? Include the sign and the "
                                                              "entrance."],
                 ["3", "<b>Inside, wide</b>", "What is it like? Clean, tidy, well lit, shot from a corner."],
                 ["4", "<b>You or your team at work</b>", "Who will I deal with? Hands busy, eyes on the "
                                                          "job, not posed."],
                 ["5", "<b>Before and after</b>", "Is it worth it? Same angle, same light, both halves."],
                 ["6", "<b>Close up of detail</b>", "Do you take care? Stitching, finishes, ingredients."],
                 ["7", "<b>Products or equipment</b>", "Do you have what I need? Shelves, tools, vehicles."],
                 ["8", "<b>Parking or access</b>", "Can I get there? Parking, ramps, the floor you are on."],
             ]},
            {"kind": "h3", "text": "Avoid"},
            {"kind": "bullets", "items": [
                "<b>Customers’ faces</b> without their permission, and children’s faces at all. "
                "Before and after photos of a person need that person’s written consent.",
                "<b>Blurry, dark or sideways photos.</b> One sharp photo beats three soft ones.",
                "<b>The same photo twice,</b> or eight photos of one room.",
                "<b>Other businesses’ logos and photos.</b> Brands you carry have their own place "
                "(page 11).",
            ]},
            {"kind": "small",
             "text": "Verified Business adds one YouTube or Vimeo video, shown first in your photos and "
                     "playing right on your profile. Thirty to sixty seconds of your work is plenty."},
        ],
    },

    # --- 7. description with ChatGPT ----------------------------------------------------
    {
        "id": "chatgpt",
        "blocks": [
            {"kind": "eyebrow", "text": "05 · Your description"},
            {"kind": "h1", "text": "Stuck for words?<br/>[[Let ChatGPT draft it.]]"},
            {"kind": "para",
             "text": "Section 3 of the form, Tell people about you, has a <b>Write it with ChatGPT</b> "
                     "button. It does not write anything by itself: it hands ChatGPT your details and "
                     "your answers, and you decide what goes on your profile."},
            {"kind": "numbered", "items": [
                ("01", "FILL IN SECTIONS 1 AND 2 FIRST",
                 "Your category, name and town go to ChatGPT with your answers, so the draft is about "
                 "your business, not a generic one."),
                ("02", "PRESS WRITE IT WITH CHATGPT",
                 "Four short questions open. Answer any of them, in your own words. Hints for your "
                 "category appear beside them."),
                ("03", "OPEN CHATGPT WITH MY ANSWERS",
                 "ChatGPT opens in a new tab with everything filled in. A free ChatGPT account is "
                 "enough."),
                ("04", "COPY WHAT IT WRITES, PASTE IT BACK",
                 "Come back to the form and paste it into the description box. Nothing is saved until "
                 "you save the form."),
                ("05", "MAKE IT YOURS",
                 "Read it aloud. Fix anything that is not true, and change anything you would never "
                 "say. Use the checklist on the next page."),
            ]},
            {"kind": "table", "widths": [0.5, 0.5],
             "header": ["THE FOUR QUESTIONS", "A GOOD ANSWER"],
             "rows": [
                 ["What do you believe in, or how do you work?",
                  "“We quote before we start and clean up before we leave.”"],
                 ["Who will customers see, and what are their qualifications?",
                  "“Sipho, a registered plumber with 15 years in Pinetown.”"],
                 ["How long have you been doing this?", "“Since 2011.”"],
                 ["What makes you different, or what do customers love?",
                  "“Same day callouts, and we answer the phone on weekends.”"],
             ]},
        ],
    },

    # --- 8. description: editing ----------------------------------------------------------
    {
        "id": "description-edit",
        "blocks": [
            {"kind": "eyebrow", "text": "05 · Your description"},
            {"kind": "h1", "text": "ChatGPT drafts.<br/>[[You sign it off.]]"},
            {"kind": "para",
             "text": f"Your description can be up to {N(DESCRIPTION_MAX)} characters, but the best are "
                     f"three short paragraphs. Write at least {DESC_FULL} characters for the full points: "
                     "about four sentences."},
            {"kind": "table", "widths": [0.5, 0.5],
             "header": ["WEAK", "STRONG"],
             "rows": [
                 ["We are the best plumbing company in Durban, offering world class service and "
                  "unbeatable prices to all our valued customers.",
                  "Thabo’s Plumbing fixes burst geysers, blocked drains and leaking taps across "
                  "Pinetown and Westville. We quote before we start, and most callouts are sorted the "
                  "same day. Sipho, our registered plumber, has 15 years on the tools."],
                 ["Says nothing a competitor could not say. “Best” cannot be checked.",
                  "Names the problems people actually have, the area, a promise and a person."],
             ]},
            {"kind": "h3", "text": "Before you paste, check"},
            {"kind": "bullets", "items": [
                "<b>Every fact is true.</b> ChatGPT fills gaps with guesses: years, awards, guarantees "
                "and qualifications you never gave it. Delete them.",
                "<b>No claims you cannot prove:</b> “best”, “cheapest”, “number one”, "
                "“guaranteed results”.",
                "<b>The first sentence says what you do and where.</b> It is what shows in discovery "
                "results and in Google.",
                "<b>Name the problem, not only the category.</b> People look for “burst geyser” "
                "before “plumber”.",
                "<b>Plain words.</b> Remove “nestled”, “bespoke”, “unparalleled” and "
                "anything else you would not say to a customer.",
                "<b>Regulated professions:</b> doctors, attorneys and other registered professionals "
                "may describe what they do and their registration accurately. Leave out comparisons "
                "with colleagues and promises of results, as your professional code requires.",
                "<b>No phone numbers or links in the text.</b> They have their own fields, which are "
                "the ones customers tap.",
            ]},
        ],
    },

    # --- 9. services ----------------------------------------------------------------------
    {
        "id": "services",
        "blocks": [
            {"kind": "eyebrow", "text": "06 · Services"},
            {"kind": "h1", "text": "Say what you do,<br/>[[one line at a time.]]"},
            {"kind": "para",
             "text": f"Services sit in section 5. Add one line per service or class, up to {SERVICES}. "
                     "The first four earn points, and every one is a way to be discovered: a "
                     "customer looking for that exact thing finds you."},
            {"kind": "table", "widths": [0.5, 0.5],
             "header": ["INSTEAD OF", "WRITE"],
             "rows": [
                 ["Plumbing", "Geyser replacement · Blocked drains · Leak detection"],
                 ["Beauty treatments", "Gel manicure · Brow lamination · Hot stone massage"],
                 ["Legal services", "Divorce and custody · Wills and estates · Property transfers"],
                 ["Classes", "Beginner yoga class · Pregnancy yoga · Private sessions"],
             ]},
            {"kind": "bullets", "items": [
                f"<b>Prices are optional.</b> “R250”, “from R150” and “POA” all "
                "work. A price, even a starting one, saves you calls from people who cannot afford you.",
                f"<b>Keep each name short</b> (up to {SERVICE_NAME} characters). The detail belongs in "
                "your description.",
                "<b>Most popular first.</b> Services show in the order you list them.",
            ]},
            {"kind": "h3", "text": "The rest of section 5"},
            {"kind": "table", "widths": [0.28, 0.72],
             "header": ["", "WHAT IT IS FOR"],
             "rows": [
                 ["Facilities", "Tick what you offer: card payments, delivery, online booking, "
                                "parking and the options for your category. Tick online booking and "
                                "add the link to your booking page."],
                 ["Areas of focus", f"What you specialise in, separated with commas, up to {FOCUS}. "
                                    "“Sports injuries, back pain” for a physio."],
                 ["Credentials", "Your qualifications and registrations, as you state them."],
                 ["Menu", f"For restaurants and cafés: one PDF, or photos of up to {MENU_PAGES} pages, "
                          f"{UPLOAD_MB} MB each."],
             ]},
        ],
    },

    # --- 10. where and when ----------------------------------------------------------------
    {
        "id": "where-when",
        "blocks": [
            {"kind": "eyebrow", "text": "07 · Where and when"},
            {"kind": "h1", "text": "Be on the map.<br/>[[Be open when they look.]]"},
            {"kind": "label", "text": "Section 4 · Where you are"},
            {"kind": "bullets", "items": [
                "<b>Your address</b> puts you on the map and in near me results. Type the street "
                "address as the post office would write it.",
                "<b>Pin your exact location.</b> Drag the pin to your door. Maps often stop at the "
                "street, and a customer who cannot find the entrance phones a competitor.",
                "<b>Where do customers meet you?</b> Choose Customers visit my business, I travel to "
                "customers, or Both.",
                f"<b>Service areas,</b> if you travel: one suburb or town per line, up to "
                f"{SERVICE_AREAS}. They show on your profile as the places you go.",
                "<b>Working from home?</b> Untick Show my business address publicly. You still appear "
                "in your area; your street does not.",
            ]},
            {"kind": "label", "text": "Section 5 · Opening hours"},
            {"kind": "bullets", "items": [
                "Fill in every day you work, at least three, so your profile can say Open now. Your "
                "profile shows today’s hours in the header.",
                "<b>By appointment only?</b> Tick it, and customers know to call first.",
                "<b>Public holidays:</b> open the public holidays section and add a holiday for each "
                "day your hours differ. Check it before Easter and December.",
            ]},
            {"kind": "preview",
             "name": "Thabo’s Plumbing",
             "meta": "Plumber in Pinetown, Durban · Open now · 07:00 to 17:00 today",
             "label": "Services",
             "rows": [
                 ("Geyser replacement", "from R4 500"),
                 ("Blocked drains", "R650"),
                 ("Leak detection", "POA"),
             ]},
            {"kind": "small", "text": "An invented example, as a profile shows it."},
        ],
    },

    # --- 11. brands ------------------------------------------------------------------------
    {
        "id": "brands",
        "blocks": [
            {"kind": "eyebrow", "text": "08 · Brands we carry"},
            {"kind": "h1", "text": "Sell a brand people know?<br/>[[Show it.]]"},
            {"kind": "para",
             "text": "Brands we carry is a strip of logos that scrolls under your description. It "
                     "answers a question customers ask before they visit: do you have it, or can you "
                     "work on mine? It is optional, and for most service businesses it is not needed."},
            {"kind": "table", "widths": [0.5, 0.5],
             "header": ["USE IT IF YOU", "LEAVE IT OUT IF"],
             "rows": [
                 ["Sell or stock brands: a pharmacy, a hardware store, a bike shop",
                  "You would be listing your suppliers rather than what customers buy"],
                 ["Service named makes: a mechanic for Toyota and VW, an appliance repairer",
                  "You only worked on a brand once"],
                 ["Use a known product line: a salon using a professional hair range",
                  "The brand is your competitor"],
                 ["Are an authorised dealer or installer", "You would need the logo to make your "
                                                           "business look bigger than it is"],
             ]},
            {"kind": "h3", "text": "How to add them"},
            {"kind": "numbered", "items": [
                ("01", "UP TO 20 BRANDS", f"Each with its name (up to {BRAND_NAME} characters) and "
                                          "its logo."),
                ("02", "A CLEAN LOGO", f"On a clear or white background works best. JPEG, PNG, WebP "
                                       f"or GIF, up to {UPLOAD_MB} MB. The brand’s own media page "
                                       "usually has one."),
                ("03", "ONLY WHAT YOU GENUINELY CARRY",
                 "A logo here says you carry the brand. It does not say the brand endorses you, and "
                 "you must never suggest it does. We may remove a brand a business does not carry."),
            ]},
        ],
    },

    # --- 12. Verified Business ----------------------------------------------------------------
    {
        "id": "verified",
        "blocks": [
            {"kind": "eyebrow", "text": "09 · Verified Business"},
            {"kind": "h1", "text": "Show who you are.<br/>[[Then show everything else.]]"},
            {"kind": "para",
             "text": f"Verified Business is {PRICE} a month, billed monthly, cancel any time. It puts a "
                     "checked badge beside your name on your profile and in every discovery result you "
                     "appear in, and opens the features on the next four pages. Your free profile stays "
                     "free either way."},
            {"kind": "table", "widths": [0.3, 0.7],
             "header": ["VERIFIED ADDS", "WHAT IT DOES FOR YOU"],
             "rows": [
                 ["<b>The badge</b>", "Tells someone choosing between you and an unbadged business that "
                                      "we have checked who you are."],
                 ["<b>Other locations</b>", f"Up to {LOCATIONS} more branches, each with its own address, "
                                            "phone, pin and hours. Page 13."],
                 ["<b>Team members</b>", f"Up to {TEAM} people, with photos, positions and qualifications. "
                                         "Page 14."],
                 ["<b>Jobs</b>", "Post vacancies on our Jobs board, and reply to customers asking for "
                                 "your kind of service. Page 15."],
                 ["<b>A video</b>", "One YouTube or Vimeo video, first in your photos."],
             ]},
            {"kind": "h3", "text": "How to apply"},
            {"kind": "numbered", "items": [
                ("01", "SEND TWO DOCUMENTS", "Your CIPC registration certificate or equivalent, and the "
                                             "owner’s ID card, ID book page or passport. PDF, JPEG or PNG, "
                                             "up to 10 MB. The whole document must be readable in frame."),
                ("02", "WE CHECK THEM FIRST", "There is nothing to pay until they check out. Your documents "
                                              "are stored privately and never shown on your profile."),
                ("03", "PAY, AND THE BADGE GOES UP", "By PayFast, monthly. Apply when you sign up, or later "
                                                      "from your Manage page."),
            ]},
            {"kind": "small",
             "text": "<b>What the badge does not mean.</b> We check a registration document and the owner’s "
                     "ID. We do not check qualifications, rate your work, or move you up the results: "
                     "Verified profiles are ordered exactly like everyone else’s."},
        ],
    },

    # --- 13. locations ---------------------------------------------------------------------------
    {
        "id": "locations",
        "blocks": [
            {"kind": "eyebrow", "text": "10 · Verified · Locations"},
            {"kind": "h1", "text": "More than one address?<br/>[[Show every one.]]"},
            {"kind": "para",
             "text": f"Add up to {LOCATIONS} more branches, practices, second rooms or satellite offices. "
                     "Your main address stays the one in section 4; these appear as Other locations on "
                     "your profile, and are linked from your header so a customer can jump to the one "
                     "nearest them."},
            {"kind": "table", "widths": [0.3, 0.7],
             "header": ["EACH LOCATION HAS", "WHY IT MATTERS"],
             "rows": [
                 ["Its own name", "“Umhlanga branch” or “Room 4, Medical Centre”. Customers "
                                  "pick by name."],
                 ["Its own address and map pin", "So the right branch comes up for the right area, and "
                                                 "each counts in its own town and province."],
                 ["Its own phone and email", "Calls go straight to the branch that can help."],
                 ["Its own opening hours", "Branches rarely keep the same hours. Each shows its own."],
                 ["A contact person", "For us only. Never shown on your profile."],
             ]},
            {"kind": "statement",
             "text": "Each location is described to Google as a business location in its own right."},
            {"kind": "h3", "text": "Good practice"},
            {"kind": "bullets", "items": [
                "Use the same trading name on every branch, with the area after it, as on your signs.",
                "Pin each branch at its door, not at the shopping centre’s middle.",
                "Close a branch? Delete it the same day, so nobody drives to an empty shop.",
                "A doctor working from two practices adds the second as a location, not a second "
                "profile.",
            ]},
        ],
    },

    # --- 14. team ----------------------------------------------------------------------------------
    {
        "id": "team",
        "blocks": [
            {"kind": "eyebrow", "text": "11 · Verified · Team and staff"},
            {"kind": "h1", "text": "People choose people.<br/>[[Introduce yours.]]"},
            {"kind": "para",
             "text": f"Show up to {TEAM} people in an Our team panel: partners, associates, practitioners, "
                     "stylists, technicians. A customer who was referred to a person, not a business, "
                     "lands in the right place, and someone looking for one of your people by name, or "
                     "for something only one of them does, discovers your business too."},
            {"kind": "table", "widths": [0.26, 0.74],
             "header": ["FOR EACH PERSON", "HOW TO FILL IT IN"],
             "rows": [
                 ["Name", "As customers know them: “Dr Thandi Mokoena”, or just “Lynn”."],
                 ["Position", "Their role in plain words: Senior stylist, Associate attorney, Practice nurse."],
                 ["Qualifications", "Degrees, registrations and memberships: “MBChB (UCT), HPCSA "
                                    "registered”. Shown as you state them."],
                 ["Areas of focus", f"What they are best at, separated with commas, up to {TEAM_FOCUS}."],
                 ["Short bio", f"Two or three sentences, up to {N(TEAM_BIO)} characters. What they do, for "
                               "whom, and one human detail."],
                 ["Photo", "Head and shoulders, facing the camera, plain background, good light. The "
                           "same style for everyone looks professional."],
             ]},
            {"kind": "h3", "text": "Before you add anyone"},
            {"kind": "bullets", "items": [
                "<b>Ask them first.</b> Their name, photo and qualifications go on a public page. Get "
                "each person’s agreement, and remove them when they ask or when they leave.",
                "<b>Qualifications must be true.</b> We do not check them; you are responsible for them, "
                "as our Terms say.",
                "<b>Keep it current.</b> A customer who books the person on your profile and finds they "
                "left last year does not come back.",
            ]},
        ],
    },

    # --- 15. jobs ------------------------------------------------------------------------------------
    {
        "id": "jobs",
        "blocks": [
            {"kind": "eyebrow", "text": "12 · Verified · Jobs"},
            {"kind": "h1", "text": "Hiring? Post it<br/>[[where people already look.]]"},
            {"kind": "para",
             "text": "Post vacancies on our Jobs board straight from your Manage page. Each post links to "
                     "your profile, and eligible vacancies are set up to appear in Google’s job search."},
            {"kind": "numbered", "items": [
                ("01", "A WE’RE HIRING NOTICE", "While a vacancy is live, your profile shows a We’re "
                                                    "hiring pill beside your badges, with a link to apply."),
                ("02", "A CLOSING DATE, ALWAYS", f"Posts run {JOB_DAYS} days unless you pick a date, and "
                                                 f"never more than {JOB_MAX_DAYS}. We remind you "
                                                 f"{JOB_REMIND} days before, so you can renew."),
                ("03", "APPLICATIONS, YOUR WAY", "By email, relayed to you without us keeping CVs, or "
                                                 "through a link to your own application page. Each "
                                                 "person can apply only once per vacancy."),
                ("04", "OPTIONAL SCREENING", "For email applications, choose a level (Basic, Intermediate "
                                             "or Advanced) and each applicant answers a short reading, "
                                             "language and numbers check. You see their score."),
            ]},
            {"kind": "h3", "text": "Service requests: new work, sent to you"},
            {"kind": "para",
             "text": f"Customers post requests on the Jobs board: “Need a plumber in Pinetown this week.” "
                     f"Verified businesses can reply, up to {REQUEST_REPLIES} replies per request, and "
                     "when a request matches your category and province, verified businesses are the "
                     f"first we alert, no more than {ALERTS_PER_DAY} alerts a day."},
            {"kind": "small",
             "text": "<b>Keep it safe.</b> Never ask an applicant to pay for training, registration, a "
                     "uniform or placement. Posts that read like the common job scams wait for a person "
                     "to check them. Our free Hiring guide on the Guides and reports page covers fair "
                     "choosing, consent checks and offers."},
        ],
    },

    # --- 16. reviews -----------------------------------------------------------------------------------
    {
        "id": "reviews",
        "blocks": [
            {"kind": "eyebrow", "text": "13 · Reviews · every profile"},
            {"kind": "h1", "text": "Reviews you can trust,<br/>[[and how to earn them.]]"},
            {"kind": "para",
             "text": "Every profile can receive reviews, and every owner can reply. Neither needs "
                     "Verified Business, and a rating never changes where you appear."},
            {"kind": "table", "widths": [0.3, 0.7],
             "header": ["HOW A REVIEW GOES UP", ""],
             "rows": [
                 ["1. Written", f"One to five stars and {REVIEW_MIN} to {N(REVIEW_MAX)} characters, on your "
                                "profile."],
                 ["2. Confirmed", "The reviewer clicks a link we email them. One review per person per "
                                  f"business. Unconfirmed reviews are deleted after {UNCONFIRMED_DAYS} days."],
                 ["3. Read by us", "A person reads every review before it is published."],
                 ["4. Published", f"Your newest {REVIEWS_SHOWN} show, with Show more for the rest."],
             ]},
            {"kind": "h3", "text": "Getting more reviews"},
            {"kind": "bullets", "items": [
                "Ask when the job is done and the customer is happy. Send your profile link on WhatsApp "
                "with one line: “If you have a minute, a review helps us a lot.”",
                "Ask everyone, not only the customers you expect to be kind. A mix reads as real.",
                "<b>Never</b> pay, offer a discount for, or write a review yourself, or have staff and "
                "family do it. We remove reviews like these when we find them.",
            ]},
            {"kind": "h3", "text": "Replying"},
            {"kind": "bullets", "items": [
                f"Your reply goes up under the review straight away, up to {N(REPLY_MAX)} characters. "
                "Reply to good reviews too.",
                "To a bad review: thank them, say what you will do, and invite them to call. Short and "
                "polite. You are writing for the next customer, not winning the argument.",
                "Never put the customer’s private details, threats or legal language in a reply.",
                "You cannot delete a review. If one breaks our terms, report it; when "
                f"{REVIEW_REPORTS} people report a review, it goes back to us to check.",
                "You can switch new reviews off for your whole profile on your Manage page. "
                "Published reviews stay.",
            ]},
        ],
    },

    # --- 17. checklist ----------------------------------------------------------------------------------
    {
        "id": "checklist",
        "blocks": [
            {"kind": "eyebrow", "text": "14 · Your launch checklist"},
            {"kind": "h1", "text": "Before you press save.<br/>[[Tick these off.]]"},
            {"kind": "table", "widths": [0.82, 0.18],
             "header": ["ON EVERY PROFILE", "PAGE"],
             "rows": [
                 ["Trading name only, and the category a customer would look for", "4"],
                 ["A phone number that is answered, and WhatsApp if you reply", "4"],
                 ["A logo, and at least 5 sharp landscape photos, best first", "5 and 6"],
                 [f"A description of at least {DESC_FULL} characters, every fact checked", "7 and 8"],
                 ["At least 4 services, most popular first, with prices where you can", "9"],
                 ["Facilities ticked and areas of focus added", "9"],
                 ["Address, a pin at your door, and service areas if you travel", "10"],
                 ["Hours for every day you work, and public holidays", "10"],
                 ["Your social pages", "4"],
                 ["Brands you genuinely carry, if it applies", "11"],
             ]},
            {"kind": "table", "widths": [0.82, 0.18],
             "header": ["WITH VERIFIED BUSINESS", "PAGE"],
             "rows": [
                 ["Registration document and owner ID sent", "12"],
                 ["Every branch added, each pinned with its own hours", "13"],
                 ["Team members added, with their agreement and matching photos", "14"],
                 ["Open vacancies posted, with a closing date", "15"],
                 ["A short video of your work", "6"],
             ]},
            {"kind": "statement", "text": f"Aim for a profile strength of {TARGET} or more."},
        ],
    },

    # --- 18. after you publish ---------------------------------------------------------------------------
    {
        "id": "after",
        "blocks": [
            {"kind": "eyebrow", "text": "15 · After you publish"},
            {"kind": "h1", "text": "Share it.<br/>[[Keep it fresh.]]"},
            {"kind": "para",
             "text": "Your profile has its own address. The more places it is linked from, the more "
                     "customers discover it, and the more search engines trust it."},
            {"kind": "numbered", "items": [
                ("01", "SHARE YOUR LINK", "In your WhatsApp Business profile and status, your email "
                                          "signature, your social pages’ bio, and on your invoices "
                                          "and quotes."),
                ("02", "USE IT AS YOUR WEBSITE", "No website? Put your profile link in the website field "
                                                 "of your Google Business Profile and your social pages."),
                ("03", "KEEP YOUR DETAILS THE SAME EVERYWHERE",
                 "Name, phone and address should match on your profile, Google, Facebook and your "
                 "signage. Differences make search engines unsure which is right."),
                ("04", "READ YOUR MONTHLY REPORT", "Unless you opt out, we email you each month how "
                                                   "many people viewed your profile and where your leads "
                                                   "came from."),
                ("05", "REFRESH IT", "New photos each season, holiday hours before each holiday, "
                                     "services and prices when they change, vacancies renewed or "
                                     "closed, team members current."),
            ]},
            {"kind": "small",
             "text": f"To change anything, go to {HOST}/manage and we email you a link. Our free Local "
                     "visibility playbook, on the Guides and reports page, covers the wider picture: "
                     "Google, maps and AI assistants."},
        ],
    },

    # --- 19. back cover ------------------------------------------------------------------------------------
    {
        "id": "close",
        "back_cover": True,
        "blocks": [
            {"kind": "gap", "mm": 10},
            {"kind": "badge", "size_mm": 38},
            {"kind": "gap", "mm": 10},
            {"kind": "eyebrow", "text": "WEBSCHEDULER LOCAL"},
            {"kind": "h1", "text": "Discover local.<br/>[[Be discovered.]]"},
            {"kind": "para",
             "text": "A free business profile takes about twenty minutes. Real photos, an honest "
                     "description, your services and your hours are what customers choose by. "
                     "Verified Business adds your people, your branches and your vacancies."},
            {"kind": "gap", "mm": 6},
            {"kind": "qr", "size_mm": 44, "caption": "Scan to create your free profile"},
            {"kind": "gap", "mm": 4},
            {"kind": "h3", "text": f"{HOST}/add-profile"},
            {"kind": "gap", "mm": 10},
            {"kind": "small", "text": f"WebScheduler (Pty) Ltd · South Africa · {EDITION}"},
        ],
    },
]


def pdf_pages(vertical: str = "general") -> list:
    return _PAGES
