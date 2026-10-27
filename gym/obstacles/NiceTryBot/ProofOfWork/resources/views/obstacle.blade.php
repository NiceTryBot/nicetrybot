{{-- Proof of work: the browser solves one SHA-256 puzzle, then moves on by itself. --}}
<div class="text-center" id="gym-pow">
    <div class="mx-auto mb-5 flex size-14 items-center justify-center rounded-full bg-coral-500/10">
        <svg class="size-7 animate-spin text-coral-500" viewBox="0 0 24 24" fill="none" aria-hidden="true" data-role="spinner">
            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.2" stroke-width="3" />
            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
        </svg>
    </div>

    <h1 class="text-xl font-semibold tracking-tight" data-role="title">{{ __('Checking your browser') }}</h1>
    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('This takes a moment and happens on its own. Please keep this tab open.') }}</p>

    @if ($obstacle['display'] === 'progress')
        <div class="mt-6 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
            <div class="h-full rounded-full bg-coral-500 transition-[width] duration-300" style="width: 2%" data-role="bar"></div>
        </div>
    @endif

    <p class="mt-3 min-h-4 text-xs text-zinc-500 dark:text-zinc-400" data-role="status" aria-live="polite"></p>

    <noscript>
        <p class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ __('This check needs JavaScript. Please turn it on and reload the page.') }}</p>
    </noscript>
</div>

<script>
    (function () {
        var config = @js([...$runtime, 'challenge' => $obstacle['challenge'], 'difficulty' => $obstacle['difficulty']]);
        var copy = @js([
            'working' => __('Working…'),
            'done' => __('Done. Taking you onward…'),
            'demoDone' => __('Solved in :seconds s after :hashes hashes. A real visitor moves on now.'),
        ]);
        var root = document.getElementById('gym-pow');
        var bar = root.querySelector('[data-role=bar]');
        var status = root.querySelector('[data-role=status]');

        var lastProof = null;

        function show(text) { status.textContent = text; }

        function fill(fraction) {
            if (bar) { bar.style.width = Math.max(2, Math.min(100, fraction * 100)) + '%'; }
        }

        function run(challenge, bits) {
            var expected = Math.pow(2, bits);
            show(copy.working);

            __q.solve(challenge, bits, function (tried) {
                // Chance the puzzle would be solved by now; a geometric search has no fixed end.
                fill(Math.min(0.96, 1 - Math.exp(-tried / expected)));
            }).then(function (result) {
                if (config.demo) {
                    fill(1);
                    root.querySelector('[data-role=spinner]').classList.remove('animate-spin');
                    show(copy.demoDone.replace(':seconds', (result.ms / 1000).toFixed(1)).replace(':hashes', result.tried.toLocaleString()));
                    return;
                }

                lastProof = { step: config.step, challenge: challenge, nonce: result.nonce };

                return __q.post(config.attemptUrl, lastProof).then(handle);
            });
        }

        function handle(body) {
            if (body.redirect) {
                fill(1);
                show(copy.done);
                window.location.replace(body.redirect);
                return;
            }

            if (body.message) { show(body.message); }

            // Rate limited, offline or a request that hung: send the same proof again shortly.
            if (body.status === 'wait' && lastProof) {
                setTimeout(function () { __q.post(config.attemptUrl, lastProof).then(handle); }, body.retryMs || 2000);
                return;
            }

            if (body.challenge) {
                setTimeout(function () { run(body.challenge, body.difficulty); }, 600);
                return;
            }

            setTimeout(function () { window.location.reload(); }, 3000);
        }

        run(config.challenge, config.difficulty);
    })();
</script>
