<?php

namespace App\Support;

use App\Models\Domain;

/**
 * The prompt "Add with AI" copies: pasted into a coding agent inside the person's project, it puts the
 * project's site behind the edge through the MCP server, locks the origin, and stops for the person to
 * try it before DNS moves. It never carries a token: the agent asks for one, made by hand in Admin.
 */
class AddDomainWithAiPrompt
{
    /**
     * The prompt, with this installation's MCP server and token page in it.
     */
    public function render(): string
    {
        $mcpUrl = route('mcp');
        $tokensUrl = route('admin.tokens');
        $header = Domain::ORIGIN_SECRET_HEADER;

        return <<<PROMPT
            Put this project's website behind NiceTryBot, which sits at the edge in front of the site and keeps
            bots out, using the NiceTryBot MCP server. Work through these steps in order and check with me where
            it says so.

            ## 1. Connect to NiceTryBot
            If you don't have the `nicetrybot` MCP tools yet, ask me to connect them and wait. I make an API
            token by hand at {$tokensUrl} (tick "Add and change domains") and run this in my own terminal, so
            the token never passes through this chat:

                claude mcp add --transport http nicetrybot {$mcpUrl} --header "Authorization: Bearer <token>"

            Then I restart you, or reconnect with /mcp, so the tools load. (Other agents: I add an HTTP MCP
            server named nicetrybot at {$mcpUrl} with that Authorization header.) Never ask me to paste the
            token, and never write it into this repository.

            ## 2. Work out the two hostnames
            Find the public hostname this app is served at, from its config and deploy files (APP_URL,
            vapor.yml, vercel.json, fly.toml, nginx config...), for example www.myproject.com. The origin is the
            same app on a new hostname that only the edge uses, for example www-origin.myproject.com (or
            origin.myproject.com for an apex domain like myproject.com). Also find out:
            - How www. and the apex are served: one redirecting to the other, or both serving the site
              (`add-domain`'s `serves_www` covers a www. that serves the same site).
            - Where the app is hosted, and whether that host routes requests by their Host header: CloudFront,
              Laravel Vapor, Vercel, Netlify, Heroku, Render and most other platforms do. Step 4 needs it.
            - The endpoints machines call rather than people: webhooks (Stripe, GitHub...), APIs, package or
              update servers. They'll need allow rules before the domain enforces.
            Tell me all of it and wait for my yes before changing anything.

            ## 3. Serve the app at the origin hostname
            Make the app also answer on the origin hostname: add it on the host (its config, deploy file or
            dashboard) and create the DNS record the host gives for it. That's often where the public hostname
            points today, but platforms like Vapor, Vercel and CloudFront give each hostname its own target.
            Leave the public hostname's DNS alone. A new hostname's certificate or CDN can take up to an hour,
            so wait until https://<origin hostname> answers before testing it in step 6.

            ## 4. Add the domain to the edge
            Call `add-domain` with the public hostname and the origin URL (https://<origin hostname>). If the
            host routes by Host, pass `host_header_mode: rewrite`, or it will refuse the public hostname. The
            edge then sends the origin's own Host, so make the app build its links and redirects on the public
            hostname (APP_URL or the framework's equivalent), never on the Host it receives: otherwise a
            redirect sends visitors to the origin, which refuses them. Add the TXT record `add-domain` returns
            (or tell me exactly what to add if you can't reach DNS), then call `verify-domain`.

            ## 5. Refuse direct requests to the origin
            The edge sends every request with a `{$header}` header carrying the secret that
            `add-domain` returned (`get-origin-secret` gives it again). Make the app refuse requests without
            it, with a 403, before any other code runs:
            - Read the secret from an environment variable, NICETRY_ORIGIN_SECRET, set in every environment
              the site runs in. Never commit it. Compare it in constant time. In production, refuse every
              request if it's missing, rather than letting them all through.
            - Until the public hostname's DNS points at the edge, visitors still reach the app directly, without
              the header. So for now refuse only requests for the origin hostname; once DNS has moved, refuse
              every request without the header. Read which hosts to lock from an environment variable too (for
              example NICETRY_LOCKED_HOSTS=<origin hostname>, then *), so going live is a config change.
            - Match the Host header the request arrived with, not X-Forwarded-Host, which anyone can send.
            - If the host's own health checks call the app directly, let only that health-check path through.
            Deploy the change, or ask me to if you can't.

            ## 6. Test, then hand over to me
            Once https://<origin hostname> answers:
            - `curl -sI https://<origin hostname>/` should answer 403.
            - The same request with `-H "{$header}: \$NICETRY_ORIGIN_SECRET"` should be served.
            - Call `check-domain`: `origin.locked` should be true.
            Then stop and invite me to try it: opening https://<origin hostname> in my browser should be refused,
            and the `preview_url` from `check-domain` shows the site through the edge before DNS moves.

            ## 7. Go live, only when I say so
            Point the public hostname's DNS at the edge with the records `check-domain` lists, then call
            `check-domain` again until `dns.pointed_at_edge` is true, and switch the origin check from step 5 to
            every request. The domain starts in observe mode: suggest when to switch it to enforce with
            `set-protection-mode`, but let me decide. Before it enforces, offer allow rules (`create-rule`) for
            the endpoints machines call, from step 2, so they aren't treated as bots.
            PROMPT;
    }
}
