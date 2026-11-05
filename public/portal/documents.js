/*
 * /member/license-documents. "Search documents" filters the rows already on the page, in the
 * browser: nothing is sent, so file names never reach a URL or a log. Without JavaScript the field
 * stays hidden. DOM APIs only (CSP).
 */
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        // An image preview that cannot be loaded (storage down, say) gives way to a plain message.
        var preview = document.querySelector('[data-ppx-doc-preview]')
        var previewFailed = document.querySelector('[data-ppx-doc-preview-failed]')

        function showPreviewFailed() {
            preview.hidden = true
            previewFailed.hidden = false
        }

        if (preview && previewFailed) {
            var image = preview.querySelector('img')

            image.addEventListener('error', showPreviewFailed)

            if (image.complete && image.naturalWidth === 0 && image.getAttribute('loading') !== 'lazy') {
                showPreviewFailed()
            }
        }

        var box = document.querySelector('[data-ppx-doc-search]')

        if (!box) {
            return
        }

        var input = box.querySelector('input')
        var rows = document.querySelectorAll('[data-ppx-doc-row]')
        var none = document.querySelector('[data-ppx-doc-none]')

        box.hidden = false

        input.addEventListener('input', function () {
            var query = input.value.trim().toLowerCase()
            var shown = 0

            rows.forEach(function (row) {
                var match = row.getAttribute('data-search').indexOf(query) !== -1

                row.hidden = !match
                shown += match ? 1 : 0
            })

            none.hidden = shown > 0
        })
    })
})()
