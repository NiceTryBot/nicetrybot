<?php

namespace App\Enums;

use Illuminate\Support\Str;

/**
 * A family of request paths that share an established pattern, e.g. every `.env*` file is a
 * credentials probe. Each category is defined only by its patterns, so it can be turned into an edge
 * rule as is: regex() and caddyMatcher() express exactly what classify() does.
 *
 * Categories are tried in case order and the first match wins. The edge's own diagnostics come first,
 * then scripts hidden among a WordPress site's uploads, then the scripts, styles, images and fonts a
 * WordPress site serves from wp-content and wp-includes and anything else in its uploads,
 * then every probe, so a shell hidden under /.well-known/ or a source map of server code is caught
 * before the browser-asset wildcards claim it, and specific probes come before the catch-all script
 * probe. Other assets come last.
 */
enum PathCategory: int
{
    case EdgeDiagnostic = 2;
    case ProbeShell = 22;
    case CmsAsset = 21;
    case ProbeCreds = 10;
    case ProbeSource = 11;
    case ProbeConfig = 12;
    case ProbeAdmin = 13;
    case ProbeCms = 14;
    case ProbeSaas = 17;
    case ProbePhish = 18;
    case ProbeAgent = 19;
    case ProbeExploit = 15;
    case ProbeApi = 16;
    case BrowserAsset = 1;
    case StaticAsset = 20;

    /**
     * Get the first category whose patterns match the path (query string ignored), or null.
     */
    public static function classify(string $path): ?self
    {
        $path = Str::before($path, '?');

        foreach (self::cases() as $category) {
            if (Str::is($category->patterns(), $path, ignoreCase: true)) {
                return $category;
            }
        }

        return null;
    }

    /**
     * Get the category for a slug such as "probe/creds".
     */
    public static function fromSlug(?string $slug): ?self
    {
        foreach (self::cases() as $category) {
            if ($category->slug() === $slug) {
                return $category;
            }
        }

        return null;
    }

    /**
     * Get the slug used in reports and URLs: the family, a slash, then the kind.
     */
    public function slug(): string
    {
        return match ($this) {
            self::BrowserAsset => 'asset/browser',
            self::EdgeDiagnostic => 'edge/diagnostic',
            self::ProbeCreds => 'probe/creds',
            self::ProbeSource => 'probe/source',
            self::ProbeConfig => 'probe/config',
            self::ProbeAdmin => 'probe/admin',
            self::ProbeCms => 'probe/cms',
            self::ProbeSaas => 'probe/saas',
            self::ProbePhish => 'probe/phish',
            self::ProbeAgent => 'probe/agent',
            self::ProbeExploit => 'probe/exploit',
            self::ProbeApi => 'probe/api',
            self::ProbeShell => 'probe/shell',
            self::StaticAsset => 'asset/static',
            self::CmsAsset => 'asset/cms',
        };
    }

    /**
     * Get the family the category belongs to: probe, asset or edge.
     */
    public function family(): string
    {
        return Str::before($this->slug(), '/');
    }

    /**
     * Determine if requests for these paths are someone looking for a way in.
     */
    public function isProbe(): bool
    {
        return $this->family() === 'probe';
    }

