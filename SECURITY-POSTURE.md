# WebScheduler Directory — Security Posture

**Last reviewed:** 25 August 2026
**Applies to:** the WebScheduler Directory service at webscheduler.co.za

This document answers the questions that supplier security questionnaires and tender
submissions typically ask. It is written to be checked: every technical control described
below is implemented in the application and can be demonstrated on request.

---

## 1. Certification status

**WebScheduler is not ISO 27001 certified and does not operate a certified Information
Security Management System (ISMS).**

We state this plainly rather than qualifying it. ISO 27001 certifies an organization's
management system against Clauses 4–10 of the standard, and is awarded only by an
accredited certification body following a Stage 1 and Stage 2 audit. WebScheduler has not
undergone that process, and we do not claim partial, pending, or equivalent status.

We hold no other security certifications (SOC 2, ISO 27017, ISO 27701).

What we do offer is a service built with a specific and verifiable set of technical
controls, described in sections 4 and 5, and an honest account of where those controls do
not yet reach, in section 6. We are glad to answer follow-up questions or complete a
customer-specific questionnaire.

---

## 2. What the service does, and what data it holds

WebScheduler Directory is a public directory of South African service businesses. Business
owners submit a listing, confirm ownership by email, and manage their own profile. An
optional paid "Verified Business" badge involves a documentary review.

| Category | Detail | Exposure |
|---|---|---|
| **Business listing data** | Business name, contact person, job title, description, phone numbers, website, address, trading hours | **Published deliberately.** This is the product — the data is intended to be public. |
| **Owner email address** | Used for ownership verification and the profile-management magic link | Never published. Not displayed on the public profile. |
| **Verified Business evidence** | Company registration documents (CIPC) and **a copy of the business owner's identity document** | Never published. Most sensitive category held. Access restricted as described in §5. |
| **Payment notification records** | Payer name and email contained in refused PayFast notifications | Never published. Automatically deleted after 365 days. |

We do not operate user accounts with passwords for business owners, do not run behavioural
advertising, and do not sell or share directory data with data brokers.

---

## 3. Hosting, data location, and subprocessors

| Party | Role | Processing location |
|---|---|---|
| **AWS Lightsail** | Application server and database | **Asia Pacific (Mumbai), `ap-south-1`** |
| **PayFast** | Payment processing and card handling | South Africa |
| **Cloudflare** | DNS, CDN, TLS termination and origin certificate | Global edge network |
| **CARTO / OpenStreetMap** | Map tiles and address geocoding | Global |

Outbound email is sent by the application server itself; no third-party bulk email provider
receives customer data.

### Cross-border transfer

