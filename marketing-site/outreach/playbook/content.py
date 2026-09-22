"""
Local Visibility Playbook — the copy, in one place.

Both outputs read from here, so the A4 document and the social deck can never
drift apart:

  * PDF_PAGES     → the 12-page A4 PDF  (download + saddle-stitched handout)
  * SOCIAL_SLIDES → the 1080x1350 deck  (LinkedIn / Facebook / Instagram)

The social deck is NOT a resize of the PDF. A4 portrait is unreadable in a
social viewport on a phone, and every table in the PDF dies on the way across.
So the deck is its own short rewrite of the same argument — the hook leads with
the real searches (PDF page 7), which is the most social-ready idea in the
document, and each slide carries one claim.

Two social variants, because the argument names Facebook:
  * "linkedin" — the sharp cut, Facebook named.
  * "meta"     — same argument reframed as "paid reach vs search intent".
                 Posting a Facebook-is-wrong deck on Facebook is tonally odd,
                 and it constrains you if the post is ever boosted.

EDITION is stamped on the cover and in the PDF metadata. It is the one value
that has to be revisited when this is re-cut.
"""

EDITION = "SEPTEMBER 2026 EDITION"
EDITION_FOOTER = "WebScheduler Local · South Africa · September 2026"

DOC_TITLE = "The Local Visibility Playbook for Small Businesses"
DOC_AUTHOR = "WebScheduler Local"
DOC_SUBJECT = "Local search visibility for South African small businesses"
DOC_KEYWORDS = "local SEO, business directory, South Africa, local search, small business"

RUNNING_FOOTER = "WEBSCHEDULER LOCAL · LOCAL VISIBILITY PLAYBOOK"

# The CTA is the entire point of the asset, so it is a real link with a real
# UTM — not flat text. `utm_medium` is overridden per social variant.
CTA_BARE = "listing.webscheduler.co.za"
CTA_URL = "https://listing.webscheduler.co.za/"
CTA_UTM_PDF = "?utm_source=pdf&utm_medium=playbook&utm_campaign=local-visibility-2026"


def cta_url(source: str, medium: str) -> str:
    return f"{CTA_URL}?utm_source={source}&utm_medium={medium}&utm_campaign=local-visibility-2026"


# --------------------------------------------------------------------------
# The A4 document
# --------------------------------------------------------------------------
# Block kinds the PDF renderer understands:
#   eyebrow    small orange label            h1/h2/h3   headings
#   para       body paragraph                small      muted small print
#   statement  the big bold pull-quote       bullets    list of strings
#   table      {header: [...], rows: [[...]], widths: [fractions of text width]}
#   numbered   [(number, heading, body), ...]
#   gap        vertical space in mm
#
# Inline <b>, <i>, <font color="#..."> and <link href="..."> are ReportLab
# markup and are honoured in para / statement / h3.

