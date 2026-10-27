{{-- Browser check: silent. The page reports what the browser says about itself and moves on. --}}
<div class="text-center" id="gym-browser-check">
    <div class="mx-auto mb-5 flex size-14 items-center justify-center rounded-full bg-coral-500/10">
        <svg class="size-7 animate-spin text-coral-500" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.2" stroke-width="3" />
            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
        </svg>
    </div>

    <h1 class="text-xl font-semibold tracking-tight">{{ __('One moment…') }}</h1>
    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Making sure your browser is ready. This happens on its own.') }}</p>

    <dl class="mx-auto mt-5 hidden max-w-xs gap-x-4 gap-y-1 text-start font-mono text-[0.7rem] text-zinc-500 dark:text-zinc-400" data-role="signals"></dl>

    <noscript>
        <p class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ __('This check needs JavaScript. Please turn it on and reload the page.') }}</p>
    </noscript>
</div>

<script>
    (function () {
        var config = @js([...$runtime, 'nonce' => $obstacle['nonce']]);

        function signals(nonce) {
            return {
                step: config.step,
                nonce: nonce,
                ua: navigator.userAgent,
                webdriver: navigator.webdriver === true,
                languages: (navigator.languages || []).length,
                chrome_runtime: typeof window.chrome === 'object' && window.chrome !== null,
                screen_height: screen.height,
                inner_height: window.innerHeight,
                pixel_ratio: window.devicePixelRatio || 1,
                cores: navigator.hardwareConcurrency || 0,
                touch_points: navigator.maxTouchPoints || 0,
                timezone: (Intl.DateTimeFormat().resolvedOptions() || {}).timeZone || null,
            };
        }

        function send(nonce) {
            var report = signals(nonce);

            if (config.demo) {
                var list = document.querySelector('#gym-browser-check [data-role=signals]');
                list.classList.remove('hidden');
                list.classList.add('grid', 'grid-cols-2');
                ['webdriver', 'languages', 'chrome_runtime', 'screen_height', 'inner_height', 'pixel_ratio', 'cores', 'timezone'].forEach(function (key) {
                    var term = document.createElement('dt'), value = document.createElement('dd');
                    term.textContent = key;
                    value.textContent = String(report[key]);
                    list.append(term, value);
                });
                return;
            }

            __q.post(config.attemptUrl, report).then(function (body) {
                if (body.redirect) { window.location.replace(body.redirect); return; }
                if (body.nonce) { setTimeout(function () { send(body.nonce); }, 500); return; }
                setTimeout(function () { window.location.reload(); }, 3000);
            });
        }

        // A short beat, so the page is painted and real browsers have settled before reporting.
        setTimeout(function () { send(config.nonce); }, 400);
    })();
</script>
