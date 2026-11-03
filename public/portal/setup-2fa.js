/*
 * Two-factor setup pages: "Copy" buttons (hidden without JavaScript) and keeping "Continue" off
 * until the recovery codes box is ticked. The server checks the box too. DOM APIs only (CSP).
 */
(function () {
    document.querySelectorAll('[data-ppx-copy]').forEach(function (button) {
        if (!navigator.clipboard) {
            return
        }

        button.hidden = false
        button.addEventListener('click', function () {
            var source = document.querySelector(button.getAttribute('data-ppx-copy'))
            var items = source.querySelectorAll('li')
            var text = items.length
                ? Array.prototype.map.call(items, function (item) { return item.textContent.trim() }).join('\n')
                : source.textContent.replace(/\s+/g, '')

            navigator.clipboard.writeText(text).then(function () {
                button.textContent = 'Copied'
                window.setTimeout(function () { button.textContent = 'Copy' }, 2000)
            })
        })
    })

    var form = document.querySelector('[data-ppx-saved-form]')

    if (form) {
        var box = form.querySelector('input[type="checkbox"]')
        var submit = form.querySelector('button[type="submit"]')
        var sync = function () { submit.disabled = !box.checked }

        box.addEventListener('change', sync)
        sync()
    }
})()