_PAGES = [
    # --- 1. cover -------------------------------------------------------
    {
        "id": "cover",
        "cover": True,
        "blocks": [
            {"kind": "eyebrow", "text": "WebScheduler Local"},
            {"kind": "banner"},
            {"kind": "gap", "mm": 8},
            {"kind": "cover_title",
             "text": "The local visibility<br/>playbook for<br/>small businesses."},
            # Was 38 words and named Facebook on the cover. A conference handout
            # is skimmed in twenty seconds by people you did not choose.
            {"kind": "cover_sub",
             "text": "Why local search deserves a place in your budget before paid social — and "
                     "how to build a listing that keeps answering the question long after a "
                     "campaign would have stopped."},
            {"kind": "gap", "mm": 13},
            {"kind": "small", "text": "<b>Inside:</b>"},
            {"kind": "small",
             "text": "Paid social vs search intent · how local search decides · what you own and "
                     "what you rent · the local visibility loop · a complete profile · SEO "
                     "foundations · service + location visibility · the customer journey · a "
                     "worked example · a 30-day rollout plan"},
            {"kind": "gap", "mm": 8},
            {"kind": "small", "text": EDITION},
        ],
    },

    # --- 2. why this matters now ----------------------------------------
    # Absorbs the old pages 2, 3 and 8, which made one argument three times.
    # Each row here is a genuinely different axis, not a restatement.
    {
        "id": "why-now",
        "blocks": [
            {"kind": "eyebrow", "text": "WHY THIS MATTERS NOW"},
            {"kind": "h1", "text": "People do not want another advert.<br/>"
                                   "They want a local answer."},
            {"kind": "para",
             "text": "You can spend R5 000 putting an advert in front of thousands of people and "
                     "still reach very few who are looking for what you sell, in the area you "
                     "sell it. The difference is intent."},
            {"kind": "h2", "text": "The local discovery problem"},
            {"kind": "table", "widths": [0.26, 0.37, 0.37],
             "header": ["QUESTION", "PAID SOCIAL", "LOCAL SEARCH"],
             "rows": [
                 ["When does it reach someone?",
                  "While they are browsing something else.",
                  "While they are looking for what you sell."],
                 ["Who chooses the audience?",
                  "You do, by targeting attributes and interests.",
                  "The customer does, by typing the search."],
                 ["What does it cost to keep going?",
                  "Reach stops when the spend stops.",
                  "Effort rather than media spend. The profile stays up."],
                 ["How much can it explain?",
                  "One creative and a few seconds of attention.",
                  "Services, area, hours and contact details in full."],
                 ["What is it best at?",
                  "Creating awareness and demand that did not exist yet.",
                  "Capturing demand that already exists."],
             ]},
            # Stated as a shared experience, not a technical claim about Meta's
            # systems. The defensible line is interest-category vs query match.
            {"kind": "para",
             "text": "You have probably felt the difference as a customer. Look for one specific "
                     "thing — navy cargo pants — and paid social will show you pants for weeks: "
                     "the category you signalled, not the thing you asked for. Interest targeting "
                     "works at the level of a category. A search matches your actual words."},
            {"kind": "eyebrow", "text": "THE SHIFT, IN ONE LINE"},
            {"kind": "statement",
             "text": 'From <font color="#666666">paying to interrupt a broad audience</font> — to '
                     'being findable by people who are already looking.'},
            {"kind": "para",
             "text": "This does not mean Facebook advertising never works. Paid social and local "
                     "search solve different problems: one creates demand, the other captures it. "
                     "A business whose objective is to be found by nearby customers should not "
                     "start with the channel that is worst at being found."},
        ],
    },

    # --- 3. how local search decides ------------------------------------
    # The mechanism. The old document asserted that a complete listing helps
    # discoverability about eight times without ever explaining why.
    {
        "id": "how-it-works",
        "blocks": [
            {"kind": "eyebrow", "text": "HOW IT ACTUALLY WORKS"},
            {"kind": "h1", "text": "Search engines do not<br/>take your word for it."},
            {"kind": "para",
             "text": "This is the part most local marketing advice skips. A search engine will not "
                     "confidently show a business it cannot corroborate, so it looks for the same "
                     "facts stated the same way in more than one independent place. Those matching "
                     "references are called citations."},
            {"kind": "h2", "text": "What a search engine is trying to establish"},
            {"kind": "table", "widths": [0.3, 0.7],
             "header": ["THE QUESTION", "WHAT ANSWERS IT"],
             "rows": [
                 ["Does this business exist?",
                  "The same business name, address and phone number appearing consistently across "
                  "independent sources — your own site, your Google Business Profile, directories, "
                  "invoices and signage."],
                 ["What does it do?",
                  "Service language that matches how customers describe the problem, rather than "
                  "internal or industry wording."],
                 ["Where does it do it?",
                  "A stated address or service area, repeated the same way everywhere the business "
                  "appears."],
             ]},
            {"kind": "h3", "text": "Consistency beats volume"},
            {"kind": "para",
             "text": "Ten references that agree are worth more than fifty that contradict each "
                     "other. An old address, a number you stopped answering or a trading name that "
                     "does not match your registration all give a search engine a reason to trust "
                     "you less. For most businesses, correcting contradictions is higher-value "
                     "work than adding new listings."},
        ],
    },

    # --- 4. what you own and what you rent ------------------------------
    # Fixes the old document's central inaccuracy: it called a listing on our
    # own platform an "owned asset". It is not, and saying so costs credibility
    # with exactly the readers worth converting.
    {
        "id": "own-vs-rent",
        "blocks": [
            {"kind": "eyebrow", "text": "BE CLEAR ABOUT THIS"},
            {"kind": "h1", "text": "What you own, and<br/>what you only rent."},
            {"kind": "para",
             "text": "Marketing material likes the word “asset”, ours included. It is "
                     "worth being precise, because the difference decides what happens when a "
                     "platform changes its rules or its pricing."},
            {"kind": "table", "widths": [0.32, 0.68],
             "header": ["WHAT IT IS", "WHAT THAT MEANS"],
             "rows": [
                 ["YOUR WEBSITE",
                  "Owned. Your domain, your content, your customer data. Nobody can change its "
                  "terms or take it down."],
                 ["YOUR GOOGLE BUSINESS PROFILE",
                  "Controlled, not owned. Free to claim, and the single most important local "
                  "surface for most South African businesses. Google sets the rules — but claim it "
                  "first, before anything else on this page."],
                 ["DIRECTORY LISTINGS, INCLUDING OURS",
                  "Corroboration. An independent, consistent citation that supports the two above. "
                  "Useful, and not a substitute for either."],
                 ["PAID SOCIAL",
                  "Rented. Distribution for exactly as long as you pay for it."],
             ]},
            {"kind": "eyebrow", "text": "WHERE WEBSCHEDULER LOCAL FITS"},
            {"kind": "statement",
             "text": "We are the third row, not the first. A listing here corroborates the "
                     "business you already run and gives South African customers another route to "
                     "find it. Claim your Google Business Profile first, then make every source "
                     "agree with it."},
            # The point a regulated practice needs to hear: a code that limits
            # advertising rarely limits being listed accurately.
            {"kind": "h3", "text": "If your profession is regulated"},
            {"kind": "small",
             "text": "Doctors, attorneys, accountants and tax practitioners work under codes that "
                     "restrict how they may advertise. Very few of those codes restrict being "
                     "listed accurately. A complete, factual, findable profile is usually the "
                     "route a professional code is most comfortable with \u2014 which makes "
                     "search visibility worth more to a regulated practice, not less."},
        ],
    },

    # --- 5. the local visibility loop -----------------------------------
    # Merges the old pages 4 and 10, which framed one model twice.
    {
        "id": "visibility-loop",
        "blocks": [
            {"kind": "eyebrow", "text": "THE LOCAL VISIBILITY LOOP"},
            {"kind": "h1", "text": "Three layers.<br/>One compounding system."},
            {"kind": "para",
             "text": "A local business does not need one magic channel. It needs a connected "
                     "system in which the basic facts are clear, consistent and easy to find."},
            {"kind": "numbered", "items": [
                ("01", "BUILD THE LOCAL LISTING",
                 "A complete profile: business name, category, description, services, contact "
                 "details, location and hours."),
                ("02", "MAKE IT SEARCH-READY",
                 "Clear service and location language, accurate titles and descriptions, and a "
                 "structure that tells a search engine what you do and where you do it."),
                ("03", "TURN DISCOVERY INTO ACTION",
                 "One obvious next step: call, message, visit the website or book."),
            ]},
            {"kind": "h2", "text": "WHAT WEBSCHEDULER LOCAL SUPPLIES"},
            {"kind": "table", "widths": [0.32, 0.68],
             "header": ["PART", "PURPOSE"],
             "rows": [
                 ["LOCAL LISTING", "The profile itself, and the facts customers need."],
                 ["SERVICE VISIBILITY", "Each service named, not buried in one paragraph."],
                 ["LOCATION VISIBILITY", "The areas you actually serve, stated plainly."],
                 ["SEO FOUNDATION",
                  "Pages structured so those relationships stay legible to people and crawlers."],
             ]},
        ],
    },

    # --- 6. step 1: the profile -----------------------------------------
    {
        "id": "step-1-profile",
        "blocks": [
            {"kind": "eyebrow", "text": "STEP 1"},
            {"kind": "h1", "text": "Build a complete local business profile"},
            {"kind": "para",
             "text": "A listing is not a digital business card. Every field you complete is one "
                     "more question a customer does not have to ask, and one more fact a search "
                     "engine can corroborate."},
            {"kind": "h2", "text": "THE CORE PROFILE"},
            {"kind": "table", "widths": [0.3, 0.7],
             "header": ["ELEMENT", "WHAT IT DOES"],
             "rows": [
                 ["Business identity",
                  "Name, category, description, and why you are the right choice."],
                 ["Services", "The services customers search for, named the way they say them."],
                 ["Location", "Your address, or the areas you actually travel to."],
                 ["Contact",
                  "Phone, email and website — and which of them you answer fastest."],
                 ["Trust information",
                  "Credentials, registration numbers, qualifications and photographs of real "
                  "work."],
                 ["Opening information",
                  "Your hours, and how quickly you reply outside them."],
             ]},
            # Addresses the gap honestly rather than routing around it.
            {"kind": "h3", "text": "What a profile cannot do on its own"},
            {"kind": "para",
             "text": "Most people decide using reviews, and for local businesses those live mainly "
                     "on Google. A complete profile earns you the click; the reviews on your "
                     "Google Business Profile usually decide what happens next. Ask satisfied "
                     "customers to leave one there."},
        ],
    },

    # --- 7. step 2: local SEO -------------------------------------------
    {
        "id": "step-2-seo",
        "blocks": [
            {"kind": "eyebrow", "text": "STEP 2"},
            {"kind": "h1", "text": "Comprehensive local SEO"},
            {"kind": "para",
             "text": "SEO is not something added to a listing afterwards. It decides how the "
                     "listing is structured in the first place."},
            {"kind": "h2", "text": "THE LOCAL SEO FOUNDATION"},
            {"kind": "table", "widths": [0.3, 0.7],
             "header": ["FOUNDATION", "PRACTICAL APPROACH"],
             "rows": [
                 ["Business + service relevance",
                  "Say what you do in the words customers use. Cut the marketing language."],
                 ["Location relevance",
                  "Make the service area unambiguous to customers and search engines alike."],
                 ["Service-specific content",
                  "Give each important service its own description instead of one paragraph "
                  "covering everything."],
                 ["Unique page content",
                  "Never repeat one description across several locations or services."],
                 ["Titles and descriptions",
                  "Concise metadata that matches what is actually on the page."],
                 ["Internal structure",
                  "Link business, service and location information so people and crawlers can "
                  "follow it."],
                 ["Technical foundations",
                  "Fast pages, mobile layouts, crawlable content, canonical URLs and sensible "
                  "structured data."],
                 ["Consistency",
                  "One business name, one address, one phone number — everywhere it appears."],
             ]},
            # The legal shield. Deliberately left hedged.
            {"kind": "h3", "text": "Important distinction"},
            {"kind": "small",
             "text": "SEO improves discoverability; it does not guarantee a particular ranking or "
                     "a specific number of leads. Search engines decide which results to show. The "
                     "strategy is to give them clear, useful and locally relevant information."},
        ],
    },

    # --- 8. step 3: service + location ----------------------------------
    {
        "id": "step-3-service-location",
        "blocks": [
            {"kind": "eyebrow", "text": "STEP 3"},
            {"kind": "h1", "text": "Service + location visibility"},
            {"kind": "para",
             "text": "Local businesses answer more than one customer need. A single homepage "
                     "cannot answer every service-and-location search."},
            {"kind": "h2", "text": "Instead of thinking only:"},
            {"kind": "h2", "text": "“I have a business in Sandton.”"},
            {"kind": "h2", "text": "Think about the searches customers actually make:"},
            {"kind": "bullets", "items": [
                "dentist in Sandton",
                "teeth whitening Sandton",
                "family lawyer in Randburg",
                "pilates studio in Rosebank",
                "plumber near Fourways",
                "nail salon in Bryanston",
            ]},
            {"kind": "eyebrow", "text": "The principle"},
            {"kind": "statement",
             "text": "One business is relevant to several service searches and several local "
                     "searches. A well-organised local presence makes those relationships clear "
                     "without creating thin or duplicated pages."},
        ],
    },

    # --- 9. the customer journey ----------------------------------------
    {
        "id": "customer-journey",
        "blocks": [
            {"kind": "eyebrow", "text": "THE CUSTOMER JOURNEY"},
            {"kind": "h1", "text": "From “I need one” to “I found one.”"},
            {"kind": "para",
             "text": "A local discovery experience should answer the customer's questions in the "
                     "order they arise."},
            {"kind": "numbered", "items": [
                ("01", "DISCOVER", "“Who provides this service near me?”"),
                ("02", "UNDERSTAND", "“Do they offer exactly what I need?”"),
                ("03", "CHECK", "“Where are they, and how do I contact them?”"),
                ("04", "TRUST",
                 "“Does this business look legitimate?” — answered by complete details, "
                 "photographs of real work, and reviews they can find."),
                ("05", "ACT", "“Can I call, message, visit, book or learn more?”"),
            ]},
            {"kind": "eyebrow", "text": "The listing is the bridge"},
            {"kind": "statement",
             "text": "A listing is not a place to store business information. It is a discovery "
                     "point where the facts a customer needs are presented clearly and connected "
                     "to a structure search engines can follow."},
        ],
    },

    # --- 10. a worked example -------------------------------------------
    # Content comes from VERTICALS, spliced in by pdf_pages(). One edition per
    # trade: the argument is shared, the searches are theirs.
    {"id": "worked-example", "blocks": []},

    # --- 11. rollout + CTA ----------------------------------------------
    {
        "id": "rollout",
        "blocks": [
            {"kind": "eyebrow", "text": "ROLLOUT"},
            {"kind": "h1", "text": "Your practical 30-day<br/>local visibility plan"},
            {"kind": "para",
             "text": "Start with the foundation. Then build the search surface around it."},
            {"kind": "table", "widths": [0.15, 0.30, 0.55],
             "header": ["WHEN", "ACTION", "OUTPUT"],
             "rows": [
                 ["WEEK 1", "Claim what is already yours",
                  "Claim and complete your Google Business Profile, then claim and complete your "
                  "WebScheduler Local listing."],
                 ["WEEK 2", "Make every source agree",
                  "One business name, one address, one phone number. Correct the contradictions "
                  "you find before adding anything new."],
                 ["WEEK 3", "Strengthen service + location content",
                  "Name each important service. Identify the areas that genuinely matter to the "
                  "business."],
                 ["WEEK 4", "Measure and improve",
                  "Watch discovery, enquiries and listing engagement. Improve the information "
                  "customers ask about most."],
             ]},
            {"kind": "eyebrow", "text": "THE END STATE"},
            {"kind": "para",
             "text": "Instead of asking “how much should I spend on Facebook this "
                     "month?”, a local business can start asking a more durable question:"},
            {"kind": "statement",
             "text": "“How easy is it for someone looking for my service in my area to "
                     "discover, understand and contact my business?”"},
            {"kind": "h3",
             "text": "List your business on WebScheduler Local: "
                     f'<link href="{CTA_URL}{CTA_UTM_PDF}" color="#F77F00">{CTA_BARE}</link>'},
            {"kind": "para",
             "text": "A free local listing is a starting point, not the whole answer. The "
                     "objective is a useful, searchable and locally relevant business presence."},
            {"kind": "gap", "mm": 6},
            {"kind": "small", "text": EDITION_FOOTER},
        ],
    },

    # --- 12. back cover --------------------------------------------------
    # Saddle stitch imposes in fours, so 11 pages could never be bound. The
    # twelfth page is the one a delegate is left holding, so it carries the
    # scannable CTA rather than a blank.
    {
        "id": "back-cover",
        "back_cover": True,
        "blocks": [
            {"kind": "gap", "mm": 18},
            {"kind": "eyebrow", "text": "WEBSCHEDULER LOCAL"},
            {"kind": "h1", "text": "Be found by the<br/>people already<br/>looking for you."},
            {"kind": "para",
             "text": "A free local listing takes a few minutes. Claim it, complete it, and make "
                     "sure it agrees with everywhere else your business appears."},
            {"kind": "gap", "mm": 6},
            # URL is filled in by the renderer so each build carries its own UTM.
            {"kind": "qr", "size_mm": 44, "caption": "Scan to list your business"},
            {"kind": "gap", "mm": 4},
            {"kind": "h3", "text": CTA_BARE},
            {"kind": "gap", "mm": 10},
            {"kind": "small", "text": EDITION_FOOTER},
        ],
    },
]


