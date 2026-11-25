# BEERxCHG

A Secret Santa beer exchange at https://drunkencomputing.com/BEERxCHG/.
Plain PHP 8.2+ with SQLite. No framework, no build step, no JavaScript.
Fonts (Mountains of Christmas, Bitter; SIL OFL) are self-hosted in `assets/fonts/`
because the CSP only allows same-origin resources.

## How it works

1. **Sign up / log in with just an email.** The site emails a 6-digit code
   (valid 15 min, 5 tries, rate-limited). Entering it starts a session that
   lasts **exactly 7 days**, then you log in again.
   **New sign-ups also answer a challenge question** ("How did you find out
   about the beer exchange?"). An answer passes if, lowercased with
   everything but letters removed, it contains one of the accepted answers.
   Organizers edit that list on the Organizer page (stored in the `settings`
   table, 3+ letters each, at least one); until they do, `challenge_keywords`
   from the gitignored `config.local.php` is used. Either way the answers
   never land in git.
   Returning users and organizers skip it. Wrong answers are limited to 10
   per IP per hour, and the answer is shown on the Organizer page.
2. **Profile.** Name, shipping address, mobile phone, the one style you love,
   the one style you never want, optional notes, and **the rules**: a numbered
   list (`rules` in `includes/config.php`) with a checkbox per rule. Every box
   must be checked to be in the draw. The agreement stores a hash of the rule
   text, so editing the rules makes everyone re-agree before the draw.
3. **Organizer approval.** New sign-ups wait for an organizer (emails in
   `admin_emails`) to approve them, so a stranger who finds the URL can't
   end up with someone's home address. Organizers get an email per sign-up.
   - **Founders** are the emails in `admin_emails` (config.local.php). They
     are permanent organizers: they can't be demoted, deleted or have their
     email changed from the site or the API, so there's always an organizer.
   - Any organizer can **promote** a participant (`users.is_organizer`), which
     also approves them, and demote other non-founders (not themselves).
   - `admin_user.php?id=N` lets organizers edit anyone's email, address,
     phone, styles and notes (blanks allowed, filled-in values validated),
     approve, promote/demote, mark shipped, and log them out everywhere.
     Nobody can check the rules for someone else.
4. **The draw** (Organizer page). Shuffles everyone who is *approved +
   complete + agreed* into one loop (A→B→C→…→A), so nobody gets themselves
   and nobody just swaps with one person. It closes sign-ups and emails each
   sender their match's name and tastes. Addresses are only shown after
   logging in and are never emailed.
5. **Shipping.** Senders mark "I shipped it" (optional tracking). The
   recipient gets an email and sees the status on their page.

## API

JSON REST API at `/BEERxCHG/api/v1/` covering everything the Organizer page
does (participants, settings, rules, the draw, shipments, match emails). The
logic is in `includes/api.php` (`api_handle()` is a pure function so the tests
can call it), and `api/index.php` is the HTTP shim. `.htaccess` routes
`api/v1/*` there and copies `Authorization` into the CGI environment.

- Keys are generated on the Organizer page (`read` or `write` scope), shown
  once, and stored as SHA-256 hashes in `api_keys`. Send them as
  `Authorization: Bearer bxk_…` (or `X-API-Key`). Cookies are not accepted.
- Docs: `docs/index.php`, visible to approved, logged-in users (organizers count as approved). `.htaccess` 403s
  every other file under `docs/`.

## Files

| Path | What |
|---|---|
| `index.php` | Landing + "email me a code" |
| `verify.php` | Enter the code |
| `me.php` | Profile, your match, shipping status |
| `admin.php` | Organizer: settings, approvals, draw |
| `includes/lib.php` | DB, auth, CSRF, mail, draw, layout |
| `includes/config.php` | Defaults (committed) |
| `includes/config.local.php` | Admin emails etc. (gitignored) |
| `data/` | `beerxchg.sqlite`, `secret.key`, `mail.log` (gitignored, 403 over HTTP) |

## Security notes

- Login codes are stored as HMACs (key in `data/secret.key`). Sessions are
  stored as SHA-256 hashes of a random 256-bit token.
- Cookies are `Secure; HttpOnly; SameSite=Lax`, scoped to `/BEERxCHG/`.
- Every POST checks a CSRF token (double-submit cookie) plus the `Origin` header.
- Strict CSP (no scripts at all), `Cache-Control: no-store` on all pages.
- `.htaccess` returns 403 for `includes/ data/ tests/`, dotfiles and `*.md`.

## Config

Copy `includes/config.local.example.php` to `includes/config.local.php` and set
`admin_emails`. Mail goes out through PHP `mail()` (DreamHost's sendmail)
from `beerxchg@drunkencomputing.com`. Set `mail_reply_to` if you want replies
to land somewhere real.

## Tests

One PHP process each, against a scratch DB (the host has a very low process
limit, so run these one at a time):

    BX_DATA_DIR=/path/to/scratch BX_MAIL_MODE=log php tests/smoke.php
    BX_DATA_DIR=/path/to/scratch BX_MAIL_MODE=log php tests/api.php
    BX_DATA_DIR=/path/to/scratch BX_MAIL_MODE=log php tests/render.php me.php user drawn

## Backup

Everything lives in `data/beerxchg.sqlite`:

    sqlite3 data/beerxchg.sqlite ".backup data/backup-$(date +%F).sqlite"
