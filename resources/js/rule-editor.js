/**
 * The rule-language editor behind the Rules script and the Activity search: a textarea with a
 * highlighted copy drawn underneath it, so the text stays editable while keywords, fields, strings and
 * numbers get their colors.
 *
 * Use it as x-data="ruleEditor(fieldNames, model)". Pass the Livewire property the textarea is bound
 * to as `model` when the server can rewrite it (the Activity filters do), so the colors follow.
 *
 * For a read-only copy (the Activity search shows the WHERE a question became), use
 * x-html="highlightRule(code, fieldNames)".
 */
const keywords = /^(BAN|TAG|AS|FOR|FOREVER|WHERE|WITHIN|HAVING|UNTIL|GROUP|BY|FILTER|AND|OR|NOT|LIKE|IN|IS|NULL|COUNT|DISTINCT|SECONDS?|MINUTES?|HOURS?|DAYS?|WEEKS?)$/i

window.highlightRule = (source = '', fieldNames = []) => {
    const fields = fieldNames instanceof Set ? fieldNames : new Set(fieldNames)
    const escape = (text) => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')

    return escape(source ?? '').replace(
        /(--[^\n]*)|('(?:[^'\\\n]|\\.|'')*'?)|(\b\d+\b)|([A-Za-z_][A-Za-z0-9_.]*)|(&lt;&gt;|!=|&lt;=|&gt;=|=|&lt;|&gt;|\*)/g,
        (match, comment, string, number, word, operator) => {
            if (comment) return `<span class="text-zinc-500 italic">${comment}</span>`
            if (string) return `<span class="text-amber-300">${string}</span>`
            if (number) return `<span class="text-violet-300">${number}</span>`
            if (word && keywords.test(word)) return `<span class="font-semibold text-coral-400">${word}</span>`
            if (word && fields.has(word.toLowerCase())) return `<span class="text-sky-300">${word}</span>`
            if (operator) return `<span class="text-zinc-400">${operator}</span>`

            return match
        },
    )
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('ruleEditor', (fieldNames = [], model = null) => ({
        html: '',
        ready: false,
        fields: new Set(fieldNames),

        init() {
            this.sync()
            this.ready = true

            if (model) {
                this.$wire.$watch(model, () => this.$nextTick(() => this.sync()))
            }
        },

        sync() {
            this.html = window.highlightRule(this.$refs.input?.value ?? '', this.fields) + '\n'
        },

        scroll() {
            this.$refs.overlay.scrollTop = this.$refs.input.scrollTop
        },

        insert(text) {
            const input = this.$refs.input
            const start = input.selectionStart ?? input.value.length
            const end = input.selectionEnd ?? start
            const before = input.value.slice(0, start)
            const spacer = before !== '' && ! /\s$/.test(before) ? ' ' : ''

            input.value = before + spacer + text + input.value.slice(end)
            input.focus()
            input.setSelectionRange(start + spacer.length + text.length, start + spacer.length + text.length)
            input.dispatchEvent(new Event('input', { bubbles: true }))
        },
    }))
})
