{{--
    The gym's browser runtime: the proof-of-work solver and a JSON poster. The solver is LN's PowSign
    core: a single-block SHA-256 over "<challenge>:<nonce>", nonces searched in order from zero, in a
    worker built from the function's own source, with a time-sliced fallback on the main thread.
    App\Gym\HashPuzzle is the PHP side; a test pins the two together.
--}}
<script>
    window.__q = window.__q || (function () {
        function powCore() {
            var K = new Uint32Array([
                0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
                0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
                0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
                0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
                0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
                0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
                0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
                0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
            ]);
            var W = new Uint32Array(64);
            var block = new Uint32Array(16);
            var out = new Uint32Array(2);

            // Pack an ASCII message of at most 55 bytes into one padded 512-bit block.
            function pack(message) {
                var length = message.length, i;
                for (i = 0; i < 16; i++) { block[i] = 0; }
                for (i = 0; i < length; i++) { block[i >> 2] |= (message.charCodeAt(i) & 0xff) << (24 - 8 * (i & 3)); }
                block[length >> 2] |= 0x80 << (24 - 8 * (length & 3));
                block[15] = length * 8;
            }

            // Compress the block and keep the first 64 bits of the digest: all the difficulty check needs.
            function compress() {
                var i, t1, t2, s0, s1, ch, maj, a, b, c, d, e, f, g, h, w15, w2;
                for (i = 0; i < 16; i++) { W[i] = block[i]; }
                for (i = 16; i < 64; i++) {
                    w15 = W[i - 15]; w2 = W[i - 2];
                    s0 = ((w15 >>> 7) | (w15 << 25)) ^ ((w15 >>> 18) | (w15 << 14)) ^ (w15 >>> 3);
                    s1 = ((w2 >>> 17) | (w2 << 15)) ^ ((w2 >>> 19) | (w2 << 13)) ^ (w2 >>> 10);
                    W[i] = (W[i - 16] + s0 + W[i - 7] + s1) | 0;
                }
                a = 0x6a09e667; b = 0xbb67ae85; c = 0x3c6ef372; d = 0xa54ff53a;
                e = 0x510e527f; f = 0x9b05688c; g = 0x1f83d9ab; h = 0x5be0cd19;
                for (i = 0; i < 64; i++) {
                    s1 = ((e >>> 6) | (e << 26)) ^ ((e >>> 11) | (e << 21)) ^ ((e >>> 25) | (e << 7));
                    ch = (e & f) ^ (~e & g);
                    t1 = (h + s1 + ch + K[i] + W[i]) | 0;
                    s0 = ((a >>> 2) | (a << 30)) ^ ((a >>> 13) | (a << 19)) ^ ((a >>> 22) | (a << 10));
                    maj = (a & b) ^ (a & c) ^ (b & c);
                    t2 = (s0 + maj) | 0;
                    h = g; g = f; f = e; e = (d + t1) | 0;
                    d = c; c = b; b = a; a = (t1 + t2) | 0;
                }
                out[0] = (0x6a09e667 + a) >>> 0;
                out[1] = (0xbb67ae85 + b) >>> 0;
            }

            function leadingZeroBits() {
                if (out[0] !== 0) { return Math.clz32(out[0]); }
                if (out[1] !== 0) { return 32 + Math.clz32(out[1]); }
                return 64;
            }

            function solveChunk(challenge, from, bits, budget) {
                var prefix = challenge + ':', nonce = from, end = from + budget;
                for (; nonce < end; nonce++) {
                    pack(prefix + nonce);
                    compress();
                    if (leadingZeroBits() >= bits) { return { found: true, nonce: nonce }; }
                }
                return { found: false, nonce: nonce };
            }

            return { solveChunk: solveChunk };
        }

        function startWorker() {
            try {
                var source = 'var core = (' + powCore.toString() + ')();\n'
                    + 'self.onmessage = function (event) {\n'
                    + '  var job = event.data, nonce = 0, result;\n'
                    + '  for (;;) {\n'
                    + '    result = core.solveChunk(job.challenge, nonce, job.bits, 50000);\n'
                    + '    if (result.found) { self.postMessage({ done: true, nonce: String(result.nonce), tried: result.nonce + 1 }); return; }\n'
                    + '    nonce = result.nonce;\n'
                    + '    self.postMessage({ done: false, tried: nonce });\n'
                    + '  }\n'
                    + '};';
                return new Worker(URL.createObjectURL(new Blob([source], { type: 'text/javascript' })));
            } catch (error) {
                return null;
            }
        }

        // Find a nonce. Resolves with { nonce, tried, ms }; onProgress gets the hashes tried so far.
        function solve(challenge, bits, onProgress) {
            return new Promise(function (resolve) {
                var started = performance.now();
                var worker = startWorker();
                var finish = function (nonce, tried) { resolve({ nonce: nonce, tried: tried, ms: performance.now() - started }); };

                if (worker) {
                    worker.onmessage = function (event) {
                        if (event.data.done) { worker.terminate(); finish(event.data.nonce, event.data.tried); return; }
                        if (onProgress) { onProgress(event.data.tried); }
                    };
                    worker.postMessage({ challenge: challenge, bits: bits });
                    return;
                }

                var core = powCore(), nonce = 0;
                (function step() {
                    var result = core.solveChunk(challenge, nonce, bits, 20000);
                    if (result.found) { finish(String(result.nonce), result.nonce + 1); return; }
                    nonce = result.nonce;
                    if (onProgress) { onProgress(nonce); }
                    setTimeout(step, 0);
                })();
            });
        }

        // Post JSON to the gym. Network trouble resolves as a retry the caller can back off from (retryMs,
        // when it's worth trying sooner). A request still unanswered after a few seconds is given up and
        // retried, rather than left hanging on a connection the browser can't open: sending the same
        // proof twice is harmless, as the gym moves on to a new challenge once it has one.
        function post(url, body) {
            var controller = typeof AbortController === 'function' ? new AbortController() : null;
            var timer = controller ? setTimeout(function () { controller.abort(); }, 6000) : null;

            return fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(body),
                signal: controller ? controller.signal : undefined,
            }).then(function (response) {
                if (response.status === 429) { return { status: 'wait', message: @js(__('Slowing down for a moment…')) }; }
                return response.json();
            }).then(function (answer) {
                clearTimeout(timer);
                return answer;
            }, function (error) {
                clearTimeout(timer);
                return error && error.name === 'AbortError'
                    ? { status: 'wait', retryMs: 250, message: @js(__('Taking longer than usual. Trying again…')) }
                    : { status: 'wait', message: @js(__('We’ve lost the connection. Trying again…')) };
            });
        }

        return { powCore: powCore, solve: solve, post: post };
    })();
</script>
