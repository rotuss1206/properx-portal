/*
 * Header search of the member area (resources/views/components/member/header.blade.php,
 * App\Http\Controllers\Member\SearchController): suggestions under the field as an ARIA
 * combobox. Arrow keys move through the list, Enter opens the chosen result or, with none
 * chosen, the results page (the form's POST); Esc closes the list. The query is sent in a POST
 * body only. DOM APIs only, text through textContent (CSP: no inline handlers, no eval).
 */
(function () {
    var form = document.querySelector('[data-ppx-search]')

    if (!form || !window.fetch) {
        return
    }

    var input = form.querySelector('input[name="q"]')
    var list = document.getElementById(input.getAttribute('aria-controls'))
    var status = form.querySelector('[data-ppx-search-status]')
    var token = form.querySelector('input[name="_token"]')
    var MIN = 2
    var timer = null
    var request = 0
    var active = -1

    function options() {
        return Array.prototype.slice.call(list.querySelectorAll('[role="option"]'))
    }

    function setActive(index) {
        var items = options()

        active = items.length ? (index + items.length) % items.length : -1
        items.forEach(function (item, i) { item.setAttribute('aria-selected', i === active ? 'true' : 'false') })

        if (active >= 0) {
            input.setAttribute('aria-activedescendant', items[active].id)
            items[active].scrollIntoView({ block: 'nearest' })
        } else {
            input.removeAttribute('aria-activedescendant')
        }
    }

    function open(show) {
        list.hidden = !show
        input.setAttribute('aria-expanded', show ? 'true' : 'false')

        if (!show) {
            setActive(-1)
        }
    }

    function icon(kind) {
        var ns = 'http://www.w3.org/2000/svg'
        var svg = document.createElementNS(ns, 'svg')
        var use = document.createElementNS(ns, 'use')
        var names = { license: 'license', tax: 'tax', certificate: 'certificate', library: 'library' }

        svg.setAttribute('class', 'ppx-search__svg')
        svg.setAttribute('viewBox', '0 0 24 24')
        svg.setAttribute('fill', 'none')
        svg.setAttribute('stroke', 'currentColor')
        svg.setAttribute('stroke-width', '2')
        svg.setAttribute('stroke-linecap', 'round')
        svg.setAttribute('stroke-linejoin', 'round')
        svg.setAttribute('aria-hidden', 'true')
        use.setAttribute('href', form.getAttribute('data-icons') + '#' + (names[kind] || 'license'))
        svg.appendChild(use)

        return svg
    }

    function el(tag, className, text) {
        var node = document.createElement(tag)

        node.className = className

        if (text) {
            node.textContent = text
        }

        return node
    }

    function render(data) {
        list.textContent = ''

        data.results.forEach(function (result, i) {
            var item = el('li', 'ppx-search__option')
            var tile = el('span', 'ppx-search__icon')
            var text = el('span', 'ppx-search__text')

            item.id = 'ppx-search-option-' + i
            item.setAttribute('role', 'option')
            item.setAttribute('aria-selected', 'false')
            item.setAttribute('data-url', result.url)
            tile.appendChild(icon(result.kind))
            text.appendChild(el('span', 'ppx-search__name', result.name))
            text.appendChild(el('span', 'ppx-search__detail', [result.type, result.detail].filter(Boolean).join(' · ')))
            item.appendChild(tile)
            item.appendChild(text)
            item.appendChild(el('span', 'ppx-search__date', result.date || ''))
            list.appendChild(item)
        })

        if (data.total === 0) {
            list.appendChild(el('li', 'ppx-search__empty', 'No matching documents'))
        } else {
            var all = el('li', 'ppx-search__all', data.total > data.results.length ? 'See all ' + data.total + ' results' : 'Open results page')

            all.id = 'ppx-search-option-all'
            all.setAttribute('role', 'option')
            all.setAttribute('aria-selected', 'false')
            all.setAttribute('data-all', '')
            list.appendChild(all)
        }

        status.textContent = data.total === 0 ? 'No results' : data.total + (data.total === 1 ? ' result' : ' results')
        active = -1
        open(true)
    }

    function suggest() {
        var query = input.value.trim()
        var mine = ++request

        if (query.length < MIN) {
            open(false)
            status.textContent = ''

            return
        }

        var body = new FormData()
        body.append('q', query)

        window.fetch(form.getAttribute('data-suggest'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token ? token.value : '', 'X-Requested-With': 'XMLHttpRequest' },
            body: body,
        }).then(function (response) {
            return response.ok ? response.json() : null
        }).then(function (data) {
            if (data && mine === request && document.activeElement === input) {
                render(data)
            }
        }).catch(function () {
            // No suggestions; Enter still opens the results page.
        })
    }

    function choose(item) {
        if (!item) {
            return
        }

        if (item.hasAttribute('data-all')) {
            form.submit()
        } else {
            window.location.assign(item.getAttribute('data-url'))
        }
    }

    input.addEventListener('input', function () {
        window.clearTimeout(timer)
        timer = window.setTimeout(suggest, 200)
    })

    input.addEventListener('focus', function () {
        if (input.value.trim().length >= MIN && list.children.length) {
            open(true)
        }
    })

    input.addEventListener('keydown', function (event) {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault()

            if (list.hidden) {
                if (list.children.length) {
                    open(true)
                } else {
                    suggest()

                    return
                }
            }

            setActive(event.key === 'ArrowDown' ? active + 1 : active - 1)
        } else if (event.key === 'Enter') {
            if (!list.hidden && active >= 0) {
                event.preventDefault()
                choose(options()[active])
            } else if (input.value.trim().length < MIN) {
                event.preventDefault()
            }
        } else if (event.key === 'Escape') {
            if (!list.hidden) {
                event.preventDefault()
                open(false)
            }
        }
    })

    // mousedown keeps the focus in the field until the choice is made.
    list.addEventListener('mousedown', function (event) {
        event.preventDefault()
    })

    list.addEventListener('click', function (event) {
        choose(event.target.closest('[role="option"]'))
    })

    document.addEventListener('click', function (event) {
        if (!form.contains(event.target)) {
            open(false)
        }
    })
})()
