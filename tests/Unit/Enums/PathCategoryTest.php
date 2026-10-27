<?php

use App\Enums\PathCategory;

dataset('categorized paths', [
    'env file' => ['/.env', PathCategory::ProbeCreds],
    'env variant' => ['/.env.local', PathCategory::ProbeCreds],
    'nested env' => ['/dashboard/.env.prod', PathCategory::ProbeCreds],
    'aws credentials' => ['/.aws/credentials', PathCategory::ProbeCreds],
    'shell history' => ['/.bash_history', PathCategory::ProbeCreds],
    'secrets file' => ['/user_secrets.yml', PathCategory::ProbeCreds],
    'frontpage password' => ['/_vti_pvt/service.pwd', PathCategory::ProbeCreds],
    'compose file' => ['/docker-compose.yml', PathCategory::ProbeSource],
    'backup archive' => ['/backup.tar.gz', PathCategory::ProbeSource],
    'laravel log' => ['/storage/logs/laravel.log', PathCategory::ProbeConfig],
    'xml config' => ['/config.xml', PathCategory::ProbeConfig],
    'git head' => ['/.git/HEAD', PathCategory::ProbeSource],
    'agent instructions' => ['/AGENTS.md', PathCategory::ProbeSource],
    'copilot instructions' => ['/.github/copilot-instructions.md', PathCategory::ProbeSource],
    'jira metadata' => ['/s/abc/_/;/META-INF/maven/com.atlassian.jira/pom.properties', PathCategory::ProbeSource],
    'frontend config' => ['/config.js', PathCategory::ProbeConfig],
    'vite env' => ['/@vite/env', PathCategory::ProbeConfig],
    'wordpress login' => ['/wp-login.php', PathCategory::ProbeAdmin],
    'cpanel proxy' => ['/___proxy_subdomain_whm/login', PathCategory::ProbeAdmin],
    'xmlrpc' => ['/xmlrpc.php', PathCategory::ProbeCms],
    'php shell' => ['/shell.php?cmd=id', PathCategory::ProbeExploit],
    'exchange' => ['/ecp/Current/exporttool/microsoft.exchange.ediscovery.exporttool.application', PathCategory::ProbeExploit],
    'graphql' => ['/api/graphql', PathCategory::ProbeApi],
    'docker registry' => ['/v2/_catalog', PathCategory::ProbeApi],
    'favicon' => ['/favicon.ico', PathCategory::BrowserAsset],
    'security.txt' => ['/.well-known/security.txt', PathCategory::BrowserAsset],
    'edge diagnostic' => ['/__nicetry/fingerprint', PathCategory::EdgeDiagnostic],
    'built script' => ['/build/assets/app-3f9a.js', PathCategory::StaticAsset],
    'livewire script' => ['/livewire/livewire.js', PathCategory::StaticAsset],
    'named env file' => ['/sendgrid.env', PathCategory::ProbeCreds],
    'bare credentials' => ['/credentials', PathCategory::ProbeCreds],
    'service account key' => ['/config/gcp-credentials.json', PathCategory::ProbeCreds],
    'ai service keys' => ['/anthropic.json', PathCategory::ProbeCreds],
    'terraform state' => ['/terraform.tfstate.backup', PathCategory::ProbeCreds],
    'gitlab ci' => ['/.gitlab-ci.yml', PathCategory::ProbeSource],
    'claude settings' => ['/.claude/settings.local.json', PathCategory::ProbeSource],
    'editor swap file' => ['/.drone.yml.swp', PathCategory::ProbeSource],
    'backup folder' => ['/bak/', PathCategory::ProbeSource],
    'dotnet settings' => ['/appsettings.Production.json', PathCategory::ProbeConfig],
    'debugbar' => ['/_debugbar/open', PathCategory::ProbeConfig],
    'cms install folder' => ['/cms/', PathCategory::ProbeCms],
    'year archive' => ['/2019/', PathCategory::ProbeCms],
    'path traversal' => ['/static/%2e%2e%2f%2e%2e%2fproc/self/environ', PathCategory::ProbeExploit],
    'weblogic' => ['/wls-wsat/CoordinatorPortType/', PathCategory::ProbeExploit],
    'php7 shell' => ['/scx.php7', PathCategory::ProbeExploit],
    'api docs' => ['/api/docs.json', PathCategory::ProbeApi],
    'llms.txt' => ['/llms.txt', PathCategory::BrowserAsset],
    'front-end env bundle' => ['/env.js', PathCategory::ProbeCreds],
    'ai provider config' => ['/config/openai.json', PathCategory::ProbeCreds],
    'cloudflare dev vars' => ['/.dev.vars.production', PathCategory::ProbeCreds],
    'platform manifest' => ['/wrangler.toml', PathCategory::ProbeSource],
    'sqlite database' => ['/db.sqlite3', PathCategory::ProbeSource],
    'livewire source from its source map' => ['/js/features/supportFileUploads.js', PathCategory::ProbeSource],
    'astro server source map' => ['/_astro/pages/index.astro.mjs.map', PathCategory::ProbeSource],
    'actuator look-alike' => ['/management/env', PathCategory::ProbeConfig],
    'n8n api' => ['/rest/workflows', PathCategory::ProbeAdmin],
    'joomla fingerprint' => ['/plugins/editors/jce/jce.xml', PathCategory::ProbeCms],
    'joomla script' => ['/media/system/js/core.js', PathCategory::StaticAsset],
    'saas dashboard route' => ['/settings/api-keys', PathCategory::ProbeSaas],
    'phishing kit page' => ['/giris/index.php', PathCategory::ProbePhish],
    'agent card' => ['/.well-known/agent-card.json', PathCategory::ProbeAgent],
    'mcp server card' => ['/.well-known/mcp/server-card.json', PathCategory::ProbeAgent],
    'shell under well-known' => ['/.well-known/zaza.php', PathCategory::ProbeExploit],
    'encoded git config' => ['/%2F.git%2Fconfig', PathCategory::ProbeExploit],
    'langflow build' => ['/api/v1/build_public_tmp/test-flow-id/flow', PathCategory::ProbeExploit],
    'openai proxy' => ['/v1/chat/completions', PathCategory::ProbeApi],
    'script source map' => ['/build/assets/app-3f9a.js.map', PathCategory::BrowserAsset],
    'acme challenge' => ['/.well-known/acme-challenge/lw-jbLCmhJ00', PathCategory::BrowserAsset],
    'published livewire script' => ['/vendor/livewire/livewire.js', PathCategory::StaticAsset],
    'next build manifest' => ['/_next/static/x9Yh2/_buildManifest.js', PathCategory::StaticAsset],
    'wordpress core script' => ['/wp-includes/js/wp-emoji-release.min.js?ver=6.4', PathCategory::CmsAsset],
    'wordpress theme stylesheet' => ['/wp-content/themes/knowall-child/style.css', PathCategory::CmsAsset],
    'wordpress upload' => ['/wp-content/uploads/2021/04/logo1.png', PathCategory::CmsAsset],
    'wordpress font' => ['/wp-content/fonts/roboto/KFOMCnqEu92Fr1ME7kSn66aGLdTylUAMQXC89YmC2DPNWubEbVmUiA8.woff2', PathCategory::CmsAsset],
    'wordpress uploaded pdf' => ['/wp-content/uploads/2014/12NRC1111-DHW-HYDRONIC-RECIRCULATION1.pdf', PathCategory::CmsAsset],
    'wordpress uploaded pdf in capitals' => ['/wp-content/uploads/2018/05/NCC199DV-Installation.PDF', PathCategory::CmsAsset],
    'pdf outside wordpress' => ['/assets/docs/nrc1111-spec-sheet.pdf', PathCategory::StaticAsset],
    'wordpress plugin readme' => ['/wp-content/plugins/revslider/readme.txt', PathCategory::ProbeCms],
    'shell in wordpress uploads' => ['/wp-content/uploads/x.php', PathCategory::ProbeShell],
    'shell in wordpress uploads with a second extension' => ['/wp-content/uploads/2024/01/logo.php.jpg.php', PathCategory::ProbeShell],
    'any file in wordpress uploads' => ['/wp-content/uploads/2023/03/price-list-2023.zip', PathCategory::CmsAsset],
    'wordpress script source map' => ['/wp-includes/js/jquery/jquery.min.js.map', PathCategory::ProbeCms],
]);

