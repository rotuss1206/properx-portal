/*
 * ProPerx header for Nova (signed-in pages): the same header as the member area
 * (resources/views/components/member/header.blade.php, styles public/portal/header.css).
 * Nova's MainHeader cannot be replaced, so three of its global components are swapped as
 * Nova registers them:
 *   - GlobalSearch -> page title (from the document title Nova sets for every page), date,
 *     and Nova's own search for staff, restyled;
 *   - UserMenu     -> avatar with Profile, Security and Sign out;
 *   - ThemeDropdown -> nothing (not in the design);
 *   - NotificationCenter -> the member area's bell and notifications panel (public/portal/
 *     notify.js opens it, notify.css styles it) with Nova's own notifications.
 * Render functions only (no unsafe-eval). If a swapped component throws, Nova's original
 * renders and html.ppx-nova-header is removed, so Nova's header comes back (for the panel
 * only html.ppx-nova-notify, so Nova's own notification center returns). Depends on Nova 5
 * internals (component names, MainHeader markup, the notifications store): recheck after
 * Nova upgrades.
 */
(function () {
    var Vue = window.Vue
    var data = window.Nova && Nova.config && Nova.config('portalHeader')

    if (!Vue || !data || typeof Nova.booting !== 'function') {
        return
    }

    var h = Vue.h
    var root = document.documentElement

    function shell(on) {
        root.classList.toggle('ppx-nova-header', on)
    }

    shell(true)

    // Light theme only (no theme switcher): public/portal/light-theme.js stores the choice
    // before Nova reads it; this covers browsers where storage is blocked.
    root.classList.remove('dark')

    function icon(name, className) {
        return h('svg', {
            class: className,
            viewBox: '0 0 24 24',
            fill: 'none',
            stroke: 'currentColor',
            'stroke-width': '2',
            'stroke-linecap': 'round',
            'stroke-linejoin': 'round',
            'aria-hidden': 'true',
            focusable: 'false',
        }, [h('use', { href: data.icons + '#' + name })])
    }

    // Page title: Nova titles every page "<title> - <app name>" (Inertia Head).
    var suffix = ' - ' + Nova.config('appName')
    var title = Vue.ref('')

    function readTitle() {
        var text = document.title || ''

        title.value = text.slice(-suffix.length) === suffix ? text.slice(0, -suffix.length) : text
    }

    readTitle()
    new MutationObserver(readTitle).observe(document.head, { childList: true, subtree: true, characterData: true })

    function headerMain(NovaSearch) {
        return {
            name: 'PortalHeaderMain',
            inheritAttrs: false,

            render: function () {
                return h('div', { class: 'ppx-header__main' }, [
                    h('h1', { class: 'ppx-header__title' }, title.value),
                    h('div', { class: 'ppx-header__extras' }, [
                        h('time', { class: 'ppx-header__date', datetime: data.dateIso }, data.date),
                        data.search ? h('div', { class: 'ppx-header__nova-search' }, [h(NovaSearch, this.$attrs)]) : null,
                    ]),
                ])
            },
        }
    }

    var AccountMenu = {
        name: 'PortalAccountMenu',
        inheritAttrs: false,

        data: function () {
            return { open: false }
        },

        mounted: function () {
            var vm = this

            this.onDocumentClick = function (event) {
                if (vm.open && !vm.$el.contains(event.target)) {
                    vm.open = false
                }
            }

            this.onKeydown = function (event) {
                if (vm.open && event.key === 'Escape') {
                    vm.open = false
                    vm.$refs.button.focus()
                }
            }

            document.addEventListener('click', this.onDocumentClick)
            document.addEventListener('keydown', this.onKeydown)
        },

        beforeUnmount: function () {
            document.removeEventListener('click', this.onDocumentClick)
            document.removeEventListener('keydown', this.onKeydown)
        },

        methods: {
            toggle: function () {
                var vm = this
                vm.open = !vm.open

                if (vm.open) {
                    vm.$nextTick(function () {
                        var first = vm.$el.querySelector('.ppx-header__menu-item')

                        if (first) {
                            first.focus()
                        }
                    })
                }
            },

            // As Nova's own menu does (store action "logout"), without its confirmation dialog,
            // like the member area.
            signOut: function () {
                this.$store.dispatch('logout', Nova.config('customLogoutPath'))
                    .then(function (redirect) {
                        if (redirect) {
                            window.location.href = redirect
                        } else {
                            Nova.redirectToLogin()
                        }
                    })
                    .catch(function () { window.location.reload() })
            },
        },

        render: function () {
            var vm = this
            var Link = Vue.resolveComponent('Link')
            var close = function () { vm.open = false }
            var link = function (href, iconName, text) {
                return h(Link, { class: 'ppx-header__menu-item', href: href, onClick: close }, {
                    default: function () { return [icon(iconName, 'ppx-header__menu-icon'), ' ' + text] },
                })
            }

            return h('div', { class: 'ppx-header__account' }, [
                h('button', {
                    ref: 'button',
                    type: 'button',
                    class: 'ppx-header__avatar-button',
                    'aria-haspopup': 'true',
                    'aria-expanded': vm.open ? 'true' : 'false',
                    'aria-controls': 'ppx-account-menu',
                    onClick: vm.toggle,
                }, [
                    h('span', { class: 'ppx-header__avatar', 'aria-hidden': 'true' }, data.initials),
                    h('span', { class: 'ppx-visually-hidden' }, 'Account menu for ' + data.name),
                    icon('chevron-down', 'ppx-header__chevron'),
                ]),
                vm.open ? h('div', { class: 'ppx-header__menu-panel', id: 'ppx-account-menu' }, [
                    data.profileUrl ? link(data.profileUrl, 'profile', 'Profile') : null,
                    link(data.securityUrl, 'shield', 'Security'),
                    h('button', { type: 'button', class: 'ppx-header__menu-item', onClick: vm.signOut }, [
                        icon('sign-out', 'ppx-header__menu-icon'), ' Sign out',
                    ]),
                ]) : null,
            ])
        },
    }

    var Nothing = { name: 'PortalNoThemeDropdown', render: function () { return null } }

    // The member area's notifications panel (public/portal/notify.js + notify.css) with Nova's
    // own notifications (store module nova/notifications, nova_notifications table). notify.js
    // opens and closes it (bell, ✕, backdrop, Esc, back button); this draws it.
    function notifyLayout(on) {
        root.classList.toggle('ppx-nova-notify', on)

        if (!on) {
            root.classList.remove('ppx-notify-modal')
        }
    }

    var NotificationPanel = {
        name: 'PortalNotificationCenter',
        inheritAttrs: false,

        data: function () {
            return { open: window.PortalNotify.isOpen() }
        },

        computed: {
            notifications: function () {
                return this.$store.getters['nova/notifications'] || []
            },

            unread: function () {
                return Number(this.$store.getters['nova/unreadNotifications']) || 0
            },
        },

        created: function () {
            this.$store.dispatch('nova/fetchNotifications')
        },

        mounted: function () {
            var vm = this

            this.onChange = function (event) { vm.open = event.detail.open }
            this.onRefresh = function () { vm.$store.dispatch('nova/fetchNotifications') }

            document.addEventListener('ppx-notify:change', this.onChange)
            Nova.$on('refresh-notifications', this.onRefresh)
            notifyLayout(true)
            this.open = window.PortalNotify.isOpen()
        },

        beforeUnmount: function () {
            document.removeEventListener('ppx-notify:change', this.onChange)
            Nova.$off('refresh-notifications', this.onRefresh)
            notifyLayout(false)
        },

        methods: {
            // As Nova's own panel does: mark it read, then follow its link (the modal modes close first).
            choose: function (notification) {
                if (!notification.read_at) {
                    this.$store.dispatch('nova/markNotificationAsRead', notification.id)
                }

                if (!notification.actionUrl) {
                    return
                }

                var go = function () {
                    Nova.visit(notification.actionUrl, { openInNewTab: notification.openInNewTab || false })
                }

                if (window.PortalNotify.mode() === 'push' || notification.openInNewTab) {
                    go()
                } else {
                    window.PortalNotify.close().then(go)
                }
            },

            readAll: function () {
                this.$store.dispatch('nova/markAllNotificationsAsRead')
            },
        },

        render: function () {
            var vm = this
            var unread = vm.unread
            var count = unread > 99 ? '99+' : String(unread)

            var items = vm.notifications.map(function (notification) {
                var isUnread = !notification.read_at

                return h('li', { key: notification.id, class: ['ppx-notify__item', isUnread ? 'is-unread' : null] }, [
                    h('button', { type: 'button', class: 'ppx-notify__link', onClick: function () { vm.choose(notification) } }, [
                        h('span', { class: 'ppx-notify__item-title' }, notification.message),
                        h('span', { class: 'ppx-notify__time', title: notification.created_at }, notification.created_at_friendly),
                    ]),
                    isUnread ? h('span', { class: 'ppx-notify__dot' }, [h('span', { class: 'ppx-visually-hidden' }, 'Unread')]) : null,
                ])
            })

            var list = items.length
                ? [
                    h('ul', { class: 'ppx-notify__list' }, items),
                    unread ? h('div', { class: 'ppx-notify__actions' }, [
                        h('button', { type: 'button', class: 'ppx-notify__read-all', onClick: vm.readAll }, 'Mark all read'),
                    ]) : null,
                ]
                : [h('div', { class: 'ppx-notify__empty' }, [
                    h('span', { class: 'ppx-notify__empty-icon' }, [icon('bell', 'ppx-notify__empty-svg')]),
                    h('p', { class: 'ppx-notify__empty-title' }, 'You are all caught up'),
                    h('p', { class: 'ppx-notify__empty-text' }, 'New notifications appear here.'),
                ])]

            return [
                h('button', {
                    type: 'button',
                    class: 'ppx-header__button ppx-header__bell',
                    'data-ppx-notify-toggle': '',
                    'aria-controls': 'ppx-notify',
                    'aria-expanded': vm.open ? 'true' : 'false',
                    'aria-label': unread ? 'Notifications, ' + unread + ' unread' : 'Notifications',
                }, [
                    h('span', { class: 'ppx-header__glyph ppx-header__glyph--bell', 'aria-hidden': 'true' }),
                    unread ? h('span', { class: 'ppx-header__count', 'aria-hidden': 'true' }, count) : null,
                ]),
                h('p', { class: 'ppx-visually-hidden ppx-notify-live', 'aria-live': 'polite' }, unread ? unread + ' unread notifications' : 'No unread notifications'),
                h(Vue.Teleport, { to: 'body' }, [
                    h('div', { class: 'ppx-notify-backdrop', 'data-ppx-notify-close': '' }),
                    h('aside', {
                        id: 'ppx-notify',
                        class: 'ppx-notify',
                        'aria-labelledby': 'ppx-notify-title',
                        role: vm.open && window.PortalNotify.mode() !== 'push' ? 'dialog' : null,
                        'aria-modal': vm.open && window.PortalNotify.mode() !== 'push' ? 'true' : null,
                    }, [
                        h('div', { class: 'ppx-notify__header' }, [
                            h('h2', { class: 'ppx-notify__title', id: 'ppx-notify-title', tabindex: '-1', 'data-ppx-notify-title': '' }, 'Notifications'),
                            unread ? h('span', { class: 'ppx-notify__count', 'aria-hidden': 'true' }, count) : null,
                            h('button', { type: 'button', class: 'ppx-notify__close', 'data-ppx-notify-close': '', 'aria-label': 'Close notifications' }, [
                                icon('close', 'ppx-notify__close-svg'),
                            ]),
                        ]),
                        h('div', { class: 'ppx-notify__body' }, [
                            h('section', { class: 'ppx-notify__section', 'aria-label': 'Recent notifications' }, list),
                        ]),
                    ]),
                ]),
            ]
        },
    }

    var replacements = {
        GlobalSearch: function (original) { return headerMain(original) },
        UserMenu: function () { return AccountMenu },
        ThemeDropdown: function () { return Nothing },
        // Without public/portal/notify.js Nova's own notification center stays.
        NotificationCenter: function (original) { return window.PortalNotify ? NotificationPanel : original },
    }

    // What a failing component takes back with it: the whole header, or only the panel.
    var fallbacks = {
        NotificationCenter: function () { notifyLayout(false) },
    }

    var replaced = {}

    function withFallback(name, original, portal) {
        return {
            name: name,
            inheritAttrs: false,

            data: function () {
                return { failed: false }
            },

            errorCaptured: function (error) {
                if (!this.failed) {
                    this.failed = true

                    if (fallbacks[name]) {
                        fallbacks[name]()
                    } else {
                        shell(false)
                    }

                    console.error('ProPerx ' + name + ' failed; showing Nova\'s own.', error && error.name)
                }

                return false
            },

            render: function () {
                return h(this.failed ? original : portal, this.$attrs)
            },
        }
    }

    // Same approach as resources/js/nova/sidebar.js: swap the components as Nova registers them.
    Nova.booting(function (app) {
        var register = app.component

        app.component = function (name, component) {
            if (arguments.length > 1 && component && replacements[name] && !replaced[name]) {
                replaced[name] = true

                return register.call(app, name, withFallback(name, component, replacements[name](component)))
            }

            return register.apply(app, arguments)
        }
    })

    Nova.booted(function () {
        if (!replaced.GlobalSearch || !replaced.UserMenu) {
            shell(false)
        }
    })
})()
