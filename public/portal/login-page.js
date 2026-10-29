/*
 * ProPerx sign-in page. Replaces only the markup and layout of Nova's login page
 * (Nova 5 pages/Login.vue): the form state, attempt() (POST /nova/login, the 2FA
 * redirect), browser validation and server errors stay Nova's own. Render functions
 * only: the CSP has no unsafe-eval, so Vue templates cannot be compiled in the browser.
 * Styles: public/portal/login-page.css (classes only, no inline styles).
 *
 * Loaded with "defer" from resources/views/vendor/nova/partials/meta.blade.php (Nova
 * gives guests no tool scripts): it runs after window.Nova exists and before Nova
 * mounts on DOMContentLoaded, so patching the page object takes effect. If a Nova
 * upgrade changes the page so this no longer fits, the stock login page stays.
 */
(function () {
    var Vue = window.Vue
    var page = window.Nova && Nova.pages && Nova.pages['Nova.Login']

    if (!Vue || !page || page.__properx || typeof page.data !== 'function'
        || !page.methods || typeof page.methods.attempt !== 'function') {
        return
    }

    var h = Vue.h
    var settings = Nova.config('loginPage') || {}

    // Placeholder notices: at most one on screen. Repeated clicks keep the visible one;
    // a different notice replaces it.
    var current = null

    function notice(message) {
        if (current && current.toast && current.toast.el && current.toast.el.isConnected) {
            if (current.message === message) {
                return
            }

            current.toast.goAway(0)
        }

        current = { message: message, toast: Nova.$toasted.show(message, { type: 'info' }) }
    }

    function icon(className, paths, extra) {
        return h('svg', {
            class: className,
            viewBox: '0 0 24 24',
            fill: 'none',
            stroke: 'currentColor',
            'stroke-width': '1.8',
            'stroke-linecap': 'round',
            'stroke-linejoin': 'round',
            'aria-hidden': 'true',
            focusable: 'false',
        }, paths.map(function (d) { return h('path', { d: d }) }).concat(extra || []))
    }

    var ICONS = {
        shield: ['M12 3l7 3v5c0 4.5-3 8.4-7 10-4-1.6-7-5.5-7-10V6l7-3z', 'M9 12l2 2 4-4'],
        award: ['M8.5 13.2L7.5 21l4.5-2.4 4.5 2.4-1-7.8', 'M12 14a5 5 0 1 0 0-10 5 5 0 0 0 0 10z'],
        library: ['M5 4v16', 'M9 4v16', 'M13 4v16', 'M16.5 4.6l3.6 14.8'],
        device: ['M8 2.5h8a1.5 1.5 0 0 1 1.5 1.5v16a1.5 1.5 0 0 1-1.5 1.5H8A1.5 1.5 0 0 1 6.5 20V4A1.5 1.5 0 0 1 8 2.5z', 'M12 18h.01'],
        eye: ['M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z', 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z'],
        eyeOff: [
            'M3 3l18 18',
            'M10.6 10.6a2 2 0 0 0 2.8 2.8',
            'M9.9 5.2A9.7 9.7 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-2.9 3.7',
            'M6.6 6.6C3.8 8.4 2 12 2 12s3.6 7 10 7a9.6 9.6 0 0 0 4.8-1.3',
        ],
    }

    var FEATURES = [
        ['shield', 'License tracking & expiry reminders'],
        ['award', 'Instant membership certificate generation'],
        ['library', 'Full compliance & training library access'],
    ]

    // Decorative artwork (star and swooshes) from the design, next to this script.
    var artworkUrl = new URL('login-art.svg', document.currentScript ? document.currentScript.src : window.location.origin + '/portal/').href

    function artwork() {
        return h('img', { class: 'ppx-login__art', src: artworkUrl, alt: '', 'aria-hidden': 'true' })
    }

    function logo() {
        var svg = Nova.config('logo')

        // Same source as Nova's own header logo (config nova.brand.logo, a trusted file).
        return svg
            ? h('span', { class: 'ppx-login__logo', innerHTML: svg })
            : h('span', { class: 'ppx-login__logo ppx-login__logo--text' }, Nova.config('appName'))
    }

    // The brand panel comes in two parts so phones can show the features below the form:
    // on desktop both form one navy column left of the form (grid, see the stylesheet).
    function panelTop() {
        return h('section', { class: 'ppx-login__panel ppx-login__panel--top', 'aria-label': 'About the member portal' }, [
            logo(),
            h('div', { class: 'ppx-login__intro' }, [
                h('p', { class: 'ppx-login__tagline' }, 'Your professional membership hub.'),
                h('p', { class: 'ppx-login__lead' },
                    'Manage your licenses, certificates, tax documents, and compliance resources — all in one place.'),
            ]),
        ])
    }

    function panelBottom() {
        return h('div', { class: 'ppx-login__panel ppx-login__panel--bottom' }, [
            artwork(),
            h('ul', { class: 'ppx-login__features' }, FEATURES.map(function (feature) {
                return h('li', { class: 'ppx-login__feature' }, [
                    h('span', { class: 'ppx-login__feature-icon' }, [icon('ppx-login__icon', ICONS[feature[0]])]),
                    h('span', feature[1]),
                ])
            })),
            h('span', { class: 'ppx-login__badge' }, 'Member portal'),
        ])
    }

    // Replaces Nova's Auth layout; keeps its error and expired-session handlers.
    var Layout = {
        name: 'ProperxLoginLayout',

        setup: function (props, context) {
            var onError = function (message) {
                Nova.error(message)
            }

            var onTokenExpired = function () {
                Nova.$toasted.show('Sorry, your session has expired.', {
                    action: { onClick: function () { Nova.redirectToLogin() }, text: 'Reload' },
                    duration: null,
                    type: 'error',
                })

                setTimeout(function () { Nova.redirectToLogin() }, 5000)
            }

            Vue.onMounted(function () {
                Nova.$on('error', onError)
                Nova.$on('token-expired', onTokenExpired)
            })

            Vue.onBeforeUnmount(function () {
                Nova.$off('error', onError)
                Nova.$off('token-expired', onTokenExpired)
            })

            return function () {
                return h('div', { class: 'ppx-login' }, [
                    panelTop(),
                    h('main', { class: 'ppx-login__main' }, context.slots.default ? context.slots.default() : []),
                    panelBottom(),
                ])
            }
        },
    }

    function errorFor(vm, name) {
        return vm.form.errors.has(name) ? vm.form.errors.first(name) : null
    }

    function errorText(id, message) {
        return message ? h('p', { id: id, class: 'ppx-login__error' }, message) : null
    }

    function forgotPasswordLink(vm) {
        if (!vm.supportsPasswordReset && vm.forgotPasswordPath === false) {
            return null
        }

        var text = vm.__('Forgot password?')

        return vm.forgotPasswordPath === false
            ? h(Vue.resolveComponent('Link'), { href: vm.$url('/password/reset'), class: 'ppx-login__link' }, { default: function () { return text } })
            : h('a', { href: vm.forgotPasswordPath, class: 'ppx-login__link' }, text)
    }

    // Placeholder until PORTAL_SUPPORT_URL is set: the link only shows a notice.
    function helpLine() {
        return h('p', { class: 'ppx-login__help' }, [
            'Need help? ',
            h('a', {
                href: settings.supportUrl || '#',
                class: 'ppx-login__link ppx-login__link--plain',
                onClick: function (event) {
                    if (!settings.supportUrl) {
                        event.preventDefault()
                        notice('Please contact the ProPerx Alliance membership team.')
                    }
                },
            }, 'Contact support'),
        ])
    }

    // Placeholder: SSO with external identity providers is out of scope for now.
    function ssoButton(vm) {
        return [
            h('div', { class: 'ppx-login__divider' }, [h('span', vm.__('or'))]),
            h('button', {
                type: 'button',
                class: 'ppx-login__sso',
                onClick: function () { notice('Single sign-on is not available yet. Please sign in with your email and password.') },
            }, [icon('ppx-login__icon', ICONS.device), h('span', vm.__('Sign in with SSO'))]),
        ]
    }

    // Bound to Nova's own "remember" field; the portal ignores it (DisableRememberMe:
    // sessions are owned by the platform), so it is visual for now.
    function rememberCheckbox(vm) {
        return h('label', { class: 'ppx-login__remember' }, [
            h('input', {
                type: 'checkbox',
                class: 'ppx-login__checkbox',
                name: 'remember',
                checked: vm.form.remember,
                onChange: function (event) { vm.form.remember = event.target.checked },
            }),
            h('span', vm.__('Keep me signed in')),
        ])
    }

    function render() {
        var vm = this
        var field = vm.username
        var usernameError = errorFor(vm, field)
        var passwordError = errorFor(vm, 'password')

        return h('div', { class: 'ppx-login__card' }, [
            h(Vue.resolveComponent('Head'), { title: vm.__('Sign in') }),

            h('h1', { class: 'ppx-login__title' }, vm.__('Sign in')),
            h('p', { class: 'ppx-login__subtitle' }, vm.__('Enter your credentials to access your account.')),

            h('form', {
                class: 'ppx-login__form',
                onSubmit: function (event) {
                    event.preventDefault()
                    vm.attempt()
                },
            }, [
                h('div', { class: 'ppx-login__field' }, [
                    h('label', { class: 'ppx-login__label', for: 'username' },
                        vm.__(vm.username === vm.email ? 'Email address' : 'Username')),
                    h('input', {
                        id: 'username',
                        class: ['ppx-login__input', usernameError && 'is-invalid'],
                        type: vm.usernameInputType,
                        name: field,
                        value: vm.form[field],
                        onInput: function (event) { vm.form[field] = event.target.value },
                        autofocus: '',
                        autocomplete: 'username',
                        required: true,
                        'aria-invalid': usernameError ? 'true' : null,
                        'aria-describedby': usernameError ? 'username-error' : null,
                    }),
                    errorText('username-error', usernameError),
                ]),

                h('div', { class: 'ppx-login__field' }, [
                    h('div', { class: 'ppx-login__label-row' }, [
                        h('label', { class: 'ppx-login__label', for: 'password' }, vm.__('Password')),
                        forgotPasswordLink(vm),
                    ]),
                    h('div', { class: 'ppx-login__password' }, [
                        h('input', {
                            id: 'password',
                            class: ['ppx-login__input', passwordError && 'is-invalid'],
                            type: vm.showPassword ? 'text' : 'password',
                            name: 'password',
                            value: vm.form.password,
                            onInput: function (event) { vm.form.password = event.target.value },
                            autocomplete: 'current-password',
                            required: true,
                            'aria-invalid': passwordError ? 'true' : null,
                            'aria-describedby': passwordError ? 'password-error' : null,
                        }),
                        h('button', {
                            type: 'button',
                            class: 'ppx-login__reveal',
                            'aria-controls': 'password',
                            'aria-pressed': vm.showPassword ? 'true' : 'false',
                            'aria-label': vm.__('Show password'),
                            onClick: function () { vm.showPassword = !vm.showPassword },
                        }, [icon('ppx-login__icon', vm.showPassword ? ICONS.eyeOff : ICONS.eye)]),
                    ]),
                    errorText('password-error', passwordError),
                ]),

                rememberCheckbox(vm),

                h('button', {
                    type: 'submit',
                    class: 'ppx-login__submit',
                    disabled: vm.form.processing,
                    'aria-busy': vm.form.processing ? 'true' : null,
                }, vm.form.processing ? vm.__('Signing in…') : vm.__('Sign in')),
            ]),

            ssoButton(vm),

            helpLine(),
        ])
    }

    var novaData = page.data

    page.data = function (instance) {
        return Object.assign(novaData.call(this, instance), { showPassword: false })
    }

    page.render = render
    page.layout = Layout
    page.__properx = true
})()
