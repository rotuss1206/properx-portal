/*
 * Upload forms of the member area (form[data-ppx-upload]: the dashboard's panel and
 * resources/views/member/license-documents/upload.blade.php): drag & drop, the chosen file, and
 * progress while it uploads (XHR with the same form fields; the server answers JSON). Without
 * JavaScript the form posts as usual. DOM APIs only (CSP: no inline handlers, no eval). Checks here
 * are hints; the server checks size and type again, by content.
 *
 * The upload page also has a file row ([data-ppx-upload-row]) whose data-state is empty, selected,
 * uploading or error; public/portal/member.css shows the matching parts.
 */
(function () {
    var MAX_BYTES = 10 * 1024 * 1024
    var TYPES = ['application/pdf', 'image/jpeg', 'image/png']
    var FORMATS = { 'application/pdf': 'PDF', 'image/jpeg': 'JPG', 'image/png': 'PNG' }

    function init(form) {
        var input = form.querySelector('[data-ppx-upload-input]')
        var fileLine = form.querySelector('[data-ppx-upload-file]')
        var meta = form.querySelector('[data-ppx-upload-meta]')
        var row = form.querySelector('[data-ppx-upload-row]')
        var percent = form.querySelector('[data-ppx-upload-percent]')
        var clear = form.querySelector('[data-ppx-upload-clear]')
        var progress = form.querySelector('[data-ppx-upload-progress]')
        var error = form.querySelector('[data-ppx-upload-error]')
        var submit = form.querySelector('[type="submit"]')
        var fieldErrors = form.querySelectorAll('[data-ppx-field-error]')
        var typeSelect = form.querySelector('[data-ppx-upload-type]')
        var license = form.querySelector('[data-ppx-upload-license]')

        // A supporting file ("other") has no license details: hidden, and not sent.
        function toggleLicense() {
            var isLicense = typeSelect.getAttribute('data-license-types').split(',').indexOf(typeSelect.value) !== -1

            license.hidden = !isLicense
            license.querySelectorAll('input, select').forEach(function (field) { field.disabled = !isLicense })
        }

        if (typeSelect && license) {
            typeSelect.addEventListener('change', toggleLicense)
            toggleLicense()
        }

        function clearFieldErrors() {
            fieldErrors.forEach(function (element) {
                element.textContent = ''
                element.hidden = true
            })
        }

        // Errors of the other fields go under those fields; the file stays chosen. True if any did.
        function showFieldErrors(errors) {
            var first = null

            fieldErrors.forEach(function (element) {
                var name = element.getAttribute('data-ppx-field-error')

                if (errors[name]) {
                    element.textContent = errors[name][0]
                    element.hidden = false
                    first = first || form.querySelector('[name="' + name + '"]')
                }
            })

            if (first) {
                first.focus()
            }

            return first !== null
        }

        function state(name) {
            if (row) {
                row.setAttribute('data-state', name)
            }
        }

        function showError(message) {
            error.textContent = message
            error.hidden = !message

            if (message) {
                state('error')
            }
        }

        // The hint for the chosen file, or '' when it looks fine.
        function problem(file) {
            if (file.type && TYPES.indexOf(file.type) === -1) {
                return 'Upload a PDF, JPG or PNG file.'
            }

            return file.size > MAX_BYTES ? 'The file may not be larger than 10 MB.' : ''
        }

        function describe() {
            var file = input.files && input.files[0]
            var size = ''

            if (file) {
                size = file.size < 1048576 ? Math.max(1, Math.round(file.size / 1024)) + ' KB' : (file.size / 1048576).toFixed(1) + ' MB'
            }

            if (meta) {
                fileLine.textContent = file ? file.name : ''
                meta.textContent = file ? size + ' · ' + (FORMATS[file.type] || 'File') : ''
            } else {
                fileLine.textContent = file ? file.name + ' · ' + size : ''
            }

            showError('')
            state(file ? 'selected' : 'empty')

            if (file && problem(file)) {
                showError(problem(file))
            }
        }

        function fail(message) {
            showError(message)
            progress.hidden = true
            submit.disabled = false
            input.focus()
        }

        input.addEventListener('change', describe)

        if (clear) {
            clear.addEventListener('click', function () {
                input.value = ''
                describe()
                input.focus()
            })
        }

        // Drag & drop onto the whole form.
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

            // Not worth sending: the server would refuse it after the whole upload.
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
            clearFieldErrors()
            state('uploading')

            xhr.upload.addEventListener('progress', function (e) {
                if (e.lengthComputable) {
                    progress.value = Math.round((e.loaded / e.total) * 100)
                    progress.textContent = progress.value + '%'

                    if (percent) {
                        percent.textContent = progress.value < 100 ? 'Uploading · ' + progress.value + '%' : 'Saving securely…'
                    }
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

                if (body.errors && !body.errors.file && showFieldErrors(body.errors)) {
                    progress.hidden = true
                    submit.disabled = false
                    state('selected')

                    return
                }

                var messages = body.errors ? Object.keys(body.errors).map(function (key) { return body.errors[key][0] }) : []
                var known = {
                    413: 'The file may not be larger than 10 MB.',
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
