{{-- Waiting room: proof-of-work rounds back to back until the server says the time is served. --}}
<div class="text-center" id="gym-room">
    <div class="mx-auto mb-5 flex size-14 items-center justify-center rounded-full bg-coral-500/10">
        <svg class="size-7 text-coral-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <circle cx="12" cy="12" r="9" />
            <path d="M12 7v5l3 2" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
    </div>

    <h1 class="text-xl font-semibold tracking-tight">{{ $obstacle['headline'] }}</h1>
    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Your place is held. Keep this tab open and you’ll continue automatically.') }}</p>

    <div class="mt-6 h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
        <div class="h-full rounded-full bg-coral-500 transition-[width] duration-500 ease-linear" style="width: 0%" data-role="bar"></div>
    </div>

    <div class="mt-3 flex items-center justify-between text-xs text-zinc-500 dark:text-zinc-400">
        <span class="flex items-center gap-1.5"><span class="size-1.5 rounded-full bg-emerald-500 gym-breathe"></span><span data-role="state">{{ __('Holding your place') }}</span></span>
        @if ($obstacle['showTime'])
            <span class="font-mono tabular-nums" data-role="left"></span>
        @endif
    </div>

    <p class="mt-4 min-h-4 text-xs text-amber-700 dark:text-amber-300" data-role="notice" aria-live="polite"></p>

    <noscript>
        <p class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ __('This page needs JavaScript to hold your place. Please turn it on and reload.') }}</p>
    </noscript>
</div>

<script>
    (function () {
        var config = @js([...$runtime, 'challenge' => $obstacle['challenge'], 'difficulty' => $obstacle['difficulty'], 'totalMs' => $obstacle['totalMs'], 'elapsedMs' => $obstacle['elapsedMs']]);
        var copy = @js([
            'left' => __(':time left'),
            'almost' => __('Almost there'),
            'through' => __('You’re through. Taking you onward…'),
            'demoShort' => __('Preview shortened to 15 s.'),
            'demoDone' => __('Time served after :rounds rounds. A real visitor moves on now.'),
        ]);

        // A preview needn't take the full wait to show what it looks like.
        var totalMs = config.demo ? Math.min(config.totalMs, 15000) : config.totalMs;
        var root = document.getElementById('gym-room');
        var bar = root.querySelector('[data-role=bar]');
        var left = root.querySelector('[data-role=left]');
        var notice = root.querySelector('[data-role=notice]');
        var state = root.querySelector('[data-role=state]');
        var served = config.elapsedMs, syncedAt = performance.now(), rounds = 0, finished = false, lastProof = null;

        function render() {
            var now = Math.min(totalMs, served + (performance.now() - syncedAt));
            bar.style.width = (now / totalMs * 100) + '%';

            if (left) {
                var seconds = Math.max(0, Math.ceil((totalMs - now) / 1000));
                left.textContent = seconds <= 1 ? copy.almost : copy.left.replace(':time', seconds >= 60 ? Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0') : seconds + ' s');
            }

            if (! finished) { requestAnimationFrame(render); }
        }

        function sync(elapsedMs) {
            served = elapsedMs;
            syncedAt = performance.now();
        }

        function round(challenge, bits) {
            __q.solve(challenge, bits).then(function (result) {
                rounds++;

                if (config.demo) {
                    if (served + (performance.now() - syncedAt) >= totalMs) {
                        finished = true;
                        bar.style.width = '100%';
                        state.textContent = copy.demoDone.replace(':rounds', rounds);
                        return;
                    }

                    round(config.challenge.slice(0, 28) + String(rounds).padStart(4, '0'), bits);
                    return;
                }

                lastProof = { step: config.step, challenge: challenge, nonce: result.nonce };
                __q.post(config.attemptUrl, lastProof).then(handle);
            });
        }

        function handle(body) {
            if (body.redirect) {
                finished = true;
                bar.style.width = '100%';
                state.textContent = copy.through;
                window.location.replace(body.redirect);
                return;
            }

            notice.textContent = body.message || '';

            // Rate limited, offline or a request that hung: send the same proof again shortly.
            if (body.status === 'wait' && lastProof) {
                setTimeout(function () { __q.post(config.attemptUrl, lastProof).then(handle); }, body.retryMs || 2000);
                return;
            }

            if (typeof body.elapsedMs === 'number') { sync(body.elapsedMs); }

            if (body.challenge) {
                round(body.challenge, body.difficulty);
                return;
            }

            setTimeout(function () { window.location.reload(); }, 3000);
        }

        if (config.demo) { notice.textContent = copy.demoShort; }

        requestAnimationFrame(render);
        round(config.challenge, config.difficulty);
    })();
</script>
