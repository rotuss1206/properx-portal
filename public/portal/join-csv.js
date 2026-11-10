/*
 * The People screen's "Upload CSV" (/join/agency, resources/views/livewire/agency-registration/steps/5.blade.php):
 * reads the chosen file in the browser, checks its name, size and UTF-8 text, and hands the text
 * to the wizard (Wizard::importPeople), which parses it in memory. Nothing is uploaded as a file
 * and nothing is stored. DOM APIs only (CSP: no inline handlers, no eval).
 */
(function () {
    var MAX_BYTES = 1024 * 1024

    function say(input, text) {
        var status = document.getElementById(input.getAttribute('data-status'))

        if (status) {
            status.textContent = text
            status.hidden = !text
        }
    }

    document.addEventListener('change', function (event) {
        var input = event.target

        if (!(input instanceof HTMLInputElement) || !input.hasAttribute('data-join-csv')) {
            return
        }

        var file = input.files && input.files[0]
        var root = input.closest('[wire\\:id]')

        input.value = ''

        if (!file || !root || !window.Livewire) {
            return
        }

        if (!/\.csv$/i.test(file.name)) {
            say(input, 'Choose a .csv file. In Excel or Google Sheets, save or download the sheet as CSV.')

            return
        }

        if (file.size > MAX_BYTES) {
            say(input, 'The file is larger than 1 MB. Split it into smaller files and upload them one after another.')

            return
        }

        say(input, 'Reading ' + file.name + '…')

        file.arrayBuffer().then(function (buffer) {
            var text

            try {
                // A byte order mark is dropped here; the server accepts one as well.
                text = new TextDecoder('utf-8', { fatal: true }).decode(buffer)
            } catch (error) {
                say(input, 'The file is not in UTF-8. In Excel choose File > Save As > "CSV UTF-8", then upload it again.')

                return
            }

            say(input, '')
            window.Livewire.find(root.getAttribute('wire:id')).call('importPeople', file.name, text)
        }, function () {
            say(input, 'The file could not be read. Please try again.')
        })
    })
})()
