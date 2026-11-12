/*
 * Filter chips (x-ppx.chips) without jumps:
 *  - The filtered list ([data-ppx-chips-area]) never gets shorter than it was with the first chip
 *    ("Everyone", "All documents"): its height is kept as min-height for this page (also across a
 *    reload, in sessionStorage), so the page below does not move and an empty group's message
 *    stays in the middle of the same space.
 *  - On a plain page a chip does not reload the page: the chip's address is fetched and the parts
 *    marked [data-ppx-chips-swap="name"] are replaced with the new ones (the scroll position stays),
 *    and the address bar follows. Without JavaScript, or if the request fails, the link is followed.
 *  - Livewire chips (wire:click) switch on their own; only the height is kept.
 * DOM APIs only, no innerHTML (CSP).
 */
(function () {
    var KEY = 'ppx-chips-height:' + location.pathname

    function area() {
        return document.querySelector('[data-ppx-chips-area]')
    }

    function stored() {
        try {
            return parseFloat(sessionStorage.getItem(KEY)) || 0
        } catch (e) {
            return 0
        }
    }

    function store(height) {
        try {
            sessionStorage.setItem(KEY, String(Math.round(height)))
        } catch (e) {}
    }

    function firstChipActive() {
        var first = document.querySelector('[data-ppx-chips] .ppx-chips__chip')

        return !!first && first.classList.contains('is-active')
    }

    // The tallest the list has been with everything shown, or just now.
    function hold() {
        var list = area()

        if (!list) {
            return
        }

        var current = parseFloat(list.style.minHeight) || 0
        var height = Math.max(current, stored(), list.getBoundingClientRect().height)

        list.style.minHeight = height + 'px'
    }

    function remember() {
        var list = area()

        if (list && firstChipActive()) {
            store(Math.max(stored(), list.getBoundingClientRect().height))
        }

        if (list && stored() > 0) {
            list.style.minHeight = Math.max(parseFloat(list.style.minHeight) || 0, stored()) + 'px'
        }
    }

    function isLivewire(link) {
        for (var i = 0; i < link.attributes.length; i++) {
            if (link.attributes[i].name.indexOf('wire:') === 0) {
                return true
            }
        }

        return false
    }

    function swap(url, link) {
        var href = url.href
        var keep = parseFloat((area() || {}).style ? area().style.minHeight : 0) || 0

        return fetch(url.pathname + url.search, { credentials: 'same-origin', headers: { Accept: 'text/html' } })
            .then(function (response) {
                if (!response.ok || new URL(response.url).pathname !== url.pathname) {
                    throw new Error('not swappable')
                }

                return response.text()
            })
            .then(function (html) {
                var next = new DOMParser().parseFromString(html, 'text/html')
                var parts = document.querySelectorAll('[data-ppx-chips-swap]')

                parts.forEach(function (part) {
                    var name = part.getAttribute('data-ppx-chips-swap')
                    var replacement = next.querySelector('[data-ppx-chips-swap="' + name + '"]')

                    if (replacement) {
                        part.replaceWith(document.importNode(replacement, true))
                    }
                })

                history.replaceState(history.state, '', href)

                var list = area()

                if (list) {
                    list.style.minHeight = Math.max(keep, stored()) + 'px'
                }

                remember()

                var chip = document.querySelector('[data-ppx-chips] a[href="' + link.getAttribute('href') + '"]')

                if (chip) {
                    chip.focus({ preventScroll: true })
                }

                document.dispatchEvent(new CustomEvent('ppx:chips-updated'))
            })
            .catch(function () {
                location.assign(href)
            })
    }

    document.addEventListener('click', function (event) {
        var link = event.target.closest && event.target.closest('[data-ppx-chips] a.ppx-chips__chip')

        if (!link) {
            return
        }

        hold()

        if (isLivewire(link) || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return
        }

        var url = new URL(link.href, location.href)

        if (url.origin !== location.origin || url.pathname !== location.pathname || !document.querySelector('[data-ppx-chips-swap]')) {
            return
        }

        event.preventDefault()
        swap(url, link)
    }, true)

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', remember)
    } else {
        remember()
    }

    // Livewire swaps its own chips; the list it renders keeps the height.
    document.addEventListener('livewire:navigated', remember)
    document.addEventListener('livewire:init', function () {
        if (window.Livewire && typeof window.Livewire.hook === 'function') {
            window.Livewire.hook('morphed', function () { remember() })
        }
    })
})()
