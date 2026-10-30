/*
 * ProPerx sidebar for Nova (signed-in pages). Replaces Nova's MainMenu component with
 * one that draws the same menu (store getter "mainMenu", built by App\Nova\Menu\SidebarMenu
 * on the server, items and access included): logo, collapse button, icons, the
 * "Renewal due" card and the signed-in person. It hides nothing itself.
 *
 * Render functions only (the CSP has no unsafe-eval). Nova's own MainMenu stays the
 * fallback: if this script does not load, or the component throws, Nova's menu renders
 * and the layout overrides are switched off. Depends on Nova 5 internals (component
 * name "MainMenu", AppLayout and MainHeader markup): recheck after Nova upgrades
 * (docs/DEVELOPMENT.md). Styles: public/portal/sidebar.css (shared with the member area)
 * and resources/css/nova/sidebar.css (Nova layout); icons: public/portal/img/icons.svg.
 */
(function () {
    var Vue = window.Vue

    if (!Vue || !window.Nova || typeof Nova.booting !== 'function') {
        return
    }

    var h = Vue.h
    var root = document.documentElement
    var data = Nova.config('portalSidebar') || {}
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
            // Storage blocked (private mode, policies): the state lasts for this page only.
        }
    }

    function shell(on) {
        root.classList.toggle('ppx-shell', on)
    }

    function collapsedClass(on) {
        root.classList.toggle('ppx-sidebar-collapsed', on)
    }

    // Before Nova mounts, so the layout does not jump.
    shell(true)
    collapsedClass(readCollapsed())

    // Icons from the design live in one sprite shared with the member area
    // (public/portal/img/icons.svg), drawn in currentColor with the stroke set here.
    function use(name) {
        return h('use', { href: data.icons + '#' + name })
    }

    function svg(className, viewBox, children, strokeWidth) {
        return h('svg', {
            class: className,
            viewBox: viewBox,
            fill: 'none',
            stroke: 'currentColor',
            'stroke-width': strokeWidth || '2',
            'stroke-linecap': 'round',
            'stroke-linejoin': 'round',
            'aria-hidden': 'true',
            focusable: 'false',
        }, children)
    }

    function icon(name) {
        return svg('ppx-sidebar__svg', '0 0 24 24', [use(name || 'dashboard')])
    }

    // Star and swooshes from the design (the sign-in artwork); ids differ per screen so
    // a hidden copy never owns the gradients.
    function decor(screen) {
        var id = function (name) { return 'ppx-' + screen + '-' + name }

        return h('svg', { class: 'ppx-sidebar__decor', viewBox: '0 0 515 328', fill: 'none', 'aria-hidden': 'true', focusable: 'false' }, [
            h('defs', [
                h('linearGradient', { id: id('fade'), x1: '75', y1: '54', x2: '75', y2: '380', gradientUnits: 'userSpaceOnUse' }, [
                    h('stop', { 'stop-color': '#fff' }), h('stop', { offset: '1', 'stop-color': '#fff', 'stop-opacity': '0' }),
                ]),
                h('linearGradient', { id: id('green'), x1: '18.6', y1: '82.6', x2: '-229', y2: '456', gradientUnits: 'userSpaceOnUse' }, [
                    h('stop', { 'stop-color': '#B8DB34' }), h('stop', { offset: '1', 'stop-color': '#75A728' }),
                ]),
                h('linearGradient', { id: id('blue'), x1: '336.6', y1: '138.7', x2: '-67.2', y2: '571.4', gradientUnits: 'userSpaceOnUse' }, [
                    h('stop', { 'stop-color': '#1DA4F0' }), h('stop', { offset: '1', 'stop-color': '#105FA5' }),
                ]),
                h('mask', { id: id('mask'), 'mask-type': 'alpha', maskUnits: 'userSpaceOnUse', x: '-364', y: '0', width: '879', height: '437' }, [
                    h('rect', { x: '-364', width: '878.5', height: '437', fill: 'url(#' + id('fade') + ')' }),
                ]),
            ]),
            h('g', { opacity: '0.3', mask: 'url(#' + id('mask') + ')' }, [
                h('path', { d: 'M327.03 82.6274C51.4116 313.378 -273.882 435.162 -289.906 435.162C-230.616 439.97 -76.4625 431.317 65.8335 358.246C208.129 285.175 299.254 144.054 327.03 82.6274Z', fill: 'url(#' + id('green') + ')' }),
                h('path', { d: 'M367.09 138.713C222.231 379.718 -40.4613 462.404 -153.7 473.621C-74.2192 542.846 -9.48099 567.63 12.9531 571.369C255.241 435.483 349.998 226.312 367.09 138.713Z', fill: 'url(#' + id('blue') + ')' }),
                h('path', { d: 'M425.322 19.6575C425.322 19.6575 428.826 52.364 442.475 66.013C456.124 79.662 488.831 83.1661 488.831 83.1661C488.831 83.1661 456.124 86.6702 442.475 100.319C428.826 113.968 425.322 146.675 425.322 146.675C425.322 146.675 421.818 113.968 408.169 100.319C394.52 86.6702 361.813 83.1661 361.813 83.1661C361.813 83.1661 394.52 79.662 408.169 66.013C421.818 52.364 425.322 19.6575 425.322 19.6575Z', fill: '#D9D9D9' }),
            ]),
        ])
    }

    function renewsIn(days) {
        if (days === 0) {
            return 'Membership renews today.'
        }

        return 'Membership renews in ' + days + (days === 1 ? ' day.' : ' days.')
    }

    function renewalCard() {
        var renewal = data.renewal

        if (!renewal) {
            return null
        }

        return h('section', { class: 'ppx-renewal', 'aria-label': 'Renewal due' }, [
            h('div', { class: 'ppx-renewal__head' }, [
                h('span', { class: 'ppx-renewal__icon' }, [svg('ppx-renewal__svg', '0 0 24 24', [use('calendar')])]),
                h('strong', { class: 'ppx-renewal__title' }, 'Renewal due'),
            ]),
            h('p', { class: 'ppx-renewal__text' }, renewsIn(renewal.days)),
            renewal.paymentUrl
                ? h('a', { class: 'ppx-renewal__button', href: renewal.paymentUrl, target: '_blank', rel: 'noopener noreferrer' }, 'Renew now')
                : null,
        ])
    }

    function person() {
        var user = data.user

        if (!user) {
            return null
        }

        return h('div', { class: 'ppx-user' }, [
            h('span', { class: 'ppx-user__avatar', 'aria-hidden': 'true' }, user.initials),
            h('span', { class: 'ppx-user__text' }, [
                h('span', { class: 'ppx-user__name' }, user.name),
                user.caption ? h('span', { class: 'ppx-user__caption' }, user.caption) : null,
            ]),
        ])
    }

    var PortalMenu = {
        name: 'PortalMainMenu',
        inheritAttrs: false,

        data: function () {
            return { collapsed: readCollapsed() }
        },

        computed: {
            screen: function () {
                return this.$attrs['data-screen'] === 'responsive' ? 'responsive' : 'desktop'
            },

            menu: function () {
                return this.$store.getters.mainMenu || []
            },
        },

        mounted: function () {
            if (this.screen === 'responsive') {
                // Nova's mobile drawer: navy like the sidebar (classes only).
                var drawer = this.$el.closest && this.$el.closest('.max-w-xxs')

                if (drawer) {
                    drawer.classList.add('ppx-drawer')
                }
            }
        },

        methods: {
            toggle: function () {
                this.collapsed = !this.collapsed
                writeCollapsed(this.collapsed)
                collapsedClass(this.collapsed)
            },

            follow: function () {
                // Close Nova's mobile drawer after choosing an item, as Nova's own menu does.
                if (this.$store.getters.mainMenuShown) {
                    this.$store.commit('toggleMainMenu')
                }
            },

            item: function (item) {
                var vm = this
                var external = item.external === true
                var content = [
                    h('span', { class: 'ppx-sidebar__icon' }, [icon(item.portalIcon)]),
                    h('span', { class: 'ppx-sidebar__text' }, item.name),
                ]
                var props = {
                    class: ['ppx-sidebar__item', item.active && 'is-active'],
                    href: item.path,
                    title: vm.collapsed && vm.screen === 'desktop' ? item.name : null,
                    'aria-current': item.active ? 'page' : null,
                    onClick: function () { vm.follow() },
                }

                if (external) {
                    props.rel = 'noreferrer noopener'
                    props.target = item.target || null
                }

                return h('li', { key: item.key || item.path }, [
                    external
                        ? h('a', props, content)
                        : h(Vue.resolveComponent('Link'), props, { default: function () { return content } }),
                ])
            },

            // Sections (and groups inside them) become a small label over their items.
            blocks: function () {
                var vm = this
                var blocks = []
                var loose = []

                function flush() {
                    if (loose.length) {
                        blocks.push(h('ul', { class: 'ppx-sidebar__list' }, loose.map(vm.item)))
                        loose = []
                    }
                }

                function walk(entry) {
                    if (entry.items && entry.items.length) {
                        flush()
                        blocks.push(h('p', { class: 'ppx-sidebar__label' }, entry.name))

                        var nested = entry.items.filter(function (child) { return child.items })

                        blocks.push(h('ul', { class: 'ppx-sidebar__list' }, entry.items.filter(function (child) { return !child.items }).map(vm.item)))
                        nested.forEach(walk)
                    } else if (entry.path) {
                        loose.push(entry)
                    }
                }

                vm.menu.forEach(walk)
                flush()

                return blocks
            },
        },

        render: function () {
            var vm = this
            var desktop = vm.screen === 'desktop'

            return h('nav', {
                id: 'ppx-sidebar-' + vm.screen,
                class: ['ppx-sidebar', 'ppx-sidebar--' + vm.screen, data.renewal && 'has-renewal'],
                'aria-label': 'Main',
            }, [
                decor(vm.screen),
                h('div', { class: 'ppx-sidebar__top' }, [
                    h(Vue.resolveComponent('Link'), { class: 'ppx-sidebar__logo', href: vm.$url('/'), onClick: function () { vm.follow() } }, {
                        default: function () { return [h('img', { src: data.logo, alt: 'ProPerx Alliance', class: 'ppx-sidebar__logo-image' })] },
                    }),
                    desktop ? h('button', {
                        type: 'button',
                        class: 'ppx-sidebar__collapse',
                        'aria-label': vm.collapsed ? 'Expand sidebar' : 'Collapse sidebar',
                        'aria-expanded': vm.collapsed ? 'false' : 'true',
                        'aria-controls': 'ppx-sidebar-' + vm.screen,
                        onClick: vm.toggle,
                    }, [svg('ppx-sidebar__collapse-svg', '0 0 18 24', [use('collapse')], '1.5')]) : null,
                ]),
                h('div', { class: 'ppx-sidebar__menu' }, vm.blocks()),
                h('div', { class: 'ppx-sidebar__bottom' }, [renewalCard(), desktop ? person() : null]),
            ])
        },
    }

    var replaced = false

    function withFallback(NovaMainMenu) {
        return {
            name: 'MainMenu',
            inheritAttrs: false,

            data: function () {
                return { failed: false }
            },

            errorCaptured: function (error) {
                if (!this.failed) {
                    this.failed = true
                    shell(false)
                    console.error('ProPerx sidebar failed; showing the Nova menu.', error && error.name)
                }

                return false
            },

            render: function () {
                return h(this.failed ? NovaMainMenu : PortalMenu, this.$attrs)
            },
        }
    }

    // Nova registers its components after the booting callbacks run: swap MainMenu as it is registered.
    Nova.booting(function (app) {
        var register = app.component

        app.component = function (name, component) {
            if (arguments.length > 1 && name === 'MainMenu' && component && !replaced) {
                replaced = true
                app.component = register

                return register.call(app, name, withFallback(component))
            }

            return register.apply(app, arguments)
        }
    })

    Nova.booted(function (app) {
        if (!replaced) {
            shell(false)
        }
    })

    // Certificate and Notifications open profile pages for one block: scroll to it once Nova draws it.
    var SECTIONS = { certificate: 'membership-certificate-panel', notifications: 'notification-preferences-panel' }

    function scrollToSection() {
        var section = new URLSearchParams(window.location.search).get('section')
        var panel = SECTIONS[section]

        if (!panel) {
            return
        }

        var selector = '[dusk="' + panel + '"]'
        var started = Date.now()

        ;(function find() {
            var element = document.querySelector(selector)

            if (element) {
                element.scrollIntoView({ block: 'start' })
            } else if (Date.now() - started < 8000) {
                window.setTimeout(find, 150)
            }
        })()
    }

    document.addEventListener('inertia:navigate', scrollToSection)
    Nova.booted(scrollToSection)

    // Keep the bottom of the sidebar above Nova's fixed payment banner.
    Nova.booted(function () {
        var banner = document.getElementById('membership-banner')

        if (banner && window.ResizeObserver) {
            new ResizeObserver(function () {
                root.style.setProperty('--ppx-banner-height', banner.offsetHeight + 'px')
            }).observe(banner)
        }
    })
})()
