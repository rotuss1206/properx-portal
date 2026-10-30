/*
 * ProPerx header for Nova (signed-in pages): the same header as the member area
 * (resources/views/components/member/header.blade.php, styles public/portal/header.css).
 * Nova's MainHeader cannot be replaced, so three of its global components are swapped as
 * Nova registers them:
 *   - GlobalSearch -> page title (from the document title Nova sets for every page), date,
 *     and Nova's own search for staff, restyled;
 *   - UserMenu     -> avatar with Profile, Security and Sign out;
 *   - ThemeDropdown -> nothing (not in the design).
 * Nova's notification bell stays; resources/css/nova/header.css restyles it. Render
 * functions only (no unsafe-eval). If a swapped component throws, Nova's original renders
 * and html.ppx-nova-header is removed, so Nova's header comes back. Depends on Nova 5
 * internals (component names, MainHeader markup): recheck after Nova upgrades.
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

    var replacements = {
        GlobalSearch: function (original) { return headerMain(original) },
        UserMenu: function () { return AccountMenu },
        ThemeDropdown: function () { return Nothing },
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
                    shell(false)
                    console.error('ProPerx header failed; showing the Nova header.', error && error.name)
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
