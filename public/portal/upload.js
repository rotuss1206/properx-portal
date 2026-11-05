/*
 * The dashboard's short upload form (form[data-ppx-upload], resources/views/member/dashboard.blade.php):
 * drag & drop, the chosen file's name, and a progress bar while it uploads (XHR with the same form
 * fields; the server answers JSON). Without JavaScript the form posts as usual. DOM APIs only (CSP).
 * Checks here are hints; the server checks size and type again, by content.
 */
(function () {
    var MAX_BYTES = 10 * 1024 * 1024
    var TYPES = ['application/pdf', 'image/jpeg', 'image/png']
    var WRONG_FORMAT = 'Unsupported format — upload a PDF, JPG or PNG.'
    var TOO_LARGE = 'Too large — the file may not be larger than 10 MB.'

    function init(form) {
        var input = form.querySelector('[data-ppx-upload-input]')
        var fileLine = form.querySelector('[data-ppx-upload-file]')
        var progress = form.querySelector('[data-ppx-upload-progress]')
        var error = form.querySelector('[data-ppx-upload-error]')
        var submit = form.querySelector('[type="submit"]')

        function showError(message) {
            error.textContent = message
            error.hidden = !message
        }

        function problem(file) {
            if (file.type && TYPES.indexOf(file.type) === -1) {
                return WRONG_FORMAT
            }

            return file.size > MAX_BYTES ? TOO_LARGE : ''
        }

        function describe() {
            var file = input.files && input.files[0]

            fileLine.textContent = file ? file.name + ' · ' + (file.size / 1048576).toFixed(1) + ' MB' : ''
            showError(file ? problem(file) : '')
        }

        function fail(message) {
            showError(message)
            progress.hidden = true
            submit.disabled = false
            input.focus()
        }

        input.addEventListener('change', describe)

        ;['dragenter', 'dragover'].forEach(function (name) {
            form.addEventListener(name, function (event) {
                event.preventDefault()
                form.classList.add('is-dragging')
            })
        })

        ;['dragleave', 'drop'].forEach(function (name) {
            form.addEventListener(name, function () { form.classList.remove('is-dragging') })
        })

        form.addEventListener('drop', function (event) {
            event.preventDefault()

            if (event.dataTransfer && event.dataTransfer.files.length) {
                input.files = event.dataTransfer.files
                describe()
            }
        })

        if (!window.FormData || !window.XMLHttpRequest) {
            return
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault()

            var file = input.files && input.files[0]

            if (!file) {
                fail('Choose a file to upload.')

                return
            }

            if (problem(file)) {
                fail(problem(file))

                return
            }

            var xhr = new XMLHttpRequest()
            xhr.open('POST', form.action)
            xhr.setRequestHeader('Accept', 'application/json')
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest')

            progress.hidden = false
            progress.value = 0
            submit.disabled = true
            showError('')

            xhr.upload.addEventListener('progress', function (e) {
                if (e.lengthComputable) {
                    progress.value = Math.round((e.loaded / e.total) * 100)
                    progress.textContent = progress.value + '%'
                }
            })

            xhr.addEventListener('load', function () {
                var body = {}

                try {
                    body = JSON.parse(xhr.responseText || '{}')
                } catch (e) {
                    body = {}
                }

                if (xhr.status >= 200 && xhr.status < 300 && body.redirect) {
                    window.location.href = body.redirect

                    return
                }

                var messages = body.errors ? Object.keys(body.errors).map(function (key) { return body.errors[key][0] }) : []
                var known = {
                    413: TOO_LARGE,
                    419: 'Your session has expired. Reload the page and try again.',
                    429: 'Too many uploads in a short time. Wait a minute and try again.',
                }

                fail(messages[0] || known[xhr.status] || (xhr.status === 503 && body.message) || 'The upload did not work. Please try again.')
            })

            xhr.addEventListener('error', function () {
                fail('The upload did not work. Please check your connection and try again.')
            })

            xhr.send(new FormData(form))
        })
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form[data-ppx-upload]').forEach(init)
    })
})()