# --------------------------------------------------------------------------
# Editions — one worked example per trade
# --------------------------------------------------------------------------
# The insight this page carries: people search for the PROBLEM, not the
# category. "Burst geyser" before "plumber". "SARS audit letter" before "tax
# practitioner". A profile that only names the category answers the second
# search and misses the first — which is the whole case for completeness.
#
# Every business name here is invented. `caveat` carries the professional
# advertising rule that actually binds that trade in South Africa; leaving it
# out would have the playbook advising people to breach their own code.

VERTICALS = {
    "general": {
        "audience": None,
        "heading": "A plumber in Fourways.",
        "business": "Thabo's Plumbing",
        "caveat": None,
        "rows": [
            ["burst geyser Fourways",
             "Geyser repair listed as its own service, not buried inside \u201cgeneral "
             "plumbing\u201d."],
            ["no hot water this morning",
             "The description uses the words a customer would, not trade terminology."],
            ["emergency plumber Sunday",
             "Hours stated plainly, including how after-hours calls are handled."],
            ["plumber near Fourways",
             "The category, plus a service area that names Fourways explicitly."],
            ["is Thabo's Plumbing legitimate",
             "Registration details, photographs of completed work, and reviews on the Google "
             "Business Profile."],
        ],
    },
    "plumbing": {
        "audience": "For plumbing and home services",
        "heading": "A plumber in Fourways.",
        "business": "Thabo's Plumbing",
        "caveat": None,
        "rows": [
            ["burst geyser Fourways",
             "Geyser repair listed as its own service, not buried inside \u201cgeneral "
             "plumbing\u201d."],
            ["no hot water this morning",
             "The description uses the words a customer would, not trade terminology."],
            ["emergency plumber Sunday",
             "Hours stated plainly, including how after-hours calls are handled."],
            ["blocked drain near Fourways",
             "Drain clearing named as a service, with the suburbs actually covered."],
            ["is Thabo's Plumbing legitimate",
             "Registration details, photographs of completed work, and reviews on the Google "
             "Business Profile."],
        ],
    },
    "medical": {
        "audience": "For medical and healthcare practices",
        "heading": "A general practice in Randburg.",
        "business": "Bergview Family Practice",
        "caveat": "A factual practice listing is what the HPCSA's rules permit: name, "
                  "qualifications, services, hours, location and registration. What they "
                  "restrict is claims — of superiority, of outcomes, or testimonials. List "
                  "freely; describe results carefully.",
        "rows": [
            ["GP open on Saturday Randburg",
             "Saturday hours stated on the profile, not only \u201cby appointment\u201d."],
            ["travel vaccinations near me",
             "Travel medicine listed as its own service rather than left inside "
             "\u201cconsultations\u201d."],
            ["walk in clinic Randburg",
             "Whether the practice takes walk-ins, said plainly."],
            ["does Bergview take Discovery",
             "The medical aids the practice is contracted to."],
            ["Bergview Family Practice contact number",
             "The practice name and number, formatted identically everywhere they appear."],
        ],
    },
    "legal": {
        "audience": "For attorneys and legal practices",
        "heading": "A law firm in Randburg.",
        "business": "Mokoena & Partners",
        "caveat": "The Legal Practice Council's rules permit factual information about "
                  "practice areas, location and registration — which is what a listing is. "
                  "What they restrict is comparative claims and anything promising an "
                  "outcome. Name what you do; do not promise how it ends.",
        "rows": [
            ["divorce lawyer Randburg",
             "Family law named as a practice area, with the areas served."],
            ["what does an antenuptial contract cost",
             "A plain description of the service, so the page answers the question that brought "
             "them."],
            ["lawyer for a CCMA case",
             "Employment and labour listed separately from general litigation."],
            ["conveyancing attorney Sandton",
             "Conveyancing named explicitly, with the suburbs covered."],
            ["is Mokoena & Partners registered",
             "Legal Practice Council registration shown alongside the firm's details."],
        ],
    },
    "nails": {
        "audience": "For nail bars and nail technicians",
        "heading": "A nail bar in Bryanston.",
        "business": "Lilac Nail Studio",
        "caveat": None,
        "rows": [
            ["BIAB near me",
             "The specific treatment named, not hidden inside \u201cnail services\u201d."],
            ["how much for acrylic infills",
             "Treatments listed individually so the page matches the question."],
            ["nail salon open Sunday Bryanston",
             "Sunday hours on the profile."],
            ["walk in nail bar Bryanston",
             "Whether walk-ins are taken, and how busy Saturdays work."],
            ["Lilac Nail Studio photos",
             "Photographs of actual work, and reviews people can find."],
        ],
    },
    "beauty": {
        "audience": "For beauty salons and skin clinics",
        "heading": "A skin clinic in Rosebank.",
        "business": "Vantage Skin Studio",
        "caveat": "Describing a treatment and naming who is qualified to perform it is "
                  "straightforward. Promising a result is not — treatment claims are "
                  "regulated. Where a therapist is registered with a body such as SAAHSP, say "
                  "so; it is both factual and persuasive.",
        "rows": [
            ["hydrafacial Rosebank",
             "The named treatment, with the suburb, rather than \u201cfacials\u201d."],
            ["laser hair removal near me",
             "Each modality listed separately, with who is qualified to perform it."],
            ["beauty salon open late Thursday",
             "Late-night hours stated rather than implied."],
            ["somatologist Rosebank",
             "The professional qualification named, because some customers search for it."],
            ["is Vantage Skin Studio any good",
             "Real photographs and reviews on the Google Business Profile."],
        ],
    },
    "travel": {
        "audience": "For travel agencies and tour operators",
        "heading": "A travel agency in Sandton.",
        "business": "Compass Travel Co",
        "caveat": "Membership of ASATA, or IATA accreditation, is factual, checkable and "
                  "worth stating — it is often the thing a nervous customer is actually "
                  "looking for. Publish nothing you cannot hold: prices and availability "
                  "move.",
        "rows": [
            ["travel agent for Mauritius packages",
             "Destinations named individually rather than \u201cworldwide travel\u201d."],
            ["help with a Schengen visa application",
             "Visa assistance listed as its own service."],
            ["group booking travel agent Johannesburg",
             "Group and corporate travel separated from leisure."],
            ["travel agent near Sandton",
             "The office location and the areas served."],
            ["is Compass Travel ASATA registered",
             "Membership and registration numbers shown with the business details."],
        ],
    },
    "accounting": {
        "audience": "For accounting practices",
        "heading": "An accounting practice in Randburg.",
        "business": "Nkosi Accounting",
        "caveat": "If the practice holds a professional designation \u2014 SAICA, SAIPA, ACCA or "
                  "similar \u2014 state it exactly as the body permits. It is the single most "
                  "checkable trust signal an accounting practice has.",
        "rows": [
            ["who can register my company at CIPC",
             "Company registration listed as its own service, in the customer's words."],
            ["accountant for a small business Randburg",
             "The size and type of client the practice actually serves."],
            ["accountant that does payroll for 5 staff",
             "Payroll named separately, with an indication of scale."],
            ["monthly bookkeeping Randburg",
             "Bookkeeping distinguished from annual financial statements."],
            ["is Nkosi Accounting SAIPA registered",
             "The professional designation and membership number on the profile."],
        ],
    },
    "tax": {
        "audience": "For tax practitioners",
        "heading": "A tax practitioner in Johannesburg.",
        "business": "Meridian Tax",
        "caveat": "Being findable is not the regulated part. Giving tax advice for reward is: "
                  "that requires registration with SARS and a recognised controlling body. "
                  "Publish the registration — it is a fact, and it is exactly what an anxious "
                  "taxpayer is checking for.",
        "rows": [
            ["I got a SARS audit letter",
             "Audit and dispute support named in the words of someone who just opened the post."],
            ["SARS eFiling help Johannesburg",
             "eFiling assistance listed as a service, with the areas served."],
            ["provisional tax deadline help",
             "Provisional tax separated from annual returns."],
            ["tax practitioner near me",
             "The category and the service area, for the customer who knows the term."],
            ["is Meridian Tax a registered practitioner",
             "SARS practitioner number and controlling body on the profile."],
        ],
    },
    "professional": {
        "audience": "For architects, engineers and consultants",
        "heading": "An architectural practice in Parktown.",
        "business": "Studio Verlaan",
        "caveat": "Statutory registration \u2014 SACAP for architects, ECSA for engineers \u2014 "
                  "is both a legal requirement for certain work and the clearest trust signal you "
                  "can publish. State it precisely.",
        "rows": [
            ["architect for house plan approval",
             "Plan approval and council submission named as a service."],
            ["engineer to sign off a structural plan",
             "The specific sign-off named, not \u201cconsulting services\u201d."],
            ["heritage approval Parktown",
             "Specialist work listed separately, with the areas it covers."],
            ["SACAP registered architect Johannesburg",
             "Registration shown alongside the practice details."],
            ["Studio Verlaan previous projects",
             "Photographs of completed work, and how to see more."],
        ],
    },
}


