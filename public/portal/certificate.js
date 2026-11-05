/*
 * /member/certificate: scales the preview to its column (the page is laid out at its real size, as in
 * the PDF) and shows the options at once, without a round trip. The form posts nothing here; "Generate
 * & download" sends the same choices to the server. DOM APIs only (CSP).
 */
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var paper = document.querySelector('[data-ppx-cert-paper]')
        var page = document.querySelector('[data-ppx-cert-page]')

        if (!paper || !page) {
            return
        }

        function fit() {
            page.style.setProperty('--ppx-cert-scale', String(paper.clientWidth / page.offsetWidth))
        }

        fit()

        if (window.ResizeObserver) {
            new ResizeObserver(fit).observe(paper)
        } else {
            window.addEventListener('resize', fit)
        }

        function parts(name) {
            return page.querySelectorAll('[data-ppx-cert="' + name + '"]')
        }

        document.querySelectorAll('[data-ppx-cert-toggle]').forEach(function (toggle) {
            function apply() {
                var name = toggle.getAttribute('data-ppx-cert-toggle')

                parts(name).forEach(function (part) {
                    part.hidden = !toggle.checked
                })

                // The bottom row's text makes room for the QR, as in the PDF.
                if (name === 'qr') {
                    page.querySelector('.cert').classList.toggle('cert--qr', toggle.checked)
                }
            }

            toggle.addEventListener('change', apply)
            apply()
        })

        var term = document.querySelector('[data-ppx-cert-term]')

        if (term) {
            term.addEventListener('change', function () {
                var option = term.options[term.selectedIndex]

                parts('term').forEach(function (part) { part.textContent = option.getAttribute('data-term') })
                parts('valid-through').forEach(function (part) { part.textContent = option.getAttribute('data-date') })
            })
        }
    })
})()
