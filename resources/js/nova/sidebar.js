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
 * (docs/DEVELOPMENT.md). Styles: resources/css/nova/sidebar.css.
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

    // Icons from the design, 24x24 strokes in currentColor (billing is a filled shape).
    var ICONS = {
        dashboard: [
            'M4 3H9C9.55192 3 10 3.44808 10 4V11C10 11.5519 9.55192 12 9 12H4C3.44808 12 3 11.5519 3 11V4C3 3.44808 3.44808 3 4 3V3',
            'M15 3H20C20.5519 3 21 3.44808 21 4V7C21 7.55192 20.5519 8 20 8H15C14.4481 8 14 7.55192 14 7V4C14 3.44808 14.4481 3 15 3V3',
            'M15 12H20C20.5519 12 21 12.4481 21 13V20C21 20.5519 20.5519 21 20 21H15C14.4481 21 14 20.5519 14 20V13C14 12.4481 14.4481 12 15 12V12',
            'M4 16H9C9.55192 16 10 16.4481 10 17V20C10 20.5519 9.55192 21 9 21H4C3.44808 21 3 20.5519 3 20V17C3 16.4481 3.44808 16 4 16V16',
        ],
        profile: [
            'M7 8C7 10.7596 9.24042 13 12 13C14.7596 13 17 10.7596 17 8C17 5.24042 14.7596 3 12 3C9.24042 3 7 5.24042 7 8V8',
            'M20 21C20 16.5847 16.4153 13 12 13C7.58468 13 4 16.5847 4 21',
        ],
        license: [
            'M6 22C4.89617 22 4 21.1038 4 20V4C4 2.89617 4.89617 2 6 2H14C14.6394 1.99897 15.2527 2.25309 15.704 2.706L19.292 6.294C19.7461 6.74545 20.001 7.35966 20 8V20C20 21.1038 19.1038 22 18 22H6',
            'M14 2V7C14 7.55192 14.4481 8 15 8H20',
            'M10 9H8',
            'M16 13H8',
            'M16 17H8',
        ],
        tax: [
            'M12 17V7',
            'M16 8H10C8.89617 8 8 8.89617 8 10C8 11.1038 8.89617 12 10 12H14C15.1038 12 16 12.8962 16 14C16 15.1038 15.1038 16 14 16H8',
            'M4 3.00001C4 2.44809 4.44808 2.00001 5 2.00001C5.24762 1.99865 5.49048 2.06803 5.7 2.20001L6.633 2.80001C7.05982 3.07275 7.60618 3.07275 8.033 2.80001L8.967 2.20001C9.39382 1.92727 9.94018 1.92727 10.367 2.20001L11.3 2.80001C11.7268 3.07275 12.2732 3.07275 12.7 2.80001L13.633 2.20001C14.0598 1.92727 14.6062 1.92727 15.033 2.20001L15.967 2.80001C16.3938 3.07275 16.9402 3.07275 17.367 2.80001L18.3 2.20001C18.5095 2.06803 18.7524 1.99865 19 2.00001C19.5519 2.00001 20 2.44809 20 3.00001V21C20 21.5519 19.5519 22 19 22C18.7524 22.0014 18.5095 21.932 18.3 21.8L17.367 21.2C16.9402 20.9273 16.3938 20.9273 15.967 21.2L15.033 21.8C14.6062 22.0727 14.0598 22.0727 13.633 21.8L12.7 21.2C12.2732 20.9273 11.7268 20.9273 11.3 21.2L10.367 21.8C9.94018 22.0727 9.39382 22.0727 8.967 21.8L8.033 21.2C7.60618 20.9273 7.05982 20.9273 6.633 21.2L5.7 21.8C5.49048 21.932 5.24762 22.0014 5 22C4.44808 22 4 21.5519 4 21L4 3.00001',
        ],
        certificate: [
            'M15.477 12.89L16.992 21.416C17.0268 21.6216 16.9303 21.8272 16.7499 21.9318C16.5696 22.0365 16.3433 22.0182 16.182 21.886L12.602 19.199C12.2471 18.9338 11.76 18.9338 11.405 19.199L7.81902 21.885C7.65791 22.017 7.43189 22.0352 7.25168 21.9309C7.07147 21.8265 6.9748 21.6214 7.00902 21.416L8.52302 12.89',
            'M6 8C6 11.3115 8.68851 14 12 14C15.3115 14 18 11.3115 18 8C18 4.68851 15.3115 2 12 2C8.68851 2 6 4.68851 6 8H6',
        ],
        library: ['M16 6L20 20', 'M12 6V20', 'M8 8V20', 'M4 4V20'],
        notifications: [
            'M10.2679 21C10.6252 21.6188 11.2854 21.9999 11.9999 21.9999C12.7144 21.9999 13.3747 21.6188 13.7319 21',
            'M3.26202 15.326C2.99511 15.6185 2.92598 16.041 3.08573 16.4034C3.24548 16.7658 3.60401 16.9997 4.00002 17H20C20.396 17.0001 20.7547 16.7666 20.9149 16.4045C21.0751 16.0424 21.0065 15.6199 20.74 15.327C19.41 13.956 18 12.499 18 8C18 4.68851 15.3115 2 12 2C8.68853 2 6.00002 4.68851 6.00002 8C6.00002 12.499 4.58902 13.956 3.26202 15.326',
        ],
        calendar: [
            'M16 14V16.2L17.6 17.2',
            'M16 2V6',
            'M21 7.5V6C21 4.89617 20.1038 4 19 4H5C3.89617 4 3 4.89617 3 6V20C3 21.1038 3.89617 22 5 22H8.5',
            'M3 10H8',
            'M8 2V6',
            'M10 16C10 19.3115 12.6885 22 16 22C19.3115 22 22 19.3115 22 16C22 12.6885 19.3115 10 16 10C12.6885 10 10 12.6885 10 16V16',
        ],
    }

    var BILLING = 'M22 6V18C22 18.55 21.8042 19.0208 21.4125 19.4125C21.0208 19.8042 20.55 20 20 20H4C3.45 20 2.97917 19.8042 2.5875 19.4125C2.19583 19.0208 2 18.55 2 18V6C2 5.45 2.19583 4.97917 2.5875 4.5875C2.97917 4.19583 3.45 4 4 4H20C20.55 4 21.0208 4.19583 21.4125 4.5875C21.8042 4.97917 22 5.45 22 6ZM4 8H20V6H4V8ZM4 12V18H20V12H4Z'

    var COLLAPSE = ['M3.75 5.25H14.25C15.0779 5.25 15.75 5.92213 15.75 6.75V17.25C15.75 18.0779 15.0779 18.75 14.25 18.75H3.75C2.92213 18.75 2.25 18.0779 2.25 17.25V6.75C2.25 5.92213 2.92213 5.25 3.75 5.25V5.25', 'M6.75 5.25V18.75', 'M12 14.25L9.75 12L12 9.75']

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
        if (name === 'billing') {
            return svg('ppx-sidebar__svg', '0 0 24 24', [h('path', { d: BILLING, fill: 'currentColor', stroke: 'none' })])
        }

        return svg('ppx-sidebar__svg', '0 0 24 24', (ICONS[name] || ICONS.dashboard).map(function (d) { return h('path', { d: d }) }))
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
                h('span', { class: 'ppx-renewal__icon' }, [svg('ppx-renewal__svg', '0 0 24 24', ICONS.calendar.map(function (d) { return h('path', { d: d }) }))]),
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
                    }, [svg('ppx-sidebar__collapse-svg', '0 0 18 24', COLLAPSE.map(function (d) { return h('path', { d: d }) }), '1.5')]) : null,
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
