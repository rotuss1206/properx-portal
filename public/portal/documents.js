/*
 * /member/license-documents. "Search documents" filters the rows already on the page, in the
 * browser: nothing is sent, so file names never reach a URL or a log. Without JavaScript the field
 * stays hidden. DOM APIs only (CSP).
 */
(function () {
    // An image preview that cannot be loaded (storage down, say) gives way to a plain message.
    // Again after a filter chip (chips.js) brings a new details panel.
    function watchPreview() {
        var preview = document.querySelector('[data-ppx-doc-preview]')
        var previewFailed = document.querySelector('[data-ppx-doc-preview-failed]')

        if (!preview || !previewFailed) {
            return
        }

        var image = preview.querySelector('img')

        function showPreviewFailed() {
            preview.hidden = true
            previewFailed.hidden = false
        }

        image.addEventListener('error', showPreviewFailed)

        if (image.complete && image.naturalWidth === 0 && image.getAttribute('loading') !== 'lazy') {
            showPreviewFailed()
        }
    }

    document.addEventListener('ppx:chips-updated', watchPreview)

    document.addEventListener('DOMContentLoaded', function () {
        watchPreview()

        var box = document.querySelector('[data-ppx-doc-search]')

        if (!box) {
            return
        }

        var input = box.querySelector('input')

        box.hidden = false

        // The rows are looked up each time: a filter chip (chips.js) brings new ones.
        function filter() {
            var query = input.value.trim().toLowerCase()
            var rows = document.querySelectorAll('[data-ppx-doc-row]')
            var none = document.querySelector('[data-ppx-doc-none]')
            var shown = 0

            rows.forEach(function (row) {
                var match = row.getAttribute('data-search').indexOf(query) !== -1

                row.hidden = !match
                shown += match ? 1 : 0
            })

            if (none) {
                none.hidden = shown > 0 || rows.length === 0
            }
        }

        input.addEventListener('input', filter)
        document.addEventListener('ppx:chips-updated', filter)
    })
})()
