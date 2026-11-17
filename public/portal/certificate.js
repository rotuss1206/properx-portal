/*
 * /member/certificate: scales the preview to its column (the sheet is laid out at its real size) and shows
 * the options at once, without a round trip. "Generate & download" sends the same choices to the server:
 * the PDF by the form itself; the PNG is drawn here from the preview, after the server has checked access,
 * fixed the date of issue and audited it (POST data-png-url). The drawing needs no library: the sheet, its
 * styles and its fonts (as data: URLs) go into an SVG <foreignObject>, which a canvas draws at twice the
 * sheet's size. DOM APIs only (CSP: no eval, images from data: and blob:).
 */
(function () {
    var SCALE = 2

    document.addEventListener('DOMContentLoaded', function () {
        var paper = document.querySelector('[data-ppx-cert-paper]')
        var page = document.querySelector('[data-ppx-cert-page]')
        var form = document.querySelector('[data-ppx-cert-form]')

        if (!paper || !page) {
            return
        }

        var sheet = page.querySelector('[data-ppx-cert-sheet]')

        function fit() {
            page.style.setProperty('--ppx-cert-scale', String(paper.clientWidth / sheet.offsetWidth))
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

                // The bottom line's text makes room for the QR.
                if (name === 'qr') {
                    sheet.classList.toggle('cert--qr', toggle.checked)
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

        if (form) {
            form.addEventListener('submit', function (event) {
                var png = form.querySelector('input[name="format"][value="png"]')

                if (png && png.checked) {
                    event.preventDefault()
                    downloadPng(form, sheet)
                }
            })
        }
    })

    function say(form, text) {
        var message = form.querySelector('[data-ppx-cert-message]')

        if (message) {
            message.textContent = text || ''
            message.hidden = !text
        }
    }

    function downloadPng(form, sheet) {
        var button = form.querySelector('button[type="submit"]')
        var body = new FormData(form)

        body.delete('format')
        say(form, '')

        if (button) {
            button.disabled = true
        }

        fetch(form.getAttribute('data-png-url'), {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'X-CSRF-TOKEN': form.getAttribute('data-csrf'), Accept: 'application/json' },
        })
            .then(function (response) {
                return response.json().catch(function () { return {} }).then(function (json) {
                    if (!response.ok) {
                        throw new Error(json.message || 'The certificate could not be prepared. Please try again.')
                    }

                    return json
                })
            })
            .then(function (issue) {
                // The date of the term's first issue, printed on every copy of it.
                sheet.querySelectorAll('[data-ppx-cert="issued"]').forEach(function (part) { part.textContent = issue.issued_on })

                return render(form, sheet).then(function (blob) { save(blob, issue.filename) })
            })
            .catch(function (error) {
                say(form, error && error.message ? error.message : 'The PNG could not be made. Please try the PDF.')
            })
            .then(function () {
                if (button) {
                    button.disabled = false
                }
            })
    }

    function dataUrl(url) {
        return fetch(url, { credentials: 'same-origin' })
            .then(function (response) { return response.blob() })
            .then(function (blob) {
                return new Promise(function (resolve, reject) {
                    var reader = new FileReader()
                    reader.onload = function () { resolve(reader.result) }
                    reader.onerror = reject
                    reader.readAsDataURL(blob)
                })
            })
    }

    // The page's own @font-face rules point at URLs, which an SVG image may not load: they become data: URLs.
    function fontFaces(form) {
        var fonts = JSON.parse(form.getAttribute('data-fonts') || '{}')
        var styles = { 'PPX Lora': 'font-style: italic;', 'PPX Open Sans': '', 'PPX Poppins': '' }

        return Promise.all(Object.keys(fonts).map(function (family) {
            return dataUrl(fonts[family]).then(function (url) {
                return "@font-face { font-family: '" + family + "'; " + (styles[family] || '') + ' font-weight: normal; src: url(' + url + '); }'
            })
        })).then(function (rules) { return rules.join('\n') })
    }

    function render(form, sheet) {
        var width = sheet.offsetWidth
        var height = sheet.offsetHeight
        var css = (document.querySelector('[data-ppx-cert-styles]') || { textContent: '' }).textContent.replace(/@font-face\s*\{[^}]*\}/g, '')

        return fontFaces(form).then(function (faces) {
            var clone = sheet.cloneNode(true)
            var markup = new XMLSerializer().serializeToString(clone)
            var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' + width * SCALE + '" height="' + height * SCALE + '" viewBox="0 0 ' + width + ' ' + height + '">'
                + '<foreignObject x="0" y="0" width="' + width + '" height="' + height + '">'
                + '<div xmlns="http://www.w3.org/1999/xhtml" style="position: relative; width: ' + width + 'px; height: ' + height + 'px;">'
                + '<style>' + faces + '\n' + css + '</style>' + markup + '</div></foreignObject></svg>'

            return new Promise(function (resolve, reject) {
                var image = new Image()

                image.onload = function () {
                    var canvas = document.createElement('canvas')
                    canvas.width = width * SCALE
                    canvas.height = height * SCALE

                    var context = canvas.getContext('2d')
                    context.fillStyle = '#ffffff'
                    context.fillRect(0, 0, canvas.width, canvas.height)
                    context.drawImage(image, 0, 0, canvas.width, canvas.height)
                    canvas.toBlob(function (blob) {
                        blob ? resolve(blob) : reject(new Error('The PNG could not be made. Please try the PDF.'))
                    }, 'image/png')
                }
                image.onerror = function () { reject(new Error('The PNG could not be made. Please try the PDF.')) }
                image.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg)
            })
        })
    }

    function save(blob, filename) {
        var link = document.createElement('a')
        link.href = URL.createObjectURL(blob)
        link.download = filename
        document.body.appendChild(link)
        link.click()
        setTimeout(function () {
            URL.revokeObjectURL(link.href)
            link.remove()
        }, 1000)
    }
})()