def _worked_example(v: dict) -> dict:
    """Page 10 for one edition."""
    blocks = [
        {"kind": "eyebrow", "text": "ONE WORKED EXAMPLE"},
        {"kind": "h1", "text": v["heading"]},
        {"kind": "para",
         "text": "People rarely search for a category. They search for the problem they woke up "
                 "with, in their own words. A profile that names only the category answers the "
                 "second kind of search and misses the first."},
        {"kind": "para",
         "text": f"{v['business']} is an invented business, used here to keep the argument "
                 "concrete. This is not a case study and not a prediction \u2014 it is one "
                 "profile, and the different questions a complete version of it can answer."},
        {"kind": "table", "widths": [0.36, 0.64],
         "header": ["WHAT SOMEONE SEARCHES", "WHAT ON THE PROFILE ANSWERS IT"],
         "rows": v["rows"]},
        {"kind": "statement",
         "text": "One business, five different questions, one complete profile. That is the whole "
                 "argument for completeness."},
    ]
    if v["caveat"]:
        blocks += [{"kind": "h3", "text": "Saying it accurately"},
                   {"kind": "small", "text": v["caveat"]}]
    return {"id": "worked-example", "blocks": blocks}


def pdf_pages(vertical: str = "general") -> list:
    """The 12-page document, tuned for one audience.

    Only two pages vary: the cover names the edition, and the worked example
    uses that trade's own searches. Everything else is shared, so a correction
    to the argument reaches every edition at once.
    """
    v = VERTICALS[vertical]
    pages = []
    for page in _PAGES:
        if page["id"] == "worked-example":
            pages.append(_worked_example(v))
        elif page["id"] == "cover" and v["audience"]:
            blocks = list(page["blocks"])
            blocks[0] = {"kind": "eyebrow", "text": f"WebScheduler Local \u00b7 {v['audience']}"}
            pages.append({**page, "blocks": blocks})
        else:
            pages.append(page)
    return pages



