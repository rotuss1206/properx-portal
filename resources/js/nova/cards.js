/*
 * Dashboard cards of the portal (Nova). "ppx-link-metric": a number with a link, in the look of
 * Nova's value metrics (App\Nova\Cards\PendingVerification). Render functions only (no
 * unsafe-eval); the link is an Inertia Link, so Nova opens it without a full page load.
 */
(function () {
    var Vue = window.Vue

    if (!Vue || !window.Nova || typeof Nova.booting !== 'function') {
        return
    }

    var h = Vue.h

    Nova.booting(function (app) {
        app.component('ppx-link-metric', {
            name: 'PortalLinkMetric',
            props: ['card'],

            render: function () {
                var card = this.card || {}
                var Link = Vue.resolveComponent('Link')

                return h('div', { class: 'ppx-link-metric bg-white dark:bg-gray-800 rounded-lg shadow h-full px-6 py-4' }, [
                    h('h3', { class: 'ppx-link-metric__title text-sm font-bold' }, card.title),
                    h('p', { class: 'ppx-link-metric__value' }, [
                        h('span', { class: 'text-4xl' }, String(card.value)),
                        card.suffix ? h('span', { class: 'ppx-link-metric__suffix ml-2 text-sm font-bold text-gray-400' }, card.suffix) : null,
                    ]),
                    card.url ? h(Link, { class: 'ppx-link-metric__link link-default text-sm font-bold', href: card.url }, function () { return card.linkText }) : null,
                ])
            },
        })
    })
})()