Application infrastructure is hosted in the AWS Mumbai region, so personal information
processed through it is transferred outside South Africa. This is disclosed in our public
[privacy policy](https://webscheduler.co.za/privacy) — the region is named explicitly in
section 4 (Hosting and technology infrastructure) and the transfer basis is set out in
section 11 (Cross-border transfers). We rely on the contractual protections,
data-processing terms and regulatory safeguards offered by our service providers to meet
the requirements of POPIA section 72.

We flag this proactively because it is material. Customers with a data-residency
requirement should raise it with us directly — region selection is a deployment decision,
not an architectural constraint.

---

## 4. Payment security

**The application never receives, transmits, or stores cardholder data.**

Checkout is a signed form POST that hands the customer to PayFast's own hosted payment
page. Card details are entered on PayFast infrastructure and are never transmitted to, or
processed by, WebScheduler systems. Our PCI-DSS exposure corresponds to the SAQ-A merchant
profile. We store no card numbers, expiry dates, or CVV values in any form.

Inbound payment notifications from PayFast are treated as untrusted input and must pass
**four independent checks** before any subscription state changes:

1. **Signature reproduction** — the notification's signature is recomputed from its fields
   and compared using a timing-safe comparison (`hash_equals`).
2. **Source validation** — the request must originate from a recognised PayFast host.
3. **Server-to-server postback** — we contact PayFast directly and ask it to confirm it
   really sent the notification. A forged notification fails here even if the attacker
   somehow possessed our signing passphrase.
4. **Amount match** — the value received must match the value requested.

---

## 5. Technical controls

Mapped to ISO/IEC 27001:2022 Annex A control references so they can be cross-referenced
against a reviewer's own checklist. These are descriptions of implemented behaviour, not
statements of intent.

### Cryptography (A.8.24)

- Profile-management and email-verification links are **bearer credentials**, generated as
  256-bit cryptographically secure random values and **stored hashed (SHA-256), never in
  plaintext**. A disclosure of the database — via a leaked backup, a read-only injection,
  or a support export — yields no usable links. (SHA-256 rather than a slow KDF is
  deliberate: these are full-entropy machine-generated values, not human-chosen secrets,
  so a work factor would add cost without adding resistance.)
- The administrative credential is stored as an adaptive salted hash (PHP `password_hash`,
  currently bcrypt) and verified with `password_verify`. No administrative password is
  stored in recoverable form.
- HTTP Strict Transport Security is emitted over HTTPS with a two-year max-age and
  `includeSubDomains`.

### Authentication and access control (A.5.15, A.8.3, A.8.5)

- Administrative login is **rate-limited to 5 attempts per 15 minutes per IP address**, and
  the session identifier is regenerated on successful login to prevent session fixation.
- Business owners authenticate by single-use magic link. Management tokens expire after
  **1 hour**; verification tokens after **48 hours**. A management token is consumed on
  redemption and exchanged for a session.
- The profile edit path writes only through a **server-side field allowlist**. A crafted
  submission cannot alter a listing's publication status, URL slug, owner email address,
  featured flag, or verified flag — those fields are not in the allowlist and are
  unreachable from the owner-facing form regardless of what is posted.
- **Verification documents are stored outside the web root** and are never served directly
  by the web server. They are readable only by streaming through a route inside the
  administrator-authenticated area.

### Application security (A.8.26, A.8.28)

- **Uploaded documents are validated three ways** — claimed file extension, server-detected
  MIME type, and leading magic bytes must all agree. The accepted set is restricted to PDF,
  JPEG and PNG; Office formats (macro-bearing) and archives are rejected outright.
- A **Content Security Policy is enforced**, not merely reported. Inline scripts and inline
  event handlers are blocked; styles require a per-response nonce; form submission targets
  are restricted to an explicit allowlist.
- **CSRF protection** is enabled on all state-changing requests, with per-request token
  randomization to mitigate BREACH-style side-channel attacks.
- Session and CSRF cookies are set `Secure` (in production), `HttpOnly`, and
  `SameSite=Lax`. Sessions expire after 2 hours.
- Database access is via a parameterised query builder throughout.

### Security headers (A.8.23)

Aligned to the OWASP Secure Headers Project: `X-Frame-Options: DENY`,
`X-Content-Type-Options: nosniff`, `X-Permitted-Cross-Domain-Policies: none`,
`Cross-Origin-Opener-Policy: same-origin`, and a `Permissions-Policy` that denies every
powerful browser feature (camera, microphone, geolocation, payment, USB and others).

`Referrer-Policy` is set to `no-referrer` as a specific mitigation: because the profile
management link carries a token in the URL path, a laxer policy would let that token leak
in the `Referer` header of any subsequent resource request.

### Backup and resilience (A.8.13)

- Nightly database dump on an automated schedule, with 14-day retention.
- Our deployment runbook treats **rehearsing a restore as mandatory** rather than optional,
  on the principle that an untested backup is a belief rather than a backup.

### Logging and data minimization (A.8.15, A.8.11)

- The outbound mail subsystem records **only the recipient's domain** — never the full
  address, subject, or message body.
- Content Security Policy violations are logged for review.
- Payment notification records containing payer details are automatically pruned after
  365 days.

### Retention and deletion (A.5.33, A.8.10)

Every category of non-public data has a defined maximum lifetime, enforced automatically
by a daily scheduled task rather than by anyone remembering to run it:

- **Verification evidence** (registration documents and ID copies) — retained while the
  badge is active or paused. Once a subscription lapses or an application is rejected, the
  documents are deleted **12 months** later. Documents replaced by a newer submission are
  deleted **90 days** after replacement.
- **All verification evidence is deleted in full when the listing is deleted**, superseded
  attempts included.
- **Refused payment notifications** (containing payer name and email) — deleted after
  **365 days**.
- **Magic-link tokens** — expire after 1 hour (management) or 48 hours (verification), and
  are cleared on use.

These periods are published in our [privacy policy](https://webscheduler.co.za/privacy),
section 12, so the commitment is public rather than internal. Owners may also request
deletion of verification documents at any time.

### Separation of environments (A.8.31)

Development and production configuration are separated, with a documented, explicitly
enumerated set of credentials that must not propagate from a developer machine to a
production environment — including a deprecated cleartext administrative password path
that must be absent in production.

---

## 6. Known gaps

We include this section deliberately. A questionnaire response with no gaps is a response
nobody checked, and a reviewer who finds an undisclosed weakness will reasonably discount
everything else in the document.

| # | Gap | Current mitigation | Direction |
|---|---|---|---|
| 1 | **No individual administrator identity.** Administrative access uses a single shared credential. Actions cannot be attributed to a named person, and multi-factor authentication is not available. | The credential is stored hashed, never in recoverable form. Login is rate-limited to 5 attempts per 15 minutes; the administrative area is not publicly linked; the configuration file is readable only by its owner and the web server group (mode 0640, no world access). | Per-administrator accounts with individual credentials and MFA. **Our highest-priority security item.** |
| 2 | **Limited administrative audit trail.** The Verified Business review lifecycle is event-logged, but general administrative edits and deletions are not recorded against an actor. | Verification decisions — the highest-impact actions — are logged. | Attributable audit log, dependent on gap 1. |
| 3 | **Verification documents are not encrypted at rest.** They are protected by filesystem permissions and administrator session gating, but are not separately encrypted at the application layer. | Stored outside the web root; no direct URL access; administrator authentication required. | Application-layer encryption for the document store. |
| 4 | **Backups are co-located with the production host.** Off-host and independently encrypted backup copies are not yet in place, so loss of the host would affect both the database and its local backups. | Nightly dumps with 14-day retention; provider-level instance snapshots. | Encrypted off-host backup replication. |
| 5 | **No automated pre-deployment gate.** Releases are performed manually. There is an automated test suite, but it is not enforced as a merge or deploy gate, and dependency vulnerability scanning is not automated. | Small, controlled change volume; single deployer; documented release and rollback procedure. | CI pipeline enforcing tests and dependency scanning before release. |

---

## 7. Incident response

We do not currently operate a formally documented incident response plan with defined
severity tiers and response-time commitments. Our practical commitment is to notify
affected customers without undue delay on becoming aware of a security compromise
affecting their data, and to report to the Information Regulator where POPIA section 22
requires it.

Customers requiring contractual breach-notification timelines should raise this with us
directly so it can be addressed in the service agreement.

---

## 8. Contact

Security questions, vulnerability reports, and questionnaire follow-ups:
**za_admin@webscheduler.co.za**

We welcome good-faith vulnerability reports and will not pursue action against researchers
who report responsibly, avoid privacy violations and service disruption, and give us
reasonable opportunity to remediate before disclosure.