    /**
     * Get a short explanation of what the paths have in common.
     */
    public function description(): string
    {
        return match ($this) {
            self::BrowserAsset => __('Icons, manifests and robots.txt that browsers and crawlers ask for on their own.'),
            self::EdgeDiagnostic => __('NiceTryBot\'s own diagnostic pages on the edge.'),
            self::ProbeCreds => __('Secrets and credentials: .env files, cloud and SSH keys, password files.'),
            self::ProbeSource => __('Source code and project files: .git, editor folders, manifests, AI agent instructions, backups.'),
            self::ProbeConfig => __('App configuration and debug endpoints that leak settings.'),
            self::ProbeAdmin => __('Admin and login panels of popular software.'),
            self::ProbeCms => __('WordPress and other CMS internals.'),
            self::ProbeSaas => __('Dashboard pages of a typical SaaS app, such as billing, API keys and webhooks, asked for on sites that have none.'),
            self::ProbePhish => __('Pages of known phishing kits, checked to find sites that already host one.'),
            self::ProbeAgent => __('AI agent and MCP server discovery files, such as agent cards and MCP manifests.'),
            self::ProbeExploit => __('Known exploit paths and server-side scripts, including Exchange and appliance bugs.'),
            self::ProbeApi => __('API discovery: GraphQL endpoints, API docs and container registries.'),
            self::ProbeShell => __('Scripts among a WordPress site\'s uploads, where only media belongs: a web shell someone hopes was uploaded.'),
            self::StaticAsset => __('Scripts, styles, images and fonts a page loads.'),
            self::CmsAsset => __('Scripts, styles, images and fonts a WordPress page loads from its theme, plugins and core, and the files in its uploads.'),
        };
    }

