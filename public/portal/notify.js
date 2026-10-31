/*
 * The notifications panel (right column of the mockups), shared by the member area
 * (resources/views/components/member/notify-panel.blade.php) and Nova (drawn by
 * resources/js/nova/header.js with the same markup). Styles: public/portal/notify.css.
 *
 * Three modes by viewport width:
 *   - push    (>= 1280 px): a column beside the page, which shrinks; the open state is
 *             remembered per context in localStorage;
 *   - overlay (1024-1279 px): over the page with a backdrop; ✕, backdrop click and Esc close it;
 *   - full    (< 1024 px): a full-screen sheet; ✕, Esc and the browser's back button close it.
 * Overlay and full are modal: focus stays inside, the page does not scroll.
 *
 * Markup contract: #ppx-notify (the panel), [data-ppx-notify-toggle] (bells),
 * [data-ppx-notify-close] (✕, backdrop), [data-ppx-notify-readall] (member "Mark all read"
 * form). State: html[data-ppx-notify="open|closed"]; the "ppx-notify:change" event tells Vue.
 * Loaded in <head> without defer, so the remembered state applies before the first paint.
 * DOM APIs only (CSP: no inline handlers, no eval).
 */
(function () {
    if (window.PortalNotify) {
        return
    }

    var root = document.documentElement
    var script = document.currentScript
    var context = (script && script.getAttribute('data-context')) || 'member'
    var defaultOpen = !!script && script.getAttribute('data-default') === 'open'
    var STORAGE_KEY = 'ppx-notify-open:' + context
    var PUSH = '(min-width: 1280px)'
    var FULL = '(max-width: 1023px)'

    var open = false
    var historyMarker = false
    var afterBack = null
    var returnFocus = null

    function media(query) {
        return !!(window.matchMedia && window.matchMedia(query).matches)
    }

    function mode() {
        return media(PUSH) ? 'push' : (media(FULL) ? 'full' : 'overlay')
    }

    function readStored() {
        try {
            var value = window.localStorage.getItem(STORAGE_KEY)

            return value === null ? defaultOpen : value === '1'
        } catch (e) {
            return defaultOpen
        }
    }

    function writeStored(value) {
        try {
            window.localStorage.setItem(STORAGE_KEY, value ? '1' : '0')
        } catch (e) {
            // Storage blocked: the state lasts for this page only.
        }
    }

    function panel() {
        return document.getElementById('ppx-notify')
    }

    function focusable(container) {
        return Array.prototype.filter.call(
            container.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea, [tabindex]:not([tabindex="-1"])'),
            function (element) { return element.offsetParent !== null || element === document.activeElement }
        )
    }

    function apply() {
        var modal = open && mode() !== 'push'

        // The page's scrollbar goes while a modal is open; its width stays as padding, so nothing moves.
        if (modal && !root.classList.contains('ppx-notify-modal')) {
            root.style.setProperty('--ppx-scrollbar-gap', (window.innerWidth - root.clientWidth) + 'px')
        }

        root.setAttribute('data-ppx-notify', open ? 'open' : 'closed')
        root.classList.toggle('ppx-notify-modal', modal)

        document.querySelectorAll('[data-ppx-notify-toggle]').forEach(function (bell) {
            bell.setAttribute('aria-expanded', open ? 'true' : 'false')
        })

        var element = panel()

        if (element) {
            if (modal) {
                element.setAttribute('role', 'dialog')
                element.setAttribute('aria-modal', 'true')
            } else {
                element.removeAttribute('role')
                element.removeAttribute('aria-modal')
            }
        }

        document.dispatchEvent(new CustomEvent('ppx-notify:change', { detail: { open: open, mode: mode() } }))
    }

    function setOpen(value, options) {
        options = options || {}

        if (value === open) {
            return Promise.resolve()
        }

        open = value
        var current = mode()

        if (current === 'push' && !options.temporary) {
            writeStored(open)
        }

        if (open) {
            returnFocus = options.focus === false ? null : document.activeElement
            apply()

            if (current === 'full') {
                // The back button closes the sheet: one history entry while it is open.
                window.history.pushState(window.history.state, '', window.location.href)
                historyMarker = true
            }

            if (options.focus !== false) {
                // The panel is visible as soon as the attribute is set (focus() recalculates styles).
                var opened = panel()
                var title = opened && opened.querySelector('[data-ppx-notify-title]')

                if (title) {
                    title.focus({ preventScroll: true })
                }
            }

            return Promise.resolve()
        }

        var element = panel()
        var hadFocus = element && element.contains(document.activeElement)

        apply()

        if (hadFocus || returnFocus) {
            var bell = document.querySelector('[data-ppx-notify-toggle]')

            if (bell && (hadFocus || returnFocus === bell)) {
                bell.focus({ preventScroll: true })
            }
        }

        returnFocus = null

        if (historyMarker && !options.fromHistory) {
            // Take our entry off again; the popstate it causes is ours (see below).
            historyMarker = false

            return new Promise(function (resolve) {
                afterBack = resolve
                window.history.back()
            })
        }

        historyMarker = false

        return Promise.resolve()
    }

    function onModeChange() {
        if (mode() === 'push') {
            if (historyMarker) {
                historyMarker = false
                window.history.back()
                afterBack = function () {}
            }

            open = readStored()
            apply()
        } else if (open) {
            // A column that no longer fits does not turn into a modal over the page.
            setOpen(false, { temporary: true, focus: false })
        } else {
            apply()
        }
    }

    // Before the first paint: the remembered column on wide screens; never a modal on load.
    open = mode() === 'push' && readStored()
    root.setAttribute('data-ppx-notify', open ? 'open' : 'closed')

    window.PortalNotify = {
        isOpen: function () { return open },
        mode: mode,
        open: function () { return setOpen(true) },
        close: function () { return setOpen(false) },
        toggle: function () { return setOpen(!open) },
    }

    // Registered before Nova's (Inertia's) own listener, so the closing step of the sheet
    // never reaches the router: the page stays as it is.
    window.addEventListener('popstate', function (event) {
        if (afterBack) {
            var done = afterBack
            afterBack = null
            event.stopImmediatePropagation()
            done()

            return
        }

        if (historyMarker && open) {
            historyMarker = false
            event.stopImmediatePropagation()
            setOpen(false, { fromHistory: true })
        }
    })

    document.addEventListener('click', function (event) {
        var target = event.target instanceof Element ? event.target : null

        if (!target) {
            return
        }

        if (target.closest('[data-ppx-notify-toggle]')) {
            event.preventDefault()
            setOpen(!open)

            return
        }

        if (target.closest('[data-ppx-notify-close]')) {
            event.preventDefault()
            setOpen(false)

            return
        }

        // Member area: a notification link while the sheet holds a history entry; the entry
        // goes first, so "back" on the next page does not land on the same page twice.
        var link = target.closest('#ppx-notify a[href]')

        if (link && historyMarker && !event.defaultPrevented && event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey) {
            event.preventDefault()
            setOpen(false).then(function () { window.location.assign(link.href) })
        }
    })

    document.addEventListener('keydown', function (event) {
        if (!open) {
            return
        }

        var element = panel()
        var modal = mode() !== 'push'

        if (event.key === 'Escape' || event.key === 'Esc') {
            if (modal || (element && element.contains(document.activeElement))) {
                event.preventDefault()
                setOpen(false)
            }

            return
        }

        if (event.key === 'Tab' && modal && element) {
            var items = focusable(element)
            var first = items[0]
            var last = items[items.length - 1]

            if (!first) {
                event.preventDefault()

                return
            }

            if (!element.contains(document.activeElement)) {
                event.preventDefault()
                first.focus()
            } else if (event.shiftKey && (document.activeElement === first || !items.includes(document.activeElement))) {
                event.preventDefault()
                last.focus()
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault()
                first.focus()
            }
        }
    })

    // Member area: "Mark all read" without leaving the page (the form still works without JS).
    document.addEventListener('submit', function (event) {
        var form = event.target instanceof Element && event.target.closest('[data-ppx-notify-readall]')

        if (!form || !window.fetch) {
            return
        }

        event.preventDefault()

        var token = form.querySelector('input[name="_token"]')

        window.fetch(form.action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': token ? token.value : '',
                'X-Requested-With': 'XMLHttpRequest',
            },
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('Request failed')
            }

            PortalNotify.markAllRead()
        }).catch(function () {
            form.submit()
        })
    })

    // Member area DOM after "Mark all read": no unread rows, counts, dots or the button.
    window.PortalNotify.markAllRead = function () {
        var element = panel()

        if (element) {
            element.querySelectorAll('.is-unread').forEach(function (item) { item.classList.remove('is-unread') })
            element.querySelectorAll('.ppx-notify__dot').forEach(function (dot) { dot.remove() })
            element.querySelectorAll('[data-ppx-notify-count], [data-ppx-notify-readall]').forEach(function (node) { node.hidden = true })
        }

        document.querySelectorAll('[data-ppx-notify-toggle]').forEach(function (bell) {
            var count = bell.querySelector('.ppx-header__count')

            if (count) {
                count.remove()
            }

            bell.setAttribute('aria-label', 'Notifications')
        })

        document.querySelectorAll('[data-ppx-notify-live]').forEach(function (live) {
            live.textContent = 'All notifications marked as read'
        })
    }

    function ready() {
        apply()

        // A failed upload in the panel: show its error even where the panel starts closed.
        var element = panel()

        if (!open && element && element.querySelector('[data-ppx-notify-force]')) {
            setOpen(true, { temporary: true, focus: false })
        }

        if (window.matchMedia) {
            [PUSH, FULL].forEach(function (query) {
                var list = window.matchMedia(query)

                if (list.addEventListener) {
                    list.addEventListener('change', onModeChange)
                }
            })
        }

        // Transitions only after the first paint, so a remembered open column does not slide in.
        window.requestAnimationFrame(function () {
            window.requestAnimationFrame(function () { root.classList.add('ppx-notify-ready') })
        })
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ready)
    } else {
        ready()
    }
})()
