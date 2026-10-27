/**
 * Picking a stretch of a traffic chart: click a bar to pick its time, or drag across bars to pick
 * theirs. Clicking inside the pick, or dragging the same bars again, lets it go. The pick lives in a
 * Livewire property as "start-end" Unix seconds (the end left out), which the page narrows its list
 * by; the bars outside it mute at once, before the server answers.
 *
 * Use it as x-data="chartRange(model)" on the element holding the bars, each bar marked with
 * data-bar-start and data-bar-end. The pick is kept as times, not bar positions, so it still lines
 * up after the chart redraws with a new bar at the end.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('chartRange', (model) => ({
        anchor: null,
        reach: null,

        // The stretch the property holds, [start, end], or null.
        committed() {
            const [start, end] = String(this.$wire[model] ?? '').split('-').map(Number)

            return start && end ? [start, end] : null
        },

        // The stretch on show: the drag in progress, else the property.
        picked() {
            if (this.anchor === null) {
                return this.committed()
            }

            return [Math.min(this.anchor[0], this.reach[0]), Math.max(this.anchor[1], this.reach[1])]
        },

        isMuted(start, end) {
            const picked = this.picked()

            return picked !== null && (end <= picked[0] || start >= picked[1])
        },

        isPicked(start, end) {
            return this.picked() !== null && ! this.isMuted(start, end)
        },

        // The bar under the pointer, as [start, end], found by how far across the chart it is.
        barAt(event) {
            const bars = this.$root.querySelectorAll('[data-bar-start]')
            const box = this.$root.getBoundingClientRect()
            const index = Math.min(bars.length - 1, Math.max(0, Math.floor((event.clientX - box.left) / box.width * bars.length)))

            return [Number(bars[index].dataset.barStart), Number(bars[index].dataset.barEnd)]
        },

        start(event) {
            if (event.button !== 0 || ! this.$root.querySelector('[data-bar-start]')) {
                return
            }

            this.anchor = this.reach = this.barAt(event)
            this.$root.setPointerCapture?.(event.pointerId)
        },

        move(event) {
            if (this.anchor !== null) {
                this.reach = this.barAt(event)
            }
        },

        finish() {
            if (this.anchor === null) {
                return
            }

            const isClick = this.anchor[0] === this.reach[0]
            const [start, end] = this.picked()
            this.anchor = this.reach = null

            this.pick(start, end, isClick)
        },

        cancel() {
            this.anchor = this.reach = null
        },

        pick(start, end, isClick = true) {
            const current = this.committed()
            const letsGo = current !== null && (isClick
                ? start >= current[0] && end <= current[1]
                : start === current[0] && end === current[1])

            this.$wire.$set(model, letsGo ? '' : `${start}-${end}`)
        },
    }))
})