    /**
     * The glob patterns (`*` matches anything, including slashes) that define the category. Patterns
     * starting with `*` + `/` match at any depth, so /dashboard/.env is caught as well as /.env.
     *
     * @return list<string>
     */
    public function patterns(): array
    {
        return match ($this) {
            self::BrowserAsset => [
                '/favicon.ico', '/favicon.png', '/favicon.svg', '/apple-touch-icon*.png', '/robots.txt', '/sitemap.xml',
                '/sitemap_index.xml', '/site.webmanifest', '/manifest.json', '/.well-known/*', '/browserconfig.xml', '/ads.txt', '*.map',
                '/humans.txt', '/llms.txt', '/security.txt',
            ],
            self::EdgeDiagnostic => ['/__nicetry/*'],
            self::ProbeCreds => [
                '*/.env*', '*/.aws/*', '*/.ssh/*', '*/id_rsa*', '*/id_ed25519*', '*/.git-credentials', '*/.npmrc', '*/.pypirc',
                '*/.netrc', '*/.htpasswd', '*/.docker/config.json', '*/wp-config.php*', '*/credentials.json', '*secrets*.json',
                '*secrets*.yml', '*secrets*.yaml', '*/.s3cfg', '*/.boto', '*.pem', '*.key', '*/.*_history', '*/_vti_pvt/*.pwd',
                // Env files under other names, and credential stores.
                '*.env', '*%2f.env*', '/env', '*/env.txt', '*/env.json', '*/credentials', '*/credentials.*', '*/credentials/*',
                '*credentials*.json', '*/.vault-token', '*/vault.json', '*/.pgpass', '*/.my.cnf', '*/.s3cmd.ini', '*/s3cmd.ini',
                '*/.passwd-s3fs', '*/.msmtprc', '*/.yarnrc', '*/.dbeaver/*', '*/kubeconfig', '*/.kube/*', '*terraform.tfstate*',
                '*/terraform.tfvars*', '*secret_token.rb', '*/secret.yaml', '*/secret.yml', '*/auth.json', '*/token.json',
                '*/key.json', '*/keyfile.json', '*private-key*.json', '*/api/v1/secrets', '*/api/v4/ci/variables',
                // Cloud and service-account keys.
                '*service-account*.json', '*service_account*.json', '*serviceAccount*.json', '*/sa.json', '*/gcp-*.json',
                '*/gc-service.json', '*/google-*.json', '*/firebase-*.json', '*/.config/gcloud/*', '*/application_default_credentials.json',
                // A wordlist of <service>.json/.yml files holding API keys.
                '/aws.json', '*/aws.yml', '*/aws/config*', '*/gcloud/configurations/*', '*/configurations/config_default', '/lambda.json', '/sns.json', '/ses.json', '/s3.json', '/s3.yml', '/openai.json',
                '/anthropic.json', '/gemini.json', '/stability.json', '/assemblyai.json', '/elevenlabs.json', '/langchain.json',
                '/pinecone.json', '/redshift.json', '/databricks.json', '/applovin.json', '/google-play.json', '/playfab.json',
                '/appstore.json', '/betterstack.json', '/brevo.json', '/uptimerobot.json', '/cassandra.json', '/nomad.json',
                '/influxdb.json', '/consul.json', '/sendgrid.*', '*/config/sendgrid.*', '/hubspot.*', '*/config/hubspot.*', '/r2.json',
                '/lithic.json', '/sardine.json', '/belvo.json', '/finicity.json', '/yodlee.json', '/amplifyconfiguration.json',
                '/appsmith.*', '/budibase.*', '/tooljet.*', '/illa*.json', '/lowcoder.*', '/meltano.*', '/retool.*', '/dagster.*',
                '/prefect.json', '/devpod.*', '/coder.json', '/pythonanywhere.json', '/sandbox.json', '/northflank.json', '/divio.*',
                '/engineyard.json', '/acquia.json', '/pantheon.json', '/pantheon.yml', '/porter.*', '/qovery.*', '/cyberpanel.json',
                '/metronome.json', '/orb.json', '/datasources.json', '*/config/datasources.json', '*/config/db.json',
                // Front-end env and runtime config bundles, which often inline API keys.
                '/env.js', '/env-config.js', '/aws-exports.js', '/runtime-config.js', '/app.config.js', '*/runtime-env.js', '/__ENV.js',
                '*/env.production.js', '*/aws-config.js', '*/aws.config.js', '*/firebase-config.js', '*/firebaseConfig.js',
                '*/config/env.js', '*secrets.js', '*/config/constants.js', '*/config/database.js', '*/config/database.config.js',
                '*/config.local.js', '*/config/environment.js', '*/config/environment.json',
                // AI provider keys: config files, key folders and client source.
                '*/config/*openai*', '*/config/*anthropic*', '/config/bedrock.*', '/config/vertex.*', '/config/gemini.*', '/openai*',
                '/azure-openai*', '/anthropic/*', '/openrouter*', '/minimax*', '*/.openai/*', '*/.anthropic/*', '*/.google/*',
                '*/.azure/*', '/claude-config.json', '/claude/*', '/lib/openai.*', '/lib/azure-openai.*', '/lib/opencode.*',
                '/lib/openrouter.*', '/lib/minimax.*', '/services/openai/*', '/services/anthropic/*', '/services/bedrock/*',
                '/services/gemini/*', '/services/opencode/*', '/services/openrouter/*', '/services/minimax/*', '/services/azure/*',
                '/src/openai.*', '/src/anthropic.*', '/utils/openai.*', '*.ipynb', '/bedrock.*', '/gemini.*', '/vertex*.json',
                '/google-ai.*', '*/.huggingface/*', '*/.cache/huggingface/*', '*/.opencode/*',
                // Keys, tokens and secret stores.
                '*/.dockercfg', '*/rclone.conf', '*/.config/rclone/*', '*/.dev.vars*', '*/id_dsa*', '*/id_ecdsa*', '*/private-key',
                '*/aws-credentials', '*/secrets.toml', '*/secret.json', '/secrets', '*.tfvars', '*/.terraform.rc', '*/git-credentials',
                '*/.config/gh/*', '/gh/hosts.yml', '*/.curlrc', '*/.wgetrc', '*jwt*secret*', '*/.gitsecret', '*/deploy_key',
                '*/.gitlab/token', '*/api_key*.txt', '*/api-key*.txt', '*/apikey*.txt', '*/key.txt', '*/keys.json', '*/api-keys.yaml',
                '*/wp-config', '*/wp-config.txt', '*/env.backup',
            ],
            self::ProbeSource => [
                '*/.git', '*/.git/*', '*/.gitignore', '*/.svn/*', '*/.hg/*', '*/.DS_Store', '*/.vscode/*', '*/.idea/*',
                '*/.cursor/*', '*/.github/*', '*/AGENTS.md', '*/CLAUDE.md', '*/README.md', '*/composer.json', '*/composer.lock',
                '*/package.json', '*/META-INF/*', '*/sftp-config.json', '*/docker-compose*', '*/Dockerfile', '*/.dockerenv',
                '*/.Dockerrun.aws.json', '*.sql', '*.bak', '*/backup*', '*.tar.gz', '*.tgz',
                // CI and deploy config.
                '*/.gitlab-ci.*', '*/.gitmodules', '*/.gitconfig', '*/.travis.yml*', '*/Jenkinsfile', '*/bitbucket-pipelines.yml*',
                '*/azure-pipelines.yml*', '*/.drone.yml*', '*/.circleci/*', '*/.vercel/*', '*/.netlify/*', '*/.amplifyrc',
                '*/serverless.yml', '*/serverless.yaml', '*/values.yaml', '*/helm-values.yaml', '*/.tugboat', '*/pantheon.upstream.yml',
                '*/vendor/composer/installed.json', '*/.eslintrc*', '*/ftpsync.settings', '*/wc.db', '*/venv/*', '*/node_modules/*',
                // AI agent settings, which often hold API keys.
                '*/.claude/*', '*/.claude.json', '*/claude.json', '*/.config/claude.json', '*/claude_desktop_config.json', '*/mcp.json',
                '*/mcp_config.json',
                // Shell profiles, editor swap files and leftovers.
                '*/.bashrc', '*/.zshrc', '*/.wp-cli/*', '*.swp', '*~', '*.save', '*.old', '*.orig', '*.dist',
                // Directory walks for backups and old copies.
                '/bak/', '/bk/', '/bac/', '/back/', '/bc/', '/old/', '/new/', '/oldsite/', '/old-site/', '/tmp/', '/tmps/',
                '/sql/', '/mysql/', '/db/', '/database/',
                // Deploy and hosting platform manifests.
                '*/vercel.json', '*/netlify.toml', '*/fly.toml', '*/.fly/*', '*/fly/config.toml', '*/railway.json', '*/railway.toml',
                '*/wrangler.toml', '*/samconfig.toml', '*/compose.yml', '*/compose.yaml', '*/.firebaserc', '*/Dockerrun.aws.json',
                '*/.terraform.lock.hcl', '/supabase/*', '*/deta.toml', '*/.gitpod.yml', '*/bitrise.yml', '*/.codefresh.yml',
                '*/codefresh.yml', '*/replit.nix', '*/gitconfig', '*/.bash_profile', '*/.profile', '*/.mongorc.js', '*/.mcp.json',
                '*/next.config.*', '*/nuxt.config.*', '*/gatsby-config.js', '*/amplify.yml', '*/amplify.yaml', '*/serverless.json',
                // Build manifests that browsers never ask for.
                '/asset-manifest.json', '*/webpack-stats.json', '/assets/manifest.json', '/static/manifest.json', '/dist/manifest.json',
                // Server source files.
                '*/settings.py', '*/app.py', '*/sms.py', '*/service/*.py', '*/.jest.config.js', '*/karma.conf.*', '/backend/config/*',
                '/server/config/*', '/server/s3.js', '/scripts/nodemailer.js', '/s3.js', '/api/common.js', '/api/constant*.js',
                '/api/env.js', '/api/server.js', '/api/config/*.js', '/api/node/*.js', '/apis/*',
                // Database files, dumps and site archives.
                '*.rej', '*.backup', '*.sql.gz', '*.sqlite', '*.sqlite3', '*.db', '*.mdb', '*.accdb', '/site.zip', '/www.zip',
                '/html.zip', '/wwwroot.zip', '/public_html.zip', '/htdocs.zip', '/db.zip', '/dump.zip', '/app.zip', '/site-backup.zip',
                '/web.zip', '/archive.zip', '/code.zip', '/src.zip', '/source.zip', '/old.zip',
                // Walking a source map back to the framework's own source: Livewire's, and Astro's server pages.
                '/js/features/support*.js', '/js/directives/wire-*.js', '/js/plugins/history/*', '/js/plugins/navigate/*',
                '*.astro.mjs.map',
            ],
            self::ProbeConfig => [
                '*/config.js', '*/config.json', '*/config.php*', '*/config.yml', '*/config.yaml', '*/config.xml', '*/appsettings*.json',
                '/settings.json', '/settings.yml', '/settings.py', '*/local_settings.py', '*/config.py', '*/parameters.yml*',
                '*/database.yml', '*/database.json', '*/databases.yml', '*/application.yml', '*/application-*.yml',
                '*/application.properties', '*/firebase.json', '*/__/firebase/init.json', '*/phinx.yml', '*/phinx.yaml', '*/web.config',
                '*/serverconfig.xml', '*/WEB-INF/*', '*/web-inf/*', '*/_environment', '*/airflow.cfg', '*/awstats*',
                '*/deployment-config.json', '*/ws-config*.json', '*/push_config.json', '/config/', '*/_debugbar/*', '*/elmah.axd',
                '*/rails/info/*', '/metrics', '*/config.properties', '*/api/index.php/v1/config/*',
                '*/storage/logs/*', '*.log',
                '*/api/config', '*/server-status*', '*/server-info', '*/phpinfo*', '*/info.php', '*/@vite/env', '*/_profiler*',
                '*/actuator*', '*/console', '*/console/*', '*/debug/*', '*/trace.axd',
                // Spring, .NET and self-hosted app config.
                '*/application.yaml', '*/application-*.properties', '*/bootstrap.yml', '*/bootstrap.properties', '*/Web.*.config',
                '*/connectionstrings.config', '*/appsettings.json.txt', '*/odoo.conf', '*/homeserver.yaml', '*/lemmy.hjson',
                '*/misskey.yml', '*/discourse.conf', '/config/*.json', '/config/*.yml', '/config/*.yaml', '/config/settings.*',
                // Environment and settings endpoints: actuator look-alikes, APIs, Elasticsearch, CouchDB, Kubernetes.
                '*/manage/env', '*/management/env', '*/monitoring/env', '/api/env', '/api/v*/env', '/api/v*/config', '/api/v*/config/',
                '/api/admin/*', '/api/keys', '/api/v*/keys', '*/nginx_status', '/_cluster/*', '/_nodes', '/_config', '/_users',
                '/api/v1/namespaces/*', '/sugar_version.json', '/assets/environments/*', '/rest/api/1.0/application-properties',
                '/api/session/properties',
            ],
            self::ProbeAdmin => [
                '*/wp-admin*', '*/wp-login.php*', '*/administrator*', '*/phpmyadmin*', '*/pma/*', '*/adminer*',
                '*/login.action', '*/manager/html*', '*/telescope*', '*/horizon*', '/___proxy_subdomain_*',
                '*/cpanel*', '*/plesk*', '*/adminhtml*', '*/myadmin*', '*/mysqladmin*', '*/amember/*', '*/backoffice*',
                '/admin/', '/admin/upload*', '/admin/editor/', '/admin/tmp/', '/admin/images/*', '/admin/controller/*',
                '*/pma', '/dbadmin', '/zabbix/*', '/enhancecp', '/admin/config', '/apps/admin/*',
                // Jenkins' script console, and the APIs of n8n, RabbitMQ and Grafana.
                '/script', '/rest/settings', '/rest/login', '/rest/logout', '/rest/workflows*', '/rest/credential*', '/rest/oauth*',
                '/rest/variables', '/rest/node-types', '/rest/license', '/rest/users', '/rest/tags', '/rest/executions',
                '/api/v1/workflows', '/api/v1/executions', '/api/queues', '/api/exchanges', '/api/overview', '/api/datasources',
            ],
            self::ProbeCms => [
                '*/wp-content/*', '*/wp-includes/*', '*/wp-json*', '*/xmlrpc.php*', '*/wordpress/*', '*/wp-old/*',
                // Walking the usual install folders for a CMS.
                '/wp/', '/cms/', '/site/', '/sito/', '/sitio/', '/web/', '/www/', '/blog/', '/press/', '/news/', '/forum/', '/forums/',
                '/shop/', '/test/', '/demo/', '/dev/', '/main/', '/portal/', '/website/*', '/2015/', '/2016/', '/2017/', '/2018/',
                '/2019/', '/2020/', '/2021/', '/2022/', '/2023/', '/2024/', '/2025/',
                // CMS internals (plain feeds stay ordinary pages).
                '/category/feed/', '/rss/order/*', '/rss/catalog/*', '/block-patterns/', '/block-supports/', '/blocks/',
                '*/magento*', '/downloader/', '/templates/beez3/', '/sites/default/files/', '/xwiki/*', '*/bin/view/Main/*',
                '*/bin/get/Main/*', '/redmine/*', '/pimcore/*', '/_layouts/*', '/modules/', '/plugins/', '/includes/', '/include/', '/components/',
                // Files only fingerprinters fetch: Joomla's JCE editor manifest, WordPress's readme, and scam-site templates.
                '/plugins/editors/jce/jce.xml', '/readme.html', '/theme/default/assets/umi.js', '/theme/default/assets/compoments.js',
                '*/umi.min.js', '/js/NewWindow_2_all.js',
            ],
            self::ProbeSaas => [
                '/billing', '/workspace', '/api-keys', '/settings/api-keys', '/members', '/studio', '/tokens', '/onboarding',
                '/webhooks', '/integrations', '/internal', '/analytics', '/playground', '/dashboard/settings', '/dashboard/billing',
                '/admin/users', '/public/admin.json',
            ],
            self::ProbePhish => [
                '/giris', '/giris/*', '/confirm', '/validate', '/customer', '/online', '/webapps/*',
            ],
            self::ProbeAgent => [
                '/.well-known/agent-card.json', '/.well-known/agent.json', '/.well-known/agents.json', '/.well-known/agents-index.json',
                '/.well-known/mcp', '/.well-known/mcp/*', '/.well-known/mcp.json', '/.well-known/webmcp*', '/.well-known/ai-plugin.json',
                '/.well-known/ucp',
            ],
            self::ProbeExploit => [
                '*/_ignition/*', '*/vendor/phpunit/*', '*/cgi-bin/*', '*/boaform/*', '*/HNAP1*', '*/shell*', '*/eval-stdin*',
                '*/solr/*', '*/geoserver/*', '*/ecp/*', '*/owa/*', '*/autodiscover/*', '*.php', '*.asp', '*.aspx', '*.jsp', '*.cgi',
                '*.php5', '*.php7*', '*.phtml', '*.ph', '*/php-cgi/*',
                // Path traversal and reading server files.
                '*%2e%2e*', '*..%2f*', '*%252f*', '*%25252f*', '*/../*', '*..;/*', '*/etc/passwd*', '*/etc/environment', '*/etc/profile.d/*',
                '*/etc/hosts', '*proc/self/*', '*win.ini*', '*/var/run/secrets/*', '*/@fs/*', '*/latest/meta-data/*',
                // Web shells and file managers.
                '*/ALFA_DATA/*', '*alfacgiapi*', '*cgialfa*', '*/.tmb/*', '/tmb/', '*/.quarantine/*', '*/server/php/*',
                '*/jquery-file-upload/*', '*/jars/upload*', '*/userfiles*', '*/uploadify/*', '/upload/', '/uploads/', '/files/',
                '/file/', '/upload/image/',
                // Known product exploits: WebLogic, ColdFusion, OFBiz, vCenter, Nexus, Fortinet, Citrix, Pulse and others.
                '*/wls-wsat/*', '*/CFIDE/*', '*/webtools/control/*', '*/ui/vropspluginui/*', '*/service/extdirect*',
                '*/remote/fgt_lang', '*/vpn/*', '*/dana-na/*', '*/logon/LogonPoint/*', '*/fortisandbox/*', '*/resin-doc/*',
                '*/brightmail/*', '*/weaver/*', '*/WebReport/*', '*/ReportServer*', '*/NCFindWeb', '*/casmain.xgi', '*/device.rsp',
                '*/cmd,/*', '*/functionRouter', '*/helpdesk/WebObjects/*', '*/go/add-on/*', '*/app/rest/server',
                '*/internal/v2/config/*', '*/__vite_rsc_findSourceMapURL', '*/actpt_5g.data', '*/xstoremgwt/*', '*/JHSoft*',
                '*/BetterImageGallery/*', '*/spog/*', '*/smartportal/*', '*/WebInterface/*', '*/Gaia/*',
                // React Server Components probes.
                '/_flight', '/rsc', '/_next/rsc', '/api/rsc',
                // Database and container endpoints left open.
                '*/api/fs/read', '*/api/system/fileView', '*/api/rds/execute', '*/rds-data/*', '*/postgres/query', '*/api/db/query',
                '*/api/data/query', '*/api/system/system/string_render', '*/api/action/datastore_search_sql', '*/containers/json',
                '*/_cat/indices',
                // Scanners announcing themselves.
                '*/scnr-probe-*', '*-debug-trigger-*',
                // Encoding tricks: overlong UTF-8, backslashes and encoded slashes in front of dotfiles.
                '/%c0*', '/%5c', '/%2f*', '*%2f.*', '*%2eenv*', '*.aws%2f*', '*.php/*',
                // Serverless and container paths, Langflow's and Laravel File Manager's bugs, Telerik and WinRM.
                '/var/task/*', '/app/*.toml', '/app/vercel.json', '/getcmd', '/file-manager/*', '/api/v1/flows/public',
                '/api/v1/build_public_tmp/*', '/wsman', '*/Telerik.Web.UI.WebResource.axd',
            ],
            self::ProbeApi => [
                '*/graphql*', '*/gql', '*/api/gql*', '*/v2/_catalog', '*/swagger*', '*/openapi.json', '*/api-docs*', '*/api/docs*',
                '*/docs/api*', '*/api/json', '*/api/v1/version', '*/api/system/version', '/openapi.yaml', '/openapi.yml',
                // Open OpenAI-compatible proxies.
                '/chat/completions', '*/v1/chat/completions', '/api/v1/models',
            ],
            self::ProbeShell => ['*/wp-content/uploads/*.php*', '*/wp-content/uploads/*.phtml', '*/wp-content/uploads/*.phar'],
            self::CmsAsset => ['*/wp-content/uploads/*', ...array_merge(...array_map(
                fn (string $folder): array => array_map(
                    fn (string $extension): string => "*/{$folder}/*.{$extension}",
                    [
                        'css', 'js', 'mjs', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot',
                        // Documents and media people upload: spec sheets, manuals, videos.
                        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'mp4', 'webm', 'mp3',
                    ],
                ),
                ['wp-content', 'wp-includes'],
            ))],
            self::StaticAsset => [
                '/build/*', '/css/*', '/js/*', '/images/*', '/img/*', '/fonts/*',
                '*.css', '*.js', '*.png', '*.jpg', '*.jpeg', '*.gif', '*.svg', '*.webp', '*.woff', '*.woff2', '*.pdf',
            ],
        };
    }

    /**
     * The patterns as one case-insensitive, anchored regular expression, for an edge rule.
     */
    public function regex(): string
    {
        $alternatives = array_map(
            fn (string $pattern) => str_replace('\*', '.*', preg_quote($pattern, '#')),
            $this->patterns(),
        );

        return '(?i)^(?:'.implode('|', $alternatives).')$';
    }

    /**
     * Get the identifier a rule for this category would use, e.g. "probe_creds".
     */
    public function ruleName(): string
    {
        return str_replace('/', '_', $this->slug());
    }

    /**
     * Get the Caddyfile named matcher that recognises the category at the edge.
     */
    public function caddyMatcher(): string
    {
        return '@'.$this->ruleName().' path_regexp '.$this->ruleName().' '.$this->regex();
    }

    /**
     * Get the 404s page bucket the category falls into.
     */
    public function notFoundCategory(): NotFoundCategory
    {
        return match ($this->family()) {
            'probe' => NotFoundCategory::Probe,
            'asset' => NotFoundCategory::BrowserAsset,
            default => NotFoundCategory::MissingPage,
        };
    }
}
