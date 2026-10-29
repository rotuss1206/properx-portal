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
        shield: [
            'M20 13C20 18 16.5 20.5 12.34 21.95C12.1222 22.0238 11.8855 22.0203 11.67 21.94C7.5 20.5 4 18 4 13V6.00002C4 5.44811 4.44808 5.00002 5 5.00002C7 5.00002 9.5 3.80002 11.24 2.28002C11.6777 1.90609 12.3223 1.90609 12.76 2.28002C14.51 3.81002 17 5.00002 19 5.00002C19.5523 5.00002 20 5.44774 20 6.00002V13',
            'M9 12L11 14L15 10',
        ],
        award: [
            'M15.477 12.89L16.992 21.416C17.0268 21.6216 16.9303 21.8272 16.7499 21.9318C16.5696 22.0365 16.3433 22.0182 16.182 21.886L12.602 19.199C12.2471 18.9338 11.76 18.9338 11.405 19.199L7.81902 21.885C7.65791 22.017 7.43189 22.0352 7.25168 21.9309C7.07147 21.8265 6.9748 21.6214 7.00902 21.416L8.52302 12.89',
            'M6 8C6 11.3115 8.68851 14 12 14C15.3115 14 18 11.3115 18 8C18 4.68851 15.3115 2 12 2C8.68851 2 6 4.68851 6 8H6',
        ],
        library: ['M16 6L20 20', 'M12 6V20', 'M8 8V20', 'M4 4V20'],
        device: [
            'M7 2H17C18.1038 2 19 2.89617 19 4V20C19 21.1038 18.1038 22 17 22H7C5.89617 22 5 21.1038 5 20V4C5 2.89617 5.89617 2 7 2V2',
            'M12 18H12.01',
        ],
    }

    // Password reveal icon from the design: a crossed-out eye with gaps in its lines.
    // When the password is shown the slash draws out and the gaps draw in (CSS
    // stroke-dashoffset on pathLength 1), so the same lines close into an open eye.
    var REVEAL = {
        lines: [
            'M10.733 5.076C15.5201 4.50551 20.1012 7.19362 21.938 11.651C22.0213 11.8755 22.0213 12.1225 21.938 12.347C21.5705 13.238 21.0848 14.0755 20.494 14.837',
            'M14.084 14.158C12.9069 15.2949 11.0358 15.2787 9.87856 14.1215C8.72136 12.9643 8.7051 11.0932 9.84201 9.91602',
            'M17.479 17.499C14.7949 19.0889 11.5525 19.4345 8.59362 18.4459C5.63474 17.4573 3.25125 15.2321 2.06199 12.348C1.97865 12.1235 1.97865 11.8765 2.06199 11.652C2.94862 9.50186 4.50866 7.69725 6.50799 6.509',
        ],
        gaps: [
            'M6.508 6.509A10.75 10.75 0 0 1 10.733 5.076',
            'M20.494 14.837A10.75 10.75 0 0 1 17.479 17.499',
            'M9.842 9.916A3 3 0 1 1 14.084 14.158',
        ],
        slash: 'M2 2L22 22',
    }

    function revealIcon(visible) {
        return h('svg', {
            class: ['ppx-login__icon', 'ppx-login__eye', visible && 'is-open'],
            viewBox: '0 0 24 24',
            fill: 'none',
            stroke: 'currentColor',
            'stroke-width': '2',
            'stroke-linecap': 'round',
            'stroke-linejoin': 'round',
            'aria-hidden': 'true',
            focusable: 'false',
        }, REVEAL.lines.map(function (d) { return h('path', { d: d }) })
            .concat(REVEAL.gaps.map(function (d) { return h('path', { d: d, class: 'ppx-login__eye-gap', pathLength: '1' }) }))
            .concat([h('path', { d: REVEAL.slash, class: 'ppx-login__eye-slash', pathLength: '1' })]))
    }

    var FEATURES = [
        ['shield', 'License tracking & expiry reminders'],
        ['award', 'Instant membership certificate generation'],
        ['library', 'Full compliance & training library access'],
    ]

    // Images in public/portal/img, next to this script.
    var assetBase = document.currentScript ? document.currentScript.src : window.location.origin + '/portal/'

    function assetUrl(name) {
        return new URL(name, assetBase).href
    }

    // Decorative artwork (star and swooshes) from the design.
    var artworkUrl = assetUrl('img/login-art.svg')

    function artwork() {
        return h('img', { class: 'ppx-login__art', src: artworkUrl, alt: '', 'aria-hidden': 'true' })
    }

    // Full-colour logo for the sign-in page only (Nova's header keeps config nova.brand.logo).
    function logo() {
        return h('span', { class: 'ppx-login__logo' }, [
            h('img', { class: 'ppx-login__logo-image', src: assetUrl('img/properx-alliance-logo.png'), alt: 'ProPerx Alliance' }),
        ])
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
                        }, [revealIcon(vm.showPassword)]),
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