dataset('uncategorized paths', [
    'home page' => ['/'],
    'content page' => ['/important-documents'],
    'login page' => ['/login'],
    'api root' => ['/api'],
    'login page on a customer site' => ['/signin'],
    'feed' => ['/feed/'],
    'livewire v2 login submission' => ['/livewire/message/filament.core.auth.login'],
    'livewire v3 update' => ['/livewire/update'],
    'court documents' => ['/court-documents'],
    'download link' => ['/download/9c9dfc70-222d-43b4-9f03-f5ea9c1ab1a9'],
    'signing page' => ['/sign/a2ef5a16-87d0-4591-8668-95af02b580b0/a2ef5a16-9ead-411a-b291-c78c768e9c43'],
    'dashboard login' => ['/dashboard/login'],
    'blog feed' => ['/blog/feed/'],
    'admin dashboard' => ['/admin/dashboard'],
    'email verification' => ['/verify'],
    'invoices under billing' => ['/billing/invoices'],
    'settings page' => ['/settings'],
]);

test('paths are grouped into the category whose pattern they match', function (string $path, PathCategory $category) {
    expect(PathCategory::classify($path))->toBe($category);
})->with('categorized paths');

test('ordinary pages match no category', function (string $path) {
    expect(PathCategory::classify($path))->toBeNull();
})->with('uncategorized paths');

/**
 * Evaluate the categories' rule regexes in order, as the edge would, and return the first match.
 */
function firstRuleMatch(string $path): ?PathCategory
{
    foreach (PathCategory::cases() as $category) {
        if (preg_match('#'.$category->regex().'#', explode('?', $path)[0]) === 1) {
            return $category;
        }
    }

    return null;
}

test('the rule regexes put every categorized path in the same category as classify()', function (string $path, PathCategory $category) {
    expect(firstRuleMatch($path))->toBe($category);
})->with('categorized paths');

test('the rule regexes leave ordinary pages uncategorized', function (string $path) {
    expect(firstRuleMatch($path))->toBeNull();
})->with('uncategorized paths');

test('every category round-trips through its slug and rule name', function (PathCategory $category) {
    expect(PathCategory::fromSlug($category->slug()))->toBe($category)
        ->and($category->caddyMatcher())->toStartWith('@'.$category->ruleName().' path_regexp '.$category->ruleName().' (?i)^(?:');
})->with(PathCategory::cases());
