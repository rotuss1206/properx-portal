/*
 * Member area behaviour (resources/views/components/member/layout.blade.php): the sidebar's
 * collapsed state (same localStorage key as the Nova sidebar), the mobile drawer and the
 * account menu. DOM APIs only (CSP: no inline handlers, no eval). Loaded in <head> without
 * defer so the collapsed state applies before the first paint.
 */
(function () {
    var root = document.documentElement
    var STORAGE_KEY = 'ppx-sidebar-collapsed'

    function readCollapsed() {
        try {
            return window.localStorage.getItem(STORAGE_KEY) === '1'
        } catch (e) {
            return false
        }
    }

    function writeCollapsed(collapsed) {
        try {
            window.localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0')
        } catch (e) {
            // Storage blocked: the state lasts for this page only.
        }
    }

    root.classList.toggle('ppx-sidebar-collapsed', readCollapsed())

    document.addEventListener('DOMContentLoaded', function () {
        var collapse = document.querySelector('[data-ppx-collapse]')
        var openButton = document.querySelector('[data-ppx-drawer-open]')
        var sidebar = document.getElementById('ppx-sidebar')
        var overlay = document.querySelector('.ppx-member__overlay')
        var account = document.querySelector('[data-ppx-account]')
        var accountMenu = account && document.getElementById(account.getAttribute('aria-controls'))

        // Collapse (desktop): icons only, names as tooltips and for screen readers.
        function applyCollapsed(collapsed) {
            root.classList.toggle('ppx-sidebar-collapsed', collapsed)

            if (collapse) {
                collapse.setAttribute('aria-expanded', collapsed ? 'false' : 'true')
                collapse.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar')
            }

            document.querySelectorAll('.ppx-sidebar__item').forEach(function (item) {
                if (collapsed) {
                    item.setAttribute('title', item.getAttribute('data-ppx-label'))
                } else {
                    item.removeAttribute('title')
                }
            })
        }

        applyCollapsed(readCollapsed())

        if (collapse) {
            collapse.addEventListener('click', function () {
                var collapsed = !root.classList.contains('ppx-sidebar-collapsed')
                writeCollapsed(collapsed)
                applyCollapsed(collapsed)
            })
        }

        // Drawer (tablets and phones), opened by the header button.
        function setDrawer(open) {
            root.classList.toggle('ppx-member-menu-open', open)

            if (overlay) {
                overlay.hidden = !open
            }

            if (openButton) {
                openButton.setAttribute('aria-expanded', open ? 'true' : 'false')
            }

            if (open && sidebar) {
                var first = sidebar.querySelector('.ppx-sidebar__item')

                if (first) {
                    first.focus()
                }
            } else if (!open && openButton && sidebar && sidebar.contains(document.activeElement)) {
                openButton.focus()
            }
        }

        if (openButton) {
            openButton.addEventListener('click', function () { setDrawer(true) })
        }

        document.querySelectorAll('[data-ppx-drawer-close]').forEach(function (element) {
            element.addEventListener('click', function () { setDrawer(false) })
        })

        // Leaving the tablet layout closes the drawer.
        if (window.matchMedia) {
            var desktop = window.matchMedia('(min-width: 1024px)')
            var onChange = function () { if (desktop.matches) { setDrawer(false) } }

            if (desktop.addEventListener) {
                desktop.addEventListener('change', onChange)
            }
        }

        // Account menu.
        function setAccountMenu(open) {
            if (!account || !accountMenu) {
                return
            }

            account.setAttribute('aria-expanded', open ? 'true' : 'false')
            accountMenu.hidden = !open

            if (open) {
                var firstItem = accountMenu.querySelector('a, button')

                if (firstItem) {
                    firstItem.focus()
                }
            }
        }

        if (account && accountMenu) {
            account.addEventListener('click', function () {
                setAccountMenu(account.getAttribute('aria-expanded') !== 'true')
            })

            document.addEventListener('click', function (event) {
                if (!account.contains(event.target) && !accountMenu.contains(event.target)) {
                    setAccountMenu(false)
                }
            })
        }

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') {
                return
            }

            if (accountMenu && !accountMenu.hidden) {
                setAccountMenu(false)
                account.focus()
            } else if (root.classList.contains('ppx-member-menu-open')) {
                setDrawer(false)
            }
        })
    })
})()
