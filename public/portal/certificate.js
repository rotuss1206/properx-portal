/*
 * Scales the certificate preview on /member/certificate to its column. The page is laid out at its
 * real size, 612 pt (816 px) wide, as in the PDF. DOM APIs only (CSP).
 */
(function () {
    var PAGE_WIDTH = 816

    document.addEventListener('DOMContentLoaded', function () {
        var paper = document.querySelector('[data-ppx-cert-paper]')
        var page = document.querySelector('[data-ppx-cert-page]')

        if (!paper || !page) {
            return
        }

        function fit() {
            page.style.setProperty('--ppx-cert-scale', String(paper.clientWidth / PAGE_WIDTH))
        }

        fit()

        if (window.ResizeObserver) {
            new ResizeObserver(fit).observe(paper)
        } else {
            window.addEventListener('resize', fit)
        }
    })
})()
