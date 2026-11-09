/*
 * The upload page (form[data-ppx-upload-page], resources/views/member/license-documents/upload.blade.php):
 * choose or drop several files; each gets a row, "Ready" or "Rejected" at once, from its size and its
 * first bytes, nothing sent. "Save document" sends the ready ones one at a time with a progress bar;
 * each becomes its own document, and the server checks it again by content, which can still reject it.
 * Nothing is kept on the server before Save. Without JavaScript the form posts all files at once.
 * Also shows the fields each document type needs. DOM APIs only (CSP: no inline handlers, no eval).
 */
(function () {
    var MAX_BYTES = 10 * 1024 * 1024
    var WRONG_FORMAT = 'Unsupported format — upload a PDF, JPG or PNG.'
    var TOO_LARGE = 'Too large — the file may not be larger than 10 MB.'
    var UNSENT = 'The upload did not work. Please check your connection and try again.'
    var FORMATS = { pdf: 'PDF', jpg: 'JPG', png: 'PNG' }

    // What the first bytes say, whatever the file is called.
    function sniff(file) {
        return file.slice(0, 8).arrayBuffer().then(function (buffer) {
            var b = new Uint8Array(buffer)

            if (b[0] === 0x25 && b[1] === 0x50 && b[2] === 0x44 && b[3] === 0x46) {
                return 'pdf'
            }

            if (b[0] === 0xff && b[1] === 0xd8 && b[2] === 0xff) {
                return 'jpg'
            }

            return b[0] === 0x89 && b[1] === 0x50 && b[2] === 0x4e && b[3] === 0x47 ? 'png' : null
        })
    }

    function sizeLabel(bytes) {
        return bytes < 1048576 ? Math.max(1, Math.round(bytes / 1024)) + ' KB' : (bytes / 1048576).toFixed(1) + ' MB'
    }

    function init(form) {
        var input = form.querySelector('[data-ppx-upload-input]')
        var zone = form.querySelector('[data-ppx-upload-zone]')
        var list = form.querySelector('[data-ppx-upload-list]')
        var none = form.querySelector('[data-ppx-upload-none]')
        var message = form.querySelector('[data-ppx-upload-message]')
        var submit = form.querySelector('[type="submit"]')
        var cancel = form.querySelector('[data-ppx-upload-cancel]')
        var serverErrors = form.querySelector('[data-ppx-upload-server-errors]')
        var icons = form.getAttribute('data-icons')
        var rows = []
        var sending = false

        function svg(name, className) {
            var ns = 'http://www.w3.org/2000/svg'
            var element = document.createElementNS(ns, 'svg')
            var use = document.createElementNS(ns, 'use')

            element.setAttribute('class', className)
            element.setAttribute('viewBox', '0 0 24 24')
            element.setAttribute('fill', 'none')
            element.setAttribute('stroke', 'currentColor')
            element.setAttribute('stroke-width', '2')
            element.setAttribute('stroke-linecap', 'round')
            element.setAttribute('stroke-linejoin', 'round')
            element.setAttribute('aria-hidden', 'true')
            use.setAttribute('href', icons + '#' + name)
            element.appendChild(use)

            return element
        }

        function el(tag, className, text) {
            var element = document.createElement(tag)

            element.className = className

            if (text !== undefined) {
                element.textContent = text
            }

            return element
        }

        function badge(variant, text, icon) {
            var span = el('span', 'ppx-badge ppx-badge--' + variant + ' ppx-upload-file__badge')

            if (icon) {
                span.appendChild(svg(icon, 'ppx-upload-file__badge-icon'))
            }

            span.appendChild(document.createTextNode(text))

            return span
        }

        function percentText(row) {
            return row.percent < 100 ? 'Uploading · ' + row.percent + '%' : 'Saving securely…'
        }

        function draw() {
            list.replaceChildren()
            none.hidden = rows.length > 0

            rows.forEach(function (row) {
                var li = el('li', 'ppx-upload-file is-' + row.state)
                var isImage = row.format === 'jpg' || row.format === 'png'
                var icon = el('span', 'ppx-upload-file__icon')
                var text = el('div', 'ppx-upload-file__text')
                var line = el('div', 'ppx-upload-file__line')

                icon.appendChild(svg(row.state === 'rejected' ? 'file-x' : (isImage ? 'file-image' : 'license'), 'ppx-upload-file__svg'))
                line.appendChild(el('span', 'ppx-upload-file__name', row.file.name))

                if (row.state === 'validated') {
                    line.appendChild(badge('success', 'Ready', 'shield-check'))
                } else if (row.state === 'saved') {
                    line.appendChild(badge('success', 'Saved', 'shield-check'))
                } else if (row.state === 'rejected') {
                    line.appendChild(badge('danger', 'Rejected'))
                } else if (row.state === 'uploading') {
                    row.percentLabel = el('span', 'ppx-upload-file__percent', percentText(row))
                    row.percentLabel.setAttribute('aria-hidden', 'true')
                    line.appendChild(row.percentLabel)
                }

                text.appendChild(line)

                if (row.state === 'rejected') {
                    text.appendChild(el('p', 'ppx-upload-file__error', row.reason))
                } else if (row.state === 'uploading') {
                    row.progress = el('progress', 'ppx-upload-file__progress')
                    row.progress.max = 100
                    row.progress.value = row.percent
                    row.progress.textContent = row.percent + '%'
                    row.progress.setAttribute('aria-label', 'Uploading ' + row.file.name)
                    text.appendChild(row.progress)
                } else {
                    text.appendChild(el('p', 'ppx-upload-file__meta', sizeLabel(row.file.size) + (row.format ? ' · ' + FORMATS[row.format] : '')))
                }

                li.appendChild(icon)
                li.appendChild(text)

                // Once all of it has arrived the server is saving it, and a click could no longer stop that.
                if (row.state !== 'saved' && !(row.state === 'uploading' && row.percent >= 100)) {
                    var remove = el('button', 'ppx-btn ppx-btn--icon ppx-upload-file__clear')

                    remove.type = 'button'
                    remove.setAttribute('aria-label', 'Remove ' + row.file.name)
                    remove.title = 'Remove from the list'
                    remove.appendChild(svg('close', 'ppx-btn__icon'))
                    remove.addEventListener('click', function () {
                        rows.splice(rows.indexOf(row), 1)

                        if (row.state === 'uploading') {
                            row.xhr.abort()
                        }

                        draw()
                        input.focus()
                    })
                    li.appendChild(remove)
                }

                list.appendChild(li)
            })
        }

        function say(text) {
            message.textContent = text
            message.hidden = !text
        }

        function add(files) {
            say('')

            if (serverErrors) {
                serverErrors.hidden = true
            }

            Array.prototype.forEach.call(files, function (file) {
                var same = rows.some(function (row) {
                    return row.file.name === file.name && row.file.size === file.size && row.file.lastModified === file.lastModified && row.state !== 'saved'
                })

                if (same) {
                    return
                }

                var row = { file: file, state: 'checking', percent: 0, format: null, reason: '' }

                rows.push(row)

                sniff(file).then(function (format) {
                    row.format = format

                    if (!format) {
                        row.state = 'rejected'
                        row.reason = WRONG_FORMAT
                    } else if (file.size > MAX_BYTES) {
                        row.state = 'rejected'
                        row.reason = TOO_LARGE
                    } else {
                        row.state = 'validated'
                    }

                    draw()
                }).catch(function () {
                    row.state = 'rejected'
                    row.reason = WRONG_FORMAT
                    draw()
                })
            })

            draw()
        }

        input.addEventListener('change', function () {
            add(input.files)
            input.value = ''
        })

        // "or click to browse": the whole zone; the button itself is the input's label.
        zone.addEventListener('click', function (event) {
            if (!event.target.closest('label')) {
                input.click()
            }
        })

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
                add(event.dataTransfer.files)
            }
        })

        // The fields each document type needs.
        var type = form.querySelector('[data-ppx-upload-type]')
        var licenseTypes = type.getAttribute('data-license-types').split(',')
        var license = form.querySelector('[data-ppx-upload-license]')
        var state = form.querySelector('[data-ppx-upload-state]')
        var prefilled = form.querySelectorAll('[data-profile-value]')

        function setEnabled(box, on) {
            box.hidden = !on
            box.querySelectorAll('input, select').forEach(function (field) { field.disabled = !on })
        }

        function showFields() {
            var nonResident = type.value === 'non_resident'

            setEnabled(license, licenseTypes.indexOf(type.value) !== -1)
            setEnabled(state, nonResident)

            // The profile's number and date are the resident license's, not another state's.
            prefilled.forEach(function (field) {
                var value = field.getAttribute('data-profile-value')

                if (nonResident && field.value === value) {
                    field.value = ''
                } else if (!nonResident && field.value === '') {
                    field.value = value
                }
            })
        }

        type.addEventListener('change', showFields)
        showFields()

        function clearFieldErrors() {
            form.querySelectorAll('[data-ppx-field-error]').forEach(function (element) {
                element.textContent = ''
                element.hidden = true
            })
        }

        // Errors of the other fields go under those fields. True if any did.
        function showFieldErrors(errors) {
            var first = null

            form.querySelectorAll('[data-ppx-field-error]').forEach(function (element) {
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

        function finish(stoppedOnFields) {
            sending = false
            submit.disabled = false
            draw()

            if (stoppedOnFields) {
                return
            }

            var saved = rows.filter(function (row) { return row.state === 'saved' }).length
            var rejected = rows.some(function (row) { return row.state === 'rejected' })
            var waiting = rows.some(function (row) { return row.state === 'validated' || row.state === 'checking' })

            if (saved > 0 && !rejected && !waiting) {
                window.location.href = form.getAttribute('data-done')

                return
            }

            if (saved > 0) {
                say(saved + (saved === 1 ? ' document was saved.' : ' documents were saved.') + (rejected ? ' The files marked Rejected were not.' : '') + (waiting ? ' Choose Save document for the remaining files.' : ''))
                cancel.textContent = 'Done'
            }
        }

        function send(row, done) {
            var data = new FormData(form)

            data.delete('file[]')
            data.set('file', row.file, row.file.name)

            var xhr = new XMLHttpRequest()

            xhr.open('POST', form.action)
            xhr.setRequestHeader('Accept', 'application/json')
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest')

            row.xhr = xhr
            row.state = 'uploading'
            row.percent = 0
            draw()

            // In place: redrawing the list here would swallow a click on a row's button.
            xhr.upload.addEventListener('progress', function (e) {
                if (!e.lengthComputable) {
                    return
                }

                row.percent = Math.round((e.loaded / e.total) * 100)
                row.progress.value = row.percent
                row.percentLabel.textContent = percentText(row)

                if (row.percent >= 100) {
                    draw()
                }
            })

            // The row's button took it off the list.
            xhr.addEventListener('abort', function () { done(false) })

            function reject(reason) {
                row.state = 'rejected'
                row.reason = reason
                done(false)
            }

            xhr.addEventListener('load', function () {
                var body = {}

                try {
                    body = JSON.parse(xhr.responseText || '{}')
                } catch (e) {
                    body = {}
                }

                if (xhr.status >= 200 && xhr.status < 300) {
                    row.state = 'saved'
                    done(false)

                    return
                }

                if (body.errors && body.errors.file) {
                    reject(body.errors.file[0])

                    return
                }

                if (body.errors && showFieldErrors(body.errors)) {
                    row.state = 'validated'
                    done(true)

                    return
                }

                var known = {
                    413: TOO_LARGE,
                    419: 'Your session has expired. Reload the page and try again.',
                    429: 'Too many uploads in a short time. Wait a minute and try again.',
                }

                reject(known[xhr.status] || body.message || UNSENT)
            })

            xhr.addEventListener('error', function () { reject(UNSENT) })

            xhr.send(data)
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault()

            if (sending) {
                return
            }

            var queue = rows.filter(function (row) { return row.state === 'validated' })

            say('')
            clearFieldErrors()

            if (queue.length === 0) {
                say('Choose at least one PDF, JPG or PNG file.')
                input.focus()

                return
            }

            sending = true
            submit.disabled = true

            ;(function next() {
                var row = queue.shift()

                // Taken off the list while waiting.
                if (row && rows.indexOf(row) === -1) {
                    next()

                    return
                }

                if (!row) {
                    finish(false)

                    return
                }

                send(row, function (stop) {
                    if (stop) {
                        finish(true)
                    } else {
                        next()
                    }
                })
            })()
        })
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form[data-ppx-upload-page]').forEach(init)
    })
})()
