"""
WebScheduler Local Verified brochure: the copy, in one place.

Rendered by ../playbook/build_playbook.py's build_pdf (see build_brochure.py).
Recreated on 14 Nov 2026 from the October 2026 PDF, whose source was lost.
Every figure keeps its source on the evidence page; feature claims are the
live site's (/verified, /pricing, _verification_pitch.php). When a price, a
limit or a feature changes there, change it here and rebuild.

Copy rules: never call the product a directory; "business profile", not
"listing"; the plan is "Verified" (owner, 14 Nov 2026), not "the badge";
paying never moves anyone up the results; no dashes and no hyphenated words.
The example profile is invented and labelled as such. South Africa only:
this brochure is for local.webscheduler.co.za, never for .app.
"""

EDITION = "November 2026"

DOC_TITLE = "WebScheduler Local: Verified"
DOC_AUTHOR = "WebScheduler (Pty) Ltd"
DOC_SUBJECT = "What Verified adds to a WebScheduler Local business profile, and the evidence behind it"
DOC_KEYWORDS = ("WebScheduler Local, Verified, business profile, online bookings, local visibility, "
                "South Africa, advertising, search, maps, AI")

RUNNING_FOOTER = "WEBSCHEDULER LOCAL · VERIFIED"

HOST = "local.webscheduler.co.za"
VERIFIED_URL = f"https://{HOST}/verified"
ADD_URL = f"https://{HOST}/add-profile"

# The renderer has one edition; the playbook's per trade editions do not apply.
VERTICALS = {"general": {"audience": None}}

# Live prices, /pricing.json (14 Nov 2026).
PRICE = "R60"
YEARLY = "R625"
PER_DAY = "R2"          # R625 / 365 = R1.71; R60 x 12 / 365 = R1.97
LOCATIONS = 6           # PracticeLocationService::MAX_LOCATIONS
TEAM = 12               # TeamMemberService::MAX_MEMBERS
GALLERY = 8             # HandlesListingUploads::GALLERY_MAX

ORANGE = "#F77F00"


def cta_url(source: str, medium: str) -> str:
    return f"{VERIFIED_URL}?utm_source={source}&utm_medium={medium}&utm_campaign=verified-brochure-2026"


def _link(url: str, text: str) -> str:
    return f'<link href="{url}" color="{ORANGE}">{text}</link>'


