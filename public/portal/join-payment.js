/*
 * The agency enrollment's payment (/join/agency, screen 6; resources/views/livewire/agency-registration/steps/6.blade.php).
 * Mounts Stripe's Payment Element (card or US bank account) once the consents are given, then:
 *   "Review payment": elements.submit() and stripe.createConfirmationToken(); the wizard
 *     (Wizard::preparePayment) reads the card's funding and shows the surcharge and the total;
 *   "Pay": the wizard creates the subscription (Wizard::startPayment) and returns its client secret;
 *     stripe.confirmPayment() with the confirmation token (3D Secure in Stripe's own window);
 *     the result goes back to the wizard (finishPayment / paymentFailed), which checks it with Stripe.
 * No amounts are decided here. DOM APIs only (CSP: no inline handlers, no eval).
 */
(function () {
    var stripe = null
    var elements = null
    var mounted = null
    var busy = false

    function component(element) {
        var root = element.closest('[wire\\:id]')

        return root && window.Livewire ? window.Livewire.find(root.getAttribute('wire:id')) : null
    }

    function container() {
        return document.querySelector('[data-join-payment]')
    }

    function showError(text) {
        var element = document.querySelector('[data-join-payment-error]')

        if (element) {
            element.textContent = text || ''
            element.hidden = !text
        }
    }

    function setBusy(on, text) {
        busy = on
        document.querySelectorAll('[data-join-pay-review], [data-join-pay-confirm]').forEach(function (button) {
            button.disabled = on
        })

        var status = document.querySelector('[data-join-payment-busy]')

        if (status) {
            status.textContent = on ? (text || 'Please wait…') : ''
            status.hidden = !on
        }
    }

    function mount(element) {
        if (mounted === element || !window.Stripe) {
            return
        }

        stripe = stripe || window.Stripe(element.getAttribute('data-key'))
        elements = stripe.elements({
            mode: 'subscription',
            amount: parseInt(element.getAttribute('data-amount'), 10),
            currency: element.getAttribute('data-currency'),
            paymentMethodTypes: ['card', 'us_bank_account'],
            appearance: {
                theme: 'stripe',
                variables: { colorPrimary: '#1e3a5f', colorDanger: '#b91c1c', borderRadius: '8px', fontFamily: 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif' },
            },
        })

        var payment = elements.create('payment', {
            layout: 'tabs',
            // The name in the mandate text ("you authorize … to charge you"), whatever the Stripe account is called.
            business: { name: element.getAttribute('data-business') || 'ProPerX Alliance' },
            // Members are US agencies; Stripe would otherwise guess the country from the visitor's IP.
            defaultValues: { billingDetails: { address: { country: 'US' } } },
        })

        payment.mount(element)
        payment.on('change', function () {
            showError('')

            // Changed after "Review payment": the reviewed total may no longer be right.
            if (!busy && document.querySelector('[data-join-pay-confirm]')) {
                var wire = component(element)

                if (wire) {
                    wire.call('clearPaymentPreview')
                }
            }
        })
        mounted = element
    }

    function review(button) {
        var element = container()
        var wire = component(button)

        if (!element || !wire || !elements) {
            return
        }

        setBusy(true, 'Checking your payment details…')
        showError('')

        elements.submit().then(function (submitted) {
            if (submitted.error) {
                throw submitted.error
            }

            return stripe.createConfirmationToken({ elements: elements, params: { return_url: element.getAttribute('data-return-url') } })
        }).then(function (result) {
            if (result.error) {
                throw result.error
            }

            return wire.call('preparePayment', result.confirmationToken.id)
        }).catch(function (error) {
            showError(error && error.message ? error.message : 'The payment details could not be checked. Please try again.')
        }).finally(function () {
            setBusy(false)
        })
    }

    function pay(button) {
        var wire = component(button)

        if (!wire || !stripe) {
            return
        }

        setBusy(true, 'Processing your payment… Please keep this page open.')
        showError('')

        wire.call('startPayment').then(function (started) {
            if (!started) {
                return null
            }

            return stripe.confirmPayment({
                clientSecret: started.clientSecret,
                confirmParams: { confirmation_token: started.confirmationToken, return_url: started.returnUrl },
                redirect: 'if_required',
            }).then(function (result) {
                if (result.error) {
                    return wire.call('paymentFailed', result.error.code || result.error.type || '', result.error.decline_code || '')
                }

                return wire.call('finishPayment', result.paymentIntent.id)
            })
        }).catch(function () {
            showError('The payment could not be completed. Nothing was charged twice; please try again.')
        }).finally(function () {
            setBusy(false)
        })
    }

    document.addEventListener('click', function (event) {
        var target = event.target instanceof Element ? event.target : null

        if (!target || busy) {
            return
        }

        var reviewButton = target.closest('[data-join-pay-review]')
        var payButton = target.closest('[data-join-pay-confirm]')

        if (reviewButton) {
            event.preventDefault()
            review(reviewButton)
        } else if (payButton) {
            event.preventDefault()
            pay(payButton)
        }
    })

    // The element appears when the consents are given (Livewire updates the page).
    function check() {
        var element = container()

        if (element) {
            mount(element)
        } else {
            mounted = null
        }
    }

    new MutationObserver(check).observe(document.documentElement, { childList: true, subtree: true })
    document.addEventListener('DOMContentLoaded', check)
})()