# --------------------------------------------------------------------------
# The social deck (1080 x 1350)
# --------------------------------------------------------------------------
# Slide kinds: hook, searches, split, numbered, lines, quote, cta.
# Hard rule enforced by the renderer: no slide may exceed MAX_SLIDE_WORDS.

MAX_SLIDE_WORDS = 34


def social_slides(variant: str = "linkedin"):
    """Return the deck for `variant` ("linkedin" or "meta")."""
    meta = variant == "meta"

    # Slide 2 and slide 8's kicker are the only places the argument names
    # Facebook. On Meta's own platforms it is reframed as paid reach.
    problem_title = (
        "Paid reach rents attention.\nSearch earns it."
        if meta else
        "You can boost a post to\nthousands and still miss\nthe one person typing this."
    )
    problem_body = (
        "Stop paying and the reach stops. A listing people can find keeps working."
        if meta else
        "Paid social interrupts people who are browsing. Local search reaches people "
        "who are already looking."
    )

    return [
        # 1 — the hook. Real searches, because that is what people recognise.
        {"kind": "searches",
         "eyebrow": "LOCAL VISIBILITY",
         "title": "This is how your\ncustomers actually\nsearch.",
         "items": [
             "plumber near Fourways",
             "dentist in Sandton",
             "family lawyer in Randburg",
             "nail salon in Bryanston",
         ]},

        # 2 — the problem.
        {"kind": "hook",
         "eyebrow": "THE PROBLEM",
         "title": problem_title,
         "body": problem_body},

        # 3 — the reframe, one pair only.
        {"kind": "split",
         "eyebrow": "THE SHIFT",
         "old_label": "OLD",
         "old_text": "Pay to place a message into a feed.",
         "new_label": "NEW",
         "new_text": "Build a listing that is found when people search."},

        # 4 — the system.
        {"kind": "numbered",
         "eyebrow": "THREE LAYERS",
         "title": "One compounding\nsystem.",
         "items": [
             ("01", "Build the listing", "Name, category, services, location, contact."),
             ("02", "Make it search-ready", "Clear service and location language."),
             ("03", "Turn discovery into action", "Call, message, visit, book."),
         ]},

        # 5 — completeness.
        {"kind": "lines",
         "eyebrow": "STEP 1",
         "title": "A listing is not a\nbusiness card.",
         "items": [
             "Business identity",
             "Services people search for",
             "Location and service area",
             "Contact and next steps",
             "Trust information",
             "Opening information",
         ]},

        # 6 — service + location.
        {"kind": "hook",
         "eyebrow": "STEP 3",
         "title": "One business.\nMany searches.",
         "body": "“I have a business in Sandton” is one search. "
                 "“Teeth whitening Sandton” is another. Both should find you."},

        # 7 — the plan.
        {"kind": "lines",
         "eyebrow": "30 DAYS",
         "title": "A practical\nrollout plan.",
         "items": [
             "Week 1 — Claim and complete the listing",
             "Week 2 — Strengthen service + location content",
             "Week 3 — Improve SEO foundations",
             "Week 4 — Measure and improve",
         ]},

        # 8 — the honest caveat. Keeps the deck claim-safe on its own.
        {"kind": "quote",
         "eyebrow": "ONE HONEST NOTE",
         "text": "SEO improves discoverability. It does not guarantee a ranking or a "
                 "number of leads. The strategy is to give search engines clear, "
                 "locally relevant information."},

        # 9 — the CTA. Image carousels have no clickable links, so the URL is
        #     set large and a QR code is drawn beside it.
        {"kind": "cta",
         "eyebrow": "FREE LOCAL LISTING",
         "title": "List your business\non WebScheduler Local.",
         "url_display": CTA_BARE,
         "url_target": cta_url("linkedin" if not meta else "meta", "social-deck"),
         "footnote": "Full playbook in the link — South Africa · 2026"},
    ]
