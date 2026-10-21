# WebScheduler marketing repo

Two products live here: the WebScheduler marketing site (`marketing-site/`, see the
`marketing-site` skill) and WebScheduler Local, the CodeIgniter 4 app in `app/` (see the
`listing-service` skill). Releases follow the `deploy-production` skill.

## Copy rules for WebScheduler Local

- **Key words: discover, discovery, platform.** Local is "a local business discovery and
  visibility platform built for South Africa", never a directory. Our own feature is
  never "Search": say **Discover** on buttons, placeholders and aria labels, **discovery
  results** for results, and **discovered on the platform** for being found.
- Keep "search" only for other people's search: search engines, Google's job search,
  paid search ads, address lookup.
- Prefer "business profile" over "listing".
- No "—" and no hyphenated words in anything customers see (proper names such as
  KwaZulu-Natal are fine).
- Copy rules apply to what customers read, not to code: identifiers, routes, CSS classes
  and config names (`_search_composer`, `Config\Search`) stay as they are.

## Working rules

- Big changes go on a feature branch, run on local dev (localhost:8095) only, and are
  merged or released only on the owner's explicit go ahead.
- Another session may be using the main checkout: use a git worktree, never `git switch`
  there.