_PAGES = [
    # --- 1. cover -------------------------------------------------------------
    {
        "id": "cover",
        "cover": True,
        "blocks": [
            {"kind": "lockup", "size_mm": 15},
            {"kind": "gap", "mm": 14},
            {"kind": "eyebrow", "text": "Verified"},
            {"kind": "cover_title",
             "text": "Are you paying for visibility,<br/>[[or building a business people can find?]]"},
            {"kind": "cover_sub",
             "text": "Adverts rent you attention for as long as the budget lasts. A Verified profile on "
                     "WebScheduler Local is a presence you build once, own, manage yourself, and keep."},
            {"kind": "gap", "mm": 4},
            {"kind": "preview", "name": "AI Data Engineering Co.",
             "meta": "Data engineering and AI · Rosebank, Johannesburg · Open until 17:00",
             "label": "Example profile for illustration",
             "rows": [("Verified", "Documents checked"),
                      ("Services", "Data pipelines, AI integration, cloud data platforms"),
                      ("Locations", "2 offices"),
                      ("Team", "8 team members · Hiring"),
                      ("Contact", "Call, WhatsApp, Book online")]},
            {"kind": "gap", "mm": 6},
            {"kind": "label", "text": f"{PRICE} a month or {YEARLY} a year · nothing to pay until your documents are checked"},
            {"kind": "small", "text": f"WebScheduler (Pty) Ltd · {EDITION}"},
        ],
    },

    # --- 2. when the budget runs out -------------------------------------------
    {
        "id": "budget",
        "blocks": [
            {"kind": "eyebrow", "text": "What happens when the budget runs out?"},
            {"kind": "h1", "text": "Adverts stop.<br/>[[The need doesn't.]]"},
            {"kind": "para",
             "text": "Facebook, Instagram and Google Ads can introduce your business to thousands of "
                     "potential customers. Digital advertising has value. But every campaign ends, and "
                     "when it does, your paid exposure ends with it."},
            {"kind": "para",
             "text": "The people who saw your advert scrolled past it, often while they did not need you "
                     "yet. When the geyser bursts or the tooth starts aching three months later, they "
                     "search. If your campaign has finished, they find whoever is still there."},
            {"kind": "table", "widths": [0.22, 0.78],
             "header": ["", "PEOPLE SEARCH WHEN THE NEED ARRIVES <sup>2</sup>"],
             "rows": [
                 ["<b>84%</b>", "of consumers searched online for a local business in the past three months"],
                 ["<b>73%</b>", "of local searches start on a mobile phone"],
                 ["<b>75%</b>", "used more than one channel in their most recent search for a local business"],
             ]},
            {"kind": "table", "widths": [0.3, 0.35, 0.35],
             "header": ["", "A CAMPAIGN", "A PROFILE YOU OWN"],
             "rows": [
                 ["Month 0 to 2", "Running: people see you", "Live, and starting to be found"],
                 ["Month 3", "Budget spent: you disappear", "Still there, still found"],
                 ["Month 12", "Gone, unless you pay again", "Older, more complete, better known to search"],
             ]},
            {"kind": "small", "text": "An illustration of the pattern, not measured data."},
            {"kind": "label", "text": "The question to ask"},
            {"kind": "statement",
             "text": "Do not just pay to be seen today. Build a presence that helps people find you tomorrow."},
        ],
    },

    # --- 3. impressions are not sales ----------------------------------------
    {
        "id": "impressions",
        "blocks": [
            {"kind": "eyebrow", "text": "Visibility is not the same as business"},
            {"kind": "h1", "text": "Impressions<br/>[[are not sales.]]"},
            {"kind": "para",
             "text": "Marketing reports celebrate impressions, reach and views. Reaching 10 000 people "
                     "sounds impressive. But how many visited your website? How many made an enquiry? "
                     "How many became paying customers?"},
            {"kind": "table", "widths": [0.22, 0.78],
             "header": ["", "WHAT 10 000 PEOPLE SEEING YOUR ADVERT TYPICALLY PRODUCES <sup>1</sup>"],
             "rows": [
                 ["<b>10 000</b>", "people see your advert"],
                 ["<b>270</b>", "click through (2.70% median click rate)"],
                 ["<b>23</b>", "become enquiries (8.54% of clicks). An enquiry is not yet a customer."],
             ]},
            {"kind": "small",
             "text": "Based on WordStream's 2026 Facebook Ads Benchmarks: median 2.70% click rate and 8.54% "
                     "conversion rate for lead campaigns (US campaigns, published September 2026)."},
            {"kind": "para",
             "text": "About 0.2% of the people reached become an enquiry. That is not a reason to stop "
                     "advertising. It is a reason not to let advertising be the only way people can "
                     "discover your business."},
            {"kind": "h3", "text": "Renting attention versus owning a presence"},
            {"kind": "table", "widths": [0.24, 0.34, 0.42],
             "header": ["", "PAID ADVERT", "VERIFIED PROFILE"],
             "rows": [
                 ["When it works", "While the budget lasts", "Every day, for as long as you keep it"],
                 ["Who it reaches", "People scrolling, mostly not looking for you",
                  "People looking for exactly what you do, when they need it"],
                 ["What it builds", "Nothing once the campaign stops",
                  "Pages, map pins and search signals that grow over time"],
                 ["Who controls it", "An agency or a platform's auction", "You. Edit it yourself, any time"],
                 ["Cost", "Spent per impression or per click", f"{PRICE} a month or {YEARLY} a year, flat"],
             ]},
        ],
    },

    # --- 4. free and Verified ------------------------------------------------
    {
        "id": "plans",
        "blocks": [
            {"kind": "eyebrow", "text": "Two ways to be on WebScheduler Local"},
            {"kind": "h1", "text": "Start free.<br/>[[Get Verified.]]"},
            {"kind": "para",
             "text": "Every business can have a profile on WebScheduler Local for free. Verified adds "
                     "trust, online bookings, and everything a business with more than one location, more "
                     "than one person or open positions needs."},
            {"kind": "table", "widths": [0.5, 0.25, 0.25],
             "header": ["", "FREE PROFILE", "VERIFIED"],
             "rows": [
                 ["Found in discovery results, on the map and in your category", "Included", "Included"],
                 ["Your own business profile page, with your address on the map", "Included", "Included"],
                 ["Phone, email, address, opening hours and a website link", "Included", "Included"],
                 ["Your services, with prices if you want to show them", "Included", "Included"],
                 [f"A logo and a photo gallery, up to {GALLERY} photos", "Included", "Included"],
                 ["Documents checked, shown beside your name in every result", "", "Included"],
                 ["Online bookings on your profile, with a calendar and reminders", "", "Included"],
                 [f"Up to {LOCATIONS} more branches or practices", "", "Included"],
                 [f"Up to {TEAM} team members, found by name", "", "Included"],
                 ["Job vacancies on the Jobs board, and first alerts for new work", "", "Included"],
                 ["Customer reviews you can switch on or off", "", "Included"],
                 ["<b>Price</b>", "<b>R0, no monthly fee</b>", f"<b>{PRICE} a month or {YEARLY} a year</b>"],
             ]},
            {"kind": "small",
             "text": "Verified: nothing to pay until we have checked your documents. Cancel any time."},
            {"kind": "h3", "text": "Why a checked business matters in South Africa"},
            {"kind": "table", "widths": [0.22, 0.78],
             "header": ["", "TRUST IS A DECIDING FACTOR"],
             "rows": [
                 ["<b>37%</b>", "of South Africans experienced a financial scam in the past 12 months <sup>4</sup>"],
                 ["<b>53%</b>", "of those scams happened on social media, more than on websites or shopping apps <sup>4</sup>"],
                 ["<b>51%</b>", "of South Africans encountered an online scam or attempted scam in a year <sup>5</sup>"],
             ]},
            {"kind": "small",
             "text": "<b>What we check.</b> Your business registration document and your ID. Verified means a "
                     "person at WebScheduler Local has confirmed the business is real, and customers can "
                     "see it beside your name."},
        ],
    },

    # --- 5. everything Verified adds ----------------------------------------
    {
        "id": "adds",
        "blocks": [
            {"kind": "eyebrow", "text": "Verified"},
            {"kind": "h1", "text": "Everything in free,<br/>[[and much more.]]"},
            {"kind": "numbered", "items": [
                ("01", "DOCUMENTS CHECKED",
                 "Verified on your profile and beside your name in every discovery result you appear "
                 "in. We check your business registration document and your ID."),
                ("02", "ONLINE BOOKINGS",
                 "Customers book a time with you right on your profile, and you get a calendar for your "
                 "team, reminders and a list of your customers. Included, at no extra cost."),
                ("03", "ALL YOUR LOCATIONS",
                 f"Up to {LOCATIONS} more branches or practices, each with its own address, phone number, "
                 "map pin and hours, and each described to Google as a business location in its own right."),
                ("04", "YOUR PEOPLE",
                 f"Up to {TEAM} team members, each with a photo, their role, their qualifications and "
                 "their areas of expertise. A search for one of them by name brings up your business too."),
                ("05", "POST JOBS, FREE",
                 "Advertise your vacancies on our Jobs board at no extra cost, set up so eligible "
                 "vacancies can appear in Google's job search <sup>8</sup>, and reply to customers who post a "
                 "request for your kind of service."),
                ("06", "CUSTOMER REVIEWS, ON YOUR TERMS",
                 "Let customers leave reviews on your profile, and switch reviews on or off yourself."),
                ("07", "FIRST TO HEAR ABOUT NEW WORK",
                 "When someone in your province asks for your kind of service, Verified businesses are "
                 "the first we alert."),
            ]},
            {"kind": "label", "text": f"For less than {PER_DAY} a day"},
            {"kind": "statement",
             "text": f"{PRICE} a month, or {YEARLY} for the whole year: one flat price for a presence that "
                     "works every day of it."},
        ],
    },

    # --- 6. search, maps and AI ----------------------------------------------
    {
        "id": "search",
        "blocks": [
            {"kind": "eyebrow", "text": "All in one"},
            {"kind": "h1", "text": "Built for search,<br/>[[maps and AI.]]"},
            {"kind": "para",
             "text": "Customers no longer find businesses in one place. They search on Google, open a map, "
                     "or ask an AI assistant such as ChatGPT. A WebScheduler Local profile is built to be "
                     "read correctly by all three, without you touching a line of code."},
            {"kind": "table", "widths": [0.22, 0.78],
             "header": ["", "HOW PEOPLE CHOOSE NOW"],
             "rows": [
                 ["<b>45%</b>", "of consumers use ChatGPT or other AI tools for local business recommendations <sup>3</sup>"],
                 ["<b>75%+</b>", "of South Africans access the internet through mobile data <sup>6</sup>"],
                 ["<b>97%</b>", "of consumers read reviews when choosing a local business <sup>3</sup>"],
             ]},
            {"kind": "table", "widths": [0.22, 0.78],
             "header": ["", "WHAT WE DO FOR EACH"],
             "rows": [
                 ["<b>Search engines</b>",
                  "Each profile, branch and vacancy is described in the structured format search engines "
                  "read, so Google understands what you do, where, and when you are open. Google says this "
                  "structured data is what makes job postings eligible for its job search <sup>8</sup>."],
                 ["<b>Maps</b>",
                  "Every address is placed on an exact map pin, for your main location and for each "
                  "branch, so “near me” searches can place you correctly."],
                 ["<b>AI assistants</b>",
                  "Clean, fast pages with a clear structure are easier for AI assistants and agents to "
                  "read accurately. Our home page passes both of Google's Agentic Browsing checks <sup>9</sup>."],
             ]},
            {"kind": "table", "widths": [0.3, 0.7],
             "header": ["WHAT YOU DON'T NEED", ""],
             "rows": [
                 ["No developer", "Your profile page is built and hosted for you."],
                 ["No agency", "You edit your details, services, photos, team and bookings yourself."],
                 ["No ad budget", "The profile keeps working after you stop paying anyone for clicks."],
                 ["No technical setup", "Map pins, structured data and job listings are handled automatically."],
             ]},
            {"kind": "small",
             "text": "<b>An honest word on AI and search.</b> No one can guarantee a position on Google or a "
                     "mention in ChatGPT; those systems decide what to show. What we can do is make sure your "
                     "business is described clearly, consistently and in the formats they read."},
        ],
    },

    # --- 7. speed -------------------------------------------------------------
    {
        "id": "speed",
        "blocks": [
            {"kind": "eyebrow", "text": "Measured, not claimed"},
            {"kind": "h1", "text": "Fast, by Google's<br/>[[own measure.]]"},
            {"kind": "para",
             "text": "Google says page experience, including loading speed and stability, aligns with what "
                     "its core ranking systems seek to reward, and strongly recommends that site owners "
                     "achieve good scores <sup>7</sup>. This is Google's own Lighthouse test of "
                     f"{HOST} <sup>9</sup>."},
            {"kind": "table", "widths": [0.25, 0.25, 0.25, 0.25],
             "header": ["PERFORMANCE", "ACCESSIBILITY", "BEST PRACTICES", "SEO"],
             "rows": [["<b>99</b>", "<b>97</b>", "<b>100</b>", "<b>100</b>"]]},
            {"kind": "table", "widths": [0.2, 0.8],
             "header": ["", "WHAT WAS MEASURED"],
             "rows": [
                 ["<b>0.4 s</b>", "First Contentful Paint: when the first content appears"],
                 ["<b>0.8 s</b>", "Largest Contentful Paint: when the main content has loaded"],
                 ["<b>1.3 s</b>", "Speed Index: how quickly the page fills in"],
                 ["<b>60 ms</b>", "Total Blocking Time: delay before the page responds"],
                 ["<b>0.005</b>", "Cumulative Layout Shift: how much the page jumps while loading"],
                 ["<b>2 / 2</b>", "Agentic Browsing: checks that AI agents can use the page"],
             ]},
            {"kind": "table", "widths": [0.34, 0.33, 0.33],
             "header": ["MEASURE", "GOOGLE'S “GOOD” <sup>7</sup>", HOST.upper()],
             "rows": [
                 ["Largest Contentful Paint", "2.5 seconds or less", "0.8 s, about 3 times faster"],
                 ["Cumulative Layout Shift", "Below 0.1", "0.005, 20 times under the limit"],
             ]},
            {"kind": "small",
             "text": "Google PageSpeed Insights, desktop test, Lighthouse 13.5.0, captured 14 October 2026 at "
                     "15:30. Lab results; scores vary between runs."},
            {"kind": "label", "text": "Why it matters to you"},
            {"kind": "statement",
             "text": "Your profile sits on the same fast platform, built for search. You get the "
                     "engineering without paying for it."},
        ],
    },

    # --- 8. evidence ------------------------------------------------------------
    {
        "id": "evidence",
        "blocks": [
            {"kind": "eyebrow", "text": "The evidence"},
            {"kind": "h1", "text": "Every claim,<br/>[[and where it comes from.]]"},
            {"kind": "small",
             "text": "The numbered references throughout this brochure point here. Where a source covers US "
                     "consumers rather than South African ones, we say so."},
            {"kind": "table", "widths": [0.05, 0.25, 0.38, 0.32],
             "header": ["", "CLAIM", "FINDING", "SOURCE"],
             "rows": [
                 ["1", "10 000 ad viewers produce about 270 clicks and 23 enquiries",
                  "Median click rate 2.70% and conversion rate 8.54% for lead campaigns, US campaigns, "
                  "April 2025 to June 2026",
                  "WordStream, Facebook Ads Benchmarks 2026, published 14 Sep 2026, reported by PPC Land"],
                 ["2", "People search when the need arises, mostly on a phone, across several channels",
                  "84% searched for a local business in the past three months; 73% of local searches start "
                  "on mobile; 75% used more than one channel",
                  "BrightLocal, Consumer Search Behavior 2026 (US consumers)"],
                 ["3", "AI assistants and reviews now shape local choices",
                  "45% use ChatGPT or other generative AI for local recommendations; 97% read reviews for "
                  "local businesses",
                  "BrightLocal, Local Consumer Review Survey 2026 (1 002 US adults, Feb 2026)"],
                 ["4", "Scams make trust a deciding factor in South Africa",
                  "37% of South Africans experienced a financial scam in the past 12 months; 53% of those "
                  "happened on social media",
                  "Visa, Stay Secure Study 2026, South Africa, released 9 Jun 2026 (Wakefield Research)"],
                 ["5", "Scam exposure is widespread",
                  "51% of South African respondents encountered an online scam or attempted scam in the "
                  "past 12 months",
                  "Kaspersky, press release, 14 Apr 2026"],
                 ["6", "South Africa is online through mobile",
                  "Over 75% of South Africans access the internet through mobile data (2024 data)",
                  "ICASA, State of the ICT Sector 2026 Report, reported by Engineering News, 1 May 2026"],
                 ["7", "Page experience matters to Google",
                  "Core Web Vitals align with what core ranking systems seek to reward; good LCP is within "
                  "2.5 s, good CLS below 0.1",
                  "Google Search Central, Understanding Core Web Vitals and Google search results"],
                 ["8", "Vacancies can appear in Google's job search",
                  "“Adding structured data makes your job postings eligible to appear in a special user "
                  "experience in Google Search results”",
                  "Google Search Central, Job posting (JobPosting) structured data"],
                 ["9", f"{HOST} scores 99 / 97 / 100 / 100",
                  "Desktop test, Lighthouse 13.5.0, 14 Oct 2026, 15:30; Agentic Browsing 2/2",
                  "Google PageSpeed Insights"],
             ]},
            {"kind": "small",
             "text": "Feature descriptions and pricing are WebScheduler Local's own. No source here guarantees "
                     "results for any individual business; search engines and AI assistants decide what "
                     "they show."},
        ],
    },

    # --- 9. close --------------------------------------------------------------
    {
        "id": "close",
        "back_cover": True,
        "blocks": [
            {"kind": "gap", "mm": 4},
            {"kind": "eyebrow", "text": "You manage it. You build it. You own it."},
            {"kind": "cover_title", "text": "Build a presence<br/>[[that keeps working.]]"},
            {"kind": "para",
             "text": "Use advertising when it makes sense. Make sure that when the campaign ends, customers "
                     "can still find your services, see your team, reach your branches, book a time and "
                     "contact you the moment they need you."},
            {"kind": "gap", "mm": 3},
            {"kind": "qr", "size_mm": 30, "caption": "Scan to get Verified"},
            {"kind": "gap", "mm": 3},
            {"kind": "h3", "text": _link(cta_url("pdf", "brochure"), f"{HOST}/verified")},
            {"kind": "para",
             "text": f"{PRICE} a month or {YEARLY} a year. Nothing to pay until your documents are checked. "
                     f"Or start with a free profile at {_link(ADD_URL, HOST + '/add-profile')}."},
            {"kind": "small",
             "text": _link("mailto:za_admin@webscheduler.co.za", "za_admin@webscheduler.co.za")
                     + " · +27 76 829 7070<br/>WebScheduler (Pty) Ltd · Reg. 2026/138798/07 · Delta Road, "
                     "Eltonhill, 2196<br/>Sources for every figure in this brochure are listed on page 8."},
        ],
    },
]


def pdf_pages(vertical: str = "general") -> list:
    return _PAGES
