"""
WebScheduler Local Partner Program: how it works. The copy, in one place.

Rendered by ../playbook/build_playbook.py's build_pdf (see build_partner_guide.py),
so it shares the playbook's type, palette and badge.

An internal explainer for the team, written against the code on the
feature/partner-program branch (app/Services/PartnerService.php and
Config\\Partners). Every rule and number here is one the code enforces; when a
setting changes there, change it here. The worked example (Partner A,
Customer A, Customer B) is invented and is labelled as such.

Copy rules as for the proposal: no long dashes and no hyphenated words;
"business profile", never "listing"; paying never moves a business up.
"""

EDITION = "October 2026"

DOC_TITLE = "WebScheduler Local: How the Partner Program Works"
DOC_AUTHOR = "WebScheduler (Pty) Ltd"
DOC_SUBJECT = "The Partner Program rules, with a worked example of one partner and two customers"
DOC_KEYWORDS = "WebScheduler Local, Partner Program, affiliate, commission, Verified Business"

RUNNING_FOOTER = "WEBSCHEDULER LOCAL · HOW THE PARTNER PROGRAM WORKS · INTERNAL"

PARTNERS_URL = "https://local.webscheduler.co.za/partners"
VERTICALS = {"general": {"audience": None}}
ORANGE = "#F77F00"

# The settings the example is worked at. Config\Partners defaults and the
# live badge price on 9 Oct 2026.
PRICE = 29.99
RATE = 20
MONTHS = 12
HOLD = 30
MINIMUM = 500
COOKIE = 60
PER_PAYMENT = round(PRICE * RATE / 100, 2)  # 6.00


def cta_url(source: str, medium: str) -> str:
    return PARTNERS_URL


def R(amount: float) -> str:
    return f"R{amount:,.2f}".replace(",", " ")


A_PAYMENTS = 12   # Customer A pays every month for the whole window
B_PAYMENTS = 4    # Customer B pays February to May, then cancels
A_EARNS = PER_PAYMENT * A_PAYMENTS
B_EARNS = PER_PAYMENT * B_PAYMENTS
TOTAL = A_EARNS + B_EARNS
REVENUE = PRICE * (A_PAYMENTS + B_PAYMENTS)


def _ledger_rows() -> list:
    months = ["Nov 2026", "Dec 2026", "Jan 2027", "Feb 2027", "Mar 2027", "Apr 2027",
              "May 2027", "Jun 2027", "Jul 2027", "Aug 2027", "Sep 2027", "Oct 2027"]
    rows, running = [], 0.0
    for i, m in enumerate(months):
        a = PER_PAYMENT
        b = PER_PAYMENT if 3 <= i <= 6 else 0.0
        running += a + b
        rows.append([m, R(a), R(b) if b else "", R(a + b), R(running)])
    rows.append(["<b>Year one</b>", f"<b>{R(A_EARNS)}</b>", f"<b>{R(B_EARNS)}</b>",
                 f"<b>{R(TOTAL)}</b>", ""])
    return rows


