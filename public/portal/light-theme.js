/*
 * Nova in the light theme only: the theme switcher is not part of the design (the header
 * replaces it, resources/js/nova/header.js). Loaded synchronously before Nova's own head
 * script, which reads localStorage.novaTheme (and otherwise follows the system theme).
 */
try {
    window.localStorage.setItem('novaTheme', 'light')
} catch (e) {
    // Storage blocked: Nova then follows the system theme.
}
