/*
 * Membership payment banner for Nova (Spec 7). Data comes from
 * Nova::provideToScript(['membershipBanner' => ...]); nothing is shown when it is null.
 * Built with DOM APIs only (no innerHTML), so it works under the strict CSP.
 */
(function () {
    var banner = window.Nova && Nova.config('membershipBanner')

    if (!banner || document.getElementById('membership-banner')) {
        return
    }

    var colors = banner.level === 'danger'
        ? { background: '#fef2f2', border: '#fecaca', text: '#991b1b', button: '#b91c1c' }
        : { background: '#fffbeb', border: '#fde68a', text: '#92400e', button: '#b45309' }

    var bar = document.createElement('div')
    bar.id = 'membership-banner'
    bar.setAttribute('role', 'alert')
    // Fixed to the bottom of the viewport: Nova's header and sidebar are positioned
    // relative to the top, so pushing content down would break the layout.
    Object.assign(bar.style, {
        position: 'fixed', left: '0', right: '0', bottom: '0', zIndex: '60',
        display: 'flex', flexWrap: 'wrap', alignItems: 'center', justifyContent: 'center', gap: '12px',
        padding: '10px 16px', fontSize: '14px', fontWeight: '600', lineHeight: '1.4',
        background: colors.background, color: colors.text, borderTop: '1px solid ' + colors.border,
        boxShadow: '0 -4px 12px rgba(15, 23, 42, 0.08)',
    })

    var message = document.createElement('span')
    message.textContent = banner.message
    bar.appendChild(message)

    if (banner.paymentUrl) {
        var link = document.createElement('a')
        link.href = banner.paymentUrl
        link.target = '_blank'
        link.rel = 'noopener noreferrer'
        link.textContent = 'Pay membership dues'
        Object.assign(link.style, {
            padding: '4px 12px', borderRadius: '6px', color: '#fff', background: colors.button, textDecoration: 'none',
        })
        bar.appendChild(link)
    }

    document.body.appendChild(bar)

    // Keep the page footer visible above the banner.
    document.body.style.paddingBottom = bar.offsetHeight + 'px'
})()