def _rate_rows() -> list:
    rows = []
    for rate in (20, 30, 40):
        per = round(PRICE * rate / 100, 2)
        two = per * (A_PAYMENTS + B_PAYMENTS)
        year = per * 12
        need = -(-MINIMUM // year)  # ceiling
        rows.append([f"{rate}%", R(per), R(two), R(year), f"{int(need)} businesses"])
    return rows


_PAGES = [
    # --- 1. cover -------------------------------------------------------------
    {
        "id": "cover",
        "cover": True,
        "blocks": [
            {"kind": "lockup", "size_mm": 15},
            {"kind": "gap", "mm": 30},
            {"kind": "eyebrow", "text": "Internal guide"},
            {"kind": "cover_title", "text": "How the Partner Program<br/>[[works.]]"},
            {"kind": "cover_sub",
             "text": "The rules, the money and the edge cases, worked through one example: "
                     "Partner A, who brings us Customer A and Customer B."},
            {"kind": "gap", "mm": 8},
            {"kind": "numbered", "items": [
                ("01", "THE MODEL", "Five steps from application to payout."),
                ("02", "THE RULES", "What gets credited, what earns, and what never does."),
                ("03", "THE WORKED EXAMPLE", "Partner A, Customer A and Customer B, month by month."),
                ("04", "WHAT IF", "Every edge case, and what the system does with it."),
                ("05", "BEFORE LAUNCH", "The decisions this example makes plain."),
            ]},
            {"kind": "gap", "mm": 10},
            {"kind": "small",
             "text": f"WebScheduler (Pty) Ltd · {EDITION} · Written against the code on the "
                     "feature/partner-program branch, not yet live. Partner A, Customer A and "
                     "Customer B are invented examples."},
        ],
    },

    # --- 2. the model -----------------------------------------------------------
    {
        "id": "model",
        "blocks": [
            {"kind": "eyebrow", "text": "01 · The model"},
            {"kind": "h1", "text": "Partners bring businesses.<br/>[[We share what they pay.]]"},
            {"kind": "para",
             "text": "A partner is anyone who works with South African businesses: a web or "
                     "marketing agency, a bookkeeper, a business coach, a community group, or a "
                     "business already on WebScheduler Local. They share a personal link. When a "
                     "business they send us pays for Verified Business or an International Profile, "
                     f"they earn {RATE}% of each payment for that business's first {MONTHS} months. "
                     "Free profiles stay free, so they earn nothing."},
            {"kind": "numbered", "items": [
                ("01", "APPLY", "At /partners. They tell us who they work with and how they will share "
                                "the link, and accept the Partner Program terms."),
                ("02", "WE APPROVE", "At /admin/partners. Approval emails them their link, "
                                     "local.webscheduler.co.za/p/their-code, and a sign in link to "
                                     "their dashboard. Declined applicants get a polite no."),
                ("03", "THEY SHARE THE LINK", f"Opening it sets a cookie for {COOKIE} days. Adding "
                                              "?ref=their-code to any page on the site does the same."),
                ("04", "A BUSINESS SIGNS UP", "A new business profile created on that browser in "
                                              "those 60 days is credited to the partner."),
                ("05", "THE BUSINESS PAYS, THE PARTNER EARNS",
                 f"Each cleared payment earns {RATE}%, held {HOLD} days, then paid by EFT once a "
                 f"month when the partner is owed {R(MINIMUM)} or more."),
            ]},
            {"kind": "gap", "mm": 4},
            {"kind": "small",
             "text": "Separate from Recommend a business (/recommend), which is a visitor's tip "
                     "with no link and no money. Both run side by side."},
        ],
    },

    # --- 3. the rules -------------------------------------------------------------
    {
        "id": "rules",
        "blocks": [
            {"kind": "eyebrow", "text": "02 · The rules"},
            {"kind": "h1", "text": "Credited once.<br/>[[Earning for 12 payments.]]"},
            {"kind": "h3", "text": "When a business is credited to a partner"},
            {"kind": "table", "widths": [0.42, 0.58],
             "header": ["ALL OF THESE MUST HOLD", "WHY"],
             "rows": [
                 ["The partner is approved", "An applicant's or a suspended partner's link sets no cookie."],
                 [f"The link was opened in the last {COOKIE} days, on the same browser",
                  "The cookie lives on the device. A phone click and a laptop signup do not connect."],
                 ["The profile is new: created in the last hour",
                  "Someone typing in an email we already know gets their existing profile back, "
                  "and that business was not brought in by today's click."],
                 ["The profile has no partner yet", "The first credit stands. It is never moved by a later click."],
                 ["The owner's email is not the partner's", "A partner cannot refer their own business."],
             ]},
            {"kind": "h3", "text": "When a payment earns commission"},
            {"kind": "table", "widths": [0.42, 0.58],
             "header": ["ALL OF THESE MUST HOLD", "WHY"],
             "rows": [
                 ["PayFast reports it COMPLETE", "Cancellations and failed payments earn nothing."],
                 ["The partner is approved at the time", "A suspended partner stops earning. What they "
                                                        "already earned is still theirs."],
                 ["It was paid after the profile was credited", "Crediting a profile by hand never pays "
                                                               "out for the past."],
                 [f"It is one of the first {MONTHS} monthly payments",
                  "Counted from the subscription's first payment, so the 13th never earns."],
                 ["It has not earned before", "One commission per PayFast payment id, enforced by the "
                                              "database, so a repeated notification cannot pay twice."],
             ]},
            {"kind": "para",
             "text": f"<b>The amount</b> is the payment times the partner's rate: {RATE}% by default, or "
                     "a rate we set for that partner. The rate is stored on each commission, so "
                     "changing it later only affects payments from then on."},
        ],
    },

    # --- 4. the example: the cast and Customer A --------------------------------
    {
        "id": "example-a",
        "blocks": [
            {"kind": "eyebrow", "text": "03 · The worked example"},
            {"kind": "h1", "text": "One partner, two customers.<br/>[[Two different paths.]]"},
            {"kind": "table", "widths": [0.24, 0.76],
             "header": ["WHO", "IN THIS EXAMPLE (INVENTED)"],
             "rows": [
                 ["<b>Partner A</b>", "Lindiwe, who runs Sizwe Digital, a small web agency in Durban. "
                                      "Approved on 28 October 2026. Her link is "
                                      "local.webscheduler.co.za/p/sizwe-digital."],
                 ["<b>Customer A</b>", "Thabo's Plumbing. Signs up straight away and goes Verified."],
                 ["<b>Customer B</b>", "Northside Dental. Takes its time, starts free, goes Verified "
                                       "later and cancels after four months."],
             ]},
            {"kind": "label", "text": "Customer A · Thabo's Plumbing"},
            {"kind": "table", "widths": [0.18, 0.82],
             "header": ["DATE", "WHAT HAPPENS, AND WHAT THE SYSTEM DOES"],
             "rows": [
                 ["1 Nov 2026", "Lindiwe sends Thabo her link on WhatsApp. WhatsApp fetches it for the "
                                "preview: <b>not counted</b>, it is not a person. Thabo taps it on his "
                                "phone: <b>one click counted</b>, cookie set until 31 December."],
                 ["1 Nov 2026", "Thabo creates his business profile on the same phone. The profile is new, "
                                "has no partner, and is not Lindiwe's own: <b>credited to Partner A</b>."],
                 ["4 Nov 2026", "He sends his documents for Verified Business. We approve them."],
                 ["5 Nov 2026", f"First payment, {R(PRICE)}. Partner A earns <b>{R(PER_PAYMENT)}</b>, "
                                "held until 5 December. The 12 month window starts today."],
                 ["5th of each month", f"Each renewal earns another {R(PER_PAYMENT)}, each held 30 days."],
                 ["5 Oct 2027", f"The 12th payment. The last one that earns."],
                 ["5 Nov 2027", "The 13th payment. Thabo is still Verified and we keep his payment, but "
                                "<b>Partner A earns nothing more</b> from Customer A."],
             ]},
            {"kind": "para",
             "text": f"<b>Customer A in total:</b> 12 payments of {R(PRICE)} = {R(PRICE * 12)} to us, "
                     f"{R(A_EARNS)} of it to Partner A."},
        ],
    },

    # --- 5. Customer B --------------------------------------------------------------
    {
        "id": "example-b",
        "blocks": [
            {"kind": "eyebrow", "text": "03 · The worked example"},
            {"kind": "h1", "text": "Customer B takes the long way.<br/>[[It still counts.]]"},
            {"kind": "label", "text": "Customer B · Northside Dental"},
            {"kind": "table", "widths": [0.18, 0.82],
             "header": ["DATE", "WHAT HAPPENS, AND WHAT THE SYSTEM DOES"],
             "rows": [
                 ["10 Nov 2026", "Lindiwe emails the practice manager her link. She opens it on her work "
                                 "laptop: <b>one click counted</b>, cookie set until 9 January. She does "
                                 "not sign up yet."],
                 ["20 Dec 2026", "Forty days later she types our address straight into the same laptop and "
                                 "creates a free business profile. The cookie is still there: "
                                 "<b>credited to Partner A</b>, even though this visit did not come from "
                                 "the link."],
                 ["Dec to Feb", "A free profile. Nobody pays, so <b>nothing is earned</b>."],
                 ["15 Feb 2027", f"Northside goes Verified. First payment, {R(PRICE)}: Partner A earns "
                                 f"<b>{R(PER_PAYMENT)}</b>. Their 12 month window starts today, not in "
                                 "December: it runs from the first payment, not the signup."],
                 ["Mar to May", f"Three renewals, {R(PER_PAYMENT)} each."],
                 ["20 May 2027", "Northside cancels. The badge stays until 15 June, the date already paid "
                                 "for, then lapses. No more payments, so <b>no more commission</b>. What "
                                 "Partner A earned stays hers."],
             ]},
            {"kind": "para",
             "text": f"<b>Customer B in total:</b> 4 payments of {R(PRICE)} = {R(PRICE * 4)} to us, "
                     f"{R(B_EARNS)} of it to Partner A. If Northside came back as Verified in 2028, "
                     "those payments would be past its 12 month window and would earn nothing."},
            {"kind": "label", "text": "If the timing had been different"},
            {"kind": "bullets", "items": [
                f"Signing up on 15 January, 66 days after the click: the {COOKIE} day cookie has expired, "
                "so the profile is <b>not credited</b>. We can credit it by hand at /admin/partners if "
                "Lindiwe shows us the referral; only payments after that would earn.",
                "Signing up on her phone instead of the laptop: no cookie on that phone, so <b>not "
                "credited</b> automatically. Same fix by hand.",
                "Opening another partner's link before signing up: the <b>newer link wins</b> and that "
                "partner is credited. Once credited, a profile never moves.",
            ]},
        ],
    },

    # --- 6. the money --------------------------------------------------------------------
    {
        "id": "ledger",
        "blocks": [
            {"kind": "eyebrow", "text": "03 · The worked example"},
            {"kind": "h1", "text": f"Year one: {R(TOTAL)}.<br/>[[Month by month.]]"},
            {"kind": "para",
             "text": "What Partner A earns, by the month the payment was made. Each amount is held "
                     f"{HOLD} days before it can be paid, so November's commission becomes payable in "
                     "December, and so on."},
            {"kind": "table", "widths": [0.2, 0.2, 0.2, 0.2, 0.2],
             "header": ["MONTH", "CUSTOMER A", "CUSTOMER B", "EARNED", "RUNNING TOTAL"],
             "rows": _ledger_rows()},
            {"kind": "table", "widths": [0.5, 0.25, 0.25],
             "header": ["WHERE THE MONEY GOES, YEAR ONE", "AMOUNT", "SHARE"],
             "rows": [
                 ["Customers A and B pay us (16 payments)", R(REVENUE), "100%"],
                 ["Partner A's commission", R(TOTAL), f"{TOTAL / REVENUE * 100:.0f}%"],
                 ["We keep, before PayFast fees", R(REVENUE - TOTAL), f"{(REVENUE - TOTAL) / REVENUE * 100:.0f}%"],
             ]},
            {"kind": "small",
             "text": f"{RATE}% of {R(PRICE)} is R{PRICE * RATE / 100:.3f}, which rounds to "
                     f"{R(PER_PAYMENT)}. Commission is worked out on what PayFast reports as paid, so a "
                     "price change shows up automatically."},
        ],
    },

    # --- 7. the payout --------------------------------------------------------------------
    {
        "id": "payout",
        "blocks": [
            {"kind": "eyebrow", "text": "03 · The worked example"},
            {"kind": "h1", "text": "Today's settings never pay her.<br/>[[That is the finding.]]"},
            {"kind": "para",
             "text": f"We pay a partner once they are owed {R(MINIMUM)} or more. Partner A earns "
                     f"{R(TOTAL)} in a full year from two customers, so <b>she never reaches the "
                     f"minimum</b>. Her balance waits on her dashboard as Ready to pay, and nothing "
                     "goes to her bank."},
            {"kind": "statement",
             "text": f"At {RATE}% and {R(MINIMUM)}, a partner needs 7 businesses paying every month for "
                     "a whole year before we pay them once."},
            {"kind": "para",
             "text": "Software affiliate programs like Elementor's pay a share of a purchase worth "
                     "hundreds of dollars. Ours is a small monthly price, so the same percentage is a much "
                     "smaller amount, and it takes many customers to add up."},
            {"kind": "table", "widths": [0.14, 0.18, 0.24, 0.22, 0.22],
             "header": ["RATE", "PER PAYMENT", "PARTNER A, YEAR ONE", "ONE CUSTOMER, 12 MONTHS",
                        f"TO REACH {R(MINIMUM)} IN A YEAR"],
             "rows": _rate_rows()},
            {"kind": "label", "text": "When a partner does reach the minimum"},
            {"kind": "numbered", "items": [
                ("01", "THE SWEEP", "Every night at 03:50, commission whose 30 day hold has ended moves "
                                    "from Held to Ready to pay."),
                ("02", "THE PAYOUT LIST", f"/admin/partners/payouts lists everyone owed {R(MINIMUM)} or "
                                          "more, with the bank account they saved."),
                ("03", "WE PAY, THEN RECORD IT", "Pay by EFT from the bank first. Then enter the EFT "
                                                 "reference and press Mark paid: every Ready commission "
                                                 "becomes Paid, and the partner is emailed a statement."),
            ]},
        ],
    },

    # --- 8. what if -----------------------------------------------------------------------
    {
        "id": "what-if",
        "blocks": [
            {"kind": "eyebrow", "text": "04 · What if"},
            {"kind": "h1", "text": "Every edge case.<br/>[[And what happens.]]"},
            {"kind": "table", "widths": [0.44, 0.56],
             "header": ["IF", "THEN"],
             "rows": [
                 ["Customer A already had a profile and signs up again with the same email",
                  "They get a link to their existing profile. It is older than an hour, so it is not "
                  "credited."],
                 ["Lindiwe signs up her own agency through her own link",
                  "Same email as hers: not credited. A different email would get through the check, so "
                  "we look out for it and can remove the credit."],
                 ["A customer's payment is refunded within the 30 day hold",
                  "We cancel that commission at /admin/partners. It is never paid."],
                 ["A refund comes after the partner was already paid",
                  "A paid commission cannot be cancelled. We take it up with the partner directly."],
                 ["PayFast sends the same payment notification twice",
                  "The second is ignored, for the payment and for the commission."],
                 ["Recording a commission fails (database trouble)",
                  "The customer's payment still goes through. The nightly sweep rebuilds any commission "
                  "missed in the last 7 days."],
                 ["We suspend Partner A in March",
                  "Her link stops tracking and her customers' payments stop earning. What she earned "
                  "before is still hers. Reinstating her does not pay for the months in between."],
                 ["We change Partner A's rate to 30%",
                  "Payments from that moment earn 30%. Earlier commission keeps the rate it was worked "
                  "out at."],
                 ["A credited business is based abroad and pays for an International Profile",
                  "Those payments earn the same way: 12 payments, counted from its first International "
                  "payment."],
                 ["Customer A deletes their profile",
                  "No more payments, so no more commission. Earned commission stays."],
                 ["A partner never adds bank details",
                  "They appear on the payout list, marked, with no Mark paid button until they do."],
                 ["A declined applicant", "Their application is deleted 12 months after we decide, as the "
                                          "privacy policy promises."],
             ]},
        ],
    },

    # --- 9. who sees what ----------------------------------------------------------------
    {
        "id": "who-sees",
        "blocks": [
            {"kind": "eyebrow", "text": "What each person sees"},
            {"kind": "h1", "text": "Three views of<br/>[[the same example.]]"},
            {"kind": "h3", "text": "Partner A, on her dashboard (/partners/dashboard)"},
            {"kind": "bullets", "items": [
                "Her link, with Copy and Share on WhatsApp buttons.",
                "Clicks, in total and in the last 30 days: 2 in this example.",
                "Profiles created and how many are paying: 2 and 2.",
                "Every commission: the business name, the payment, what she earns, and Held, Ready to pay, "
                "Paid or Cancelled.",
                "Payments to her, with the EFT reference. A form to add or change her bank account.",
                "She signs in with a link we email her, valid once for an hour. No password.",
            ]},
            {"kind": "h3", "text": "Thabo and Northside Dental"},
            {"kind": "para",
             "text": "Nothing changes for them. They pay the normal price, never see the partner's "
                     "commission, and get exactly the same profile and search position as anyone else. "
                     "The cookie policy names the partner cookie, and it holds only Lindiwe's code."},
            {"kind": "h3", "text": "Us, at /admin/partners"},
            {"kind": "bullets", "items": [
                "Applications to approve or decline, with how they plan to promote us.",
                "Each partner's page: clicks, profiles credited, commission by state, payouts, and their "
                "rate. Credit a missed profile by hand, remove a credit, cancel a commission, suspend.",
                "The payout list, with each partner's bank account, decrypted only on that screen.",
                "A count of new applications in the admin bar.",
            ]},
        ],
    },

    # --- 10. safeguards ---------------------------------------------------------------------
    {
        "id": "safeguards",
        "blocks": [
            {"kind": "eyebrow", "text": "Safeguards"},
            {"kind": "h1", "text": "The program can fail.<br/>[[A customer's payment cannot.]]"},
            {"kind": "numbered", "items": [
                ("01", "PROFILES AND PAYMENTS COME FIRST",
                 "Crediting a signup and recording a commission both run after the profile or payment is "
                 "saved, and swallow their own errors. A partner problem never costs a business its "
                 "profile or its badge."),
                ("02", "NO DOUBLE PAYING",
                 "One commission per PayFast payment, enforced by the database. Payouts are all or "
                 "nothing: either every Ready commission is marked paid, or none is."),
                ("03", "NOTHING CLAIMED FROM A FORM",
                 "No form can set which partner a profile belongs to. Only the cookie at signup, or an "
                 "admin, can."),
                ("04", "PRIVACY",
                 "Clicks are daily totals: no IP addresses, no record of who clicked, and link previews "
                 "are not counted. Bank details are encrypted, and refused rather than stored plain if "
                 "the encryption key is missing. Terms section 19, privacy section 10 and the cookie "
                 "policy say all of this."),
                ("05", "NO ENUMERATION",
                 "Applying twice, or asking for a sign in link, gets the same answer whether or not the "
                 "email belongs to a partner."),
                ("06", "SPAM RULES",
                 "Partners may not spam, add businesses without the owner's agreement, refer their own "
                 "business, bid on our name in paid search, or promise a higher place in search results. "
                 "Breaking them can cost unpaid commission."),
            ]},
        ],
    },

    # --- 11. before launch ---------------------------------------------------------------
    {
        "id": "decisions",
        "blocks": [
            {"kind": "eyebrow", "text": "05 · Before launch"},
            {"kind": "h1", "text": "What this example<br/>[[asks us to decide.]]"},
            {"kind": "numbered", "items": [
                ("01", "THE RATE AND THE MINIMUM",
                 f"At {RATE}% and {R(MINIMUM)}, Partner A with two customers is never paid. Raising the "
                 "rate or lowering the minimum are settings, not code changes. A fixed bonus on a "
                 "customer's first payment would need a small code change."),
                ("02", "WHETHER RENEWALS AFTER 12 MONTHS SHOULD EARN",
                 "Today the 13th payment earns nothing. Some programs pay for as long as the customer "
                 "stays. It is one setting, partners.commissionMonths."),
                ("03", "HOW WE CHECK PARTNERS",
                 "We approve by hand. Decide what makes a yes, and how we spot a partner signing up "
                 "their own businesses under other emails."),
                ("04", "REFUNDS AFTER PAYOUT",
                 "The system will not reclaim money already paid. Decide whether we take it off the next "
                 "payout, and say so in the terms."),
                ("05", "TAX AND RECORDS",
                 "Partners are responsible for their own tax. We keep commission and payout records for "
                 "five years after the last payment. Confirm with the accountant how payouts are booked."),
                ("06", "THE RELEASE ORDER",
                 "Run the migration before the code goes live, or every admin page fails. Production "
                 "needs the encryption key and the partners:sweep cron line."),
            ]},
        ],
    },

    # --- 12. close ---------------------------------------------------------------------
    {
        "id": "close",
        "back_cover": True,
        "blocks": [
            {"kind": "gap", "mm": 4},
            {"kind": "eyebrow", "text": "In one paragraph"},
            {"kind": "cover_title", "text": "One link. One credit.<br/>[[Twelve payments.]]"},
            {"kind": "para",
             "text": f"Partner A's link credits a business that creates its profile on that browser within "
                     f"{COOKIE} days. From that business's first payment, she earns {RATE}% of each of "
                     f"its first {MONTHS} monthly payments, held {HOLD} days, and paid by EFT once she is "
                     f"owed {R(MINIMUM)}. Customer A earns her {R(A_EARNS)}; Customer B, who started free "
                     f"and cancelled, earns her {R(B_EARNS)}. Free profiles earn nothing, paying never moves "
                     "a business up, and nothing about the program can cost a customer their profile or "
                     "their payment."},
            {"kind": "gap", "mm": 6},
            {"kind": "table", "widths": [0.5, 0.5],
             "header": ["SETTING (CONFIG\\PARTNERS, OR .ENV)", "TODAY"],
             "rows": [
                 ["partners.commissionRate", f"{RATE}%"],
                 ["partners.commissionMonths", f"{MONTHS} payments"],
                 ["partners.holdDays", f"{HOLD} days"],
                 ["partners.minimumPayout", R(MINIMUM)],
                 ["partners.cookieDays", f"{COOKIE} days"],
                 ["partners.attributionWindowMinutes", "60 minutes"],
             ]},
            {"kind": "small",
             "text": "WebScheduler (Pty) Ltd · Internal · Rules: app/Services/PartnerService.php · "
                     "Tests: tests/database/PartnerProgramTest.php"},
        ],
    },
]


def pdf_pages(vertical: str = "general") -> list:
    return _PAGES
