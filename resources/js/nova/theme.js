/*
 * The portal's look for Nova (resources/css/nova/theme.css, the member area's cards, tables, buttons,
 * fields and badges). The stylesheet applies only under html.ppx-nova-theme, which this script sets: if
 * it does not run, Nova keeps its own look. It also hides a page's own heading when it repeats the
 * title in the portal header (resources/js/nova/header.js), so a page is not titled twice; panel and
 * relation headings ("Billing", "People") stay. DOM APIs only; nothing of Nova is replaced.
 */
(function () {
    var root = document.documentElement
    root.classList.add('ppx-nova-theme')

    function normalize(text) {
        return String(text || '').replace(/\s+/g, ' ').trim().toLowerCase()
    }

    function markDuplicates() {
        var header = document.querySelector('.ppx-header__title')
        var title = normalize(header && header.textContent)

        document.querySelectorAll('h1[dusk$="-heading"]').forEach(function (heading) {
            heading.classList.toggle('ppx-nova-duplicate-heading', title !== '' && normalize(heading.textContent) === title)
        })
    }

    var queued = false

    function schedule() {
        if (queued) {
            return
        }

        queued = true
        window.requestAnimationFrame(function () {
            queued = false
            markDuplicates()
        })
    }

    function start() {
        markDuplicates()
        new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true, characterData: true })
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start)
    } else {
        start()
    }
})()
