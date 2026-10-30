/*
 * Upload form of the member area (form[data-ppx-upload], resources/views/member/dashboard.blade.php):
 * drag & drop onto the drop zone, the chosen file's name, and a progress bar while it uploads
 * (XHR with the same form fields; the server answers JSON). Without JavaScript the form posts as
 * usual. DOM APIs only (CSP: no inline handlers, no eval). Checks here are hints; the server
 * validates type and size again.
 */
(function () {
    var MAX_BYTES = 10 * 1024 * 1024
    var TYPES = ['application/pdf', 'image/jpeg', 'image/png']

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

        function describe() {
            var file = input.files && input.files[0]

            fileLine.textContent = file ? file.name + ' · ' + (file.size / 1048576).toFixed(1) + ' MB' : ''

            if (file && TYPES.indexOf(file.type) === -1) {
                showError('Upload a PDF, JPG or PNG file.')
            } else if (file && file.size > MAX_BYTES) {
                showError('The file may not be larger than 10 MB.')
            } else {
                showError('')
            }
        }

        input.addEventListener('change', describe)

        // Drag & drop onto the whole drop zone.
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

            if (!input.files || !input.files[0]) {
                showError('Choose a file to upload.')

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
                showError(messages[0] || body.message || 'The upload did not work. Please try again.')
                progress.hidden = true
                submit.disabled = false
            })

            xhr.addEventListener('error', function () {
                showError('The upload did not work. Please check your connection and try again.')
                progress.hidden = true
                submit.disabled = false
            })

            xhr.send(new FormData(form))
        })
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form[data-ppx-upload]').forEach(init)
    })
})()
