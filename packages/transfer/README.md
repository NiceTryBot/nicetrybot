# Transfer

Moves an origin from one NiceTryBot to another, such as from the hosted service to a panel on your own
server. Free, and it comes with NiceTryBot. It moves origins, so it needs the Origins add-on on both
panels, installed and on.

## What moves

- **The origin:** its name, URL, Host header handling, timeout, Server header, HSTS, asset caching,
  www folding, protection mode, authorize URL and **its secret**, so your app keeps accepting requests
  from the new edge.
- **Its groups and sites:** every site by hostname, with the protection mode a site keeps for itself.
  New sites are live straight away, as sites an origin vouched for are. Each gets a new preview
  hostname on the new panel.
- **The team's rules and gym routines**, by name. A routine's "has a verdict from" condition names its
  rule, so it points at the same rule on the new panel.

Traffic history, tags, bans, verdicts and anything about the team's people stay behind.

## Two ways in

Both start on the **Transfer** page, from the Domains page's Transfer button (for those who can change
domains).

1. **Pull it.** On the old panel, make an API token in Admin → API tokens on the team that has the
   origin, with "Add and change domains". On the new panel, enter the old panel's address and the token,
   pick the origin and bring it here. The token is used for those two requests and then forgotten.
2. **A transfer file.** On the old panel's Transfer page, under "Take an origin to another panel",
   download the origin's transfer file, then upload it on the new panel. The file holds the origin's
   secret: keep it like a password and delete it once it's imported.

After either, point each site's DNS at the new edge (the records are on each domain's page). Certificates
follow on demand. Until DNS moves, the old edge keeps serving the sites.

## Importing again

An import is re-runnable, and all of it happens or none of it. The origin is matched by its URL (by name
when the team has several at that URL), sites by hostname, rules and routines by name. What's there is
updated, nothing is duplicated, and the page says what was added, updated or left alone. Sites the
bundle doesn't list are left as they are; the origin's groups are made to match it.

What doesn't come across, and why:

- **A hostname another team on this panel has** stays theirs.
- **A hostname this team has outside the origin** is left as it is.
- **A rule that tests something this panel hasn't got** (a field from an add-on that isn't installed)
  can't be saved here, and a routine waiting for that rule's verdict is left out rather than let
  everyone in.
- **Obstacles from an add-on this panel hasn't got** come across in their routines and are reported;
  the gym skips them until the add-on is here.

Sites behind an origin don't count toward the domain limit, so a transfer never runs into it.

## The pull API

On the panel being pulled from, behind the same API token check as the MCP server:

- `GET /transfer/v1/origins`: `{"origins": [{"id", "name", "origin_url", "sites"}]}` for the token's team.
- `GET /transfer/v1/origins/{id}`: that origin's transfer bundle.

The token must allow "Add and change domains", and its user must still be allowed to on the team. A
request without such a token, for another team's origin, or while Origins is off gets the same 404 a
path that was never routed gets.

## The bundle

JSON: `{"format": "nicetrybot-transfer", "version": 1, "exported_at", "source", "origin": {…, "zones",
"sites"}, "rules", "routines"}`. Ids don't travel. Every field is checked against this version's schema
(`TransferBundle`): an unknown field anywhere refuses the whole, every hostname must be a hostname,
every obstacle setting a plain value, and a bundle is at most 10 MB.

## Pulls are held to public HTTPS hosts

The address is someone's input, so a pull only goes to `https://`, and only when every address the name
resolves to is public. Not private ranges, loopback, link-local (so not a cloud's metadata service),
carrier-grade NAT, or IPv4 written as IPv6. The connection is pinned to the address that was checked,
and redirects aren't followed. In the local environment only, a panel on your own network or over plain
HTTP is allowed, for trying it between two local panels.

## Paid instead of free

To sell it: set `"edition": "paid"` in `composer.json` and give it a price in the marketplace catalog,
add `packages/transfer` to `.github/public/exclude.txt` so the public release leaves it out, and release
it with the add-on release workflow.
