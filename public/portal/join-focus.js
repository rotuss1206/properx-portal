/*
 * Moves the focus where a Livewire people list asks for it ("join-focus" with an element id), once
 * Livewire has updated the page. Used by the member area's "Add people"; the enrollment has the
 * same listener in its layout. DOM APIs only (CSP).
 */
(function () {
    window.addEventListener('join-focus', function (event) {
        var id = event.detail && event.detail.id

        requestAnimationFrame(function () {
            var target = id && document.getElementById(id)

            if (target) {
                target.focus()
            }
        })
    })
})()
