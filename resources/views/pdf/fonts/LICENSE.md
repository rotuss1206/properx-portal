# Fonts

The certificate's fonts, unmodified, all under the SIL Open Font License 1.1
(https://openfontlicense.org):

| File | Copyright | Source |
|---|---|---|
| `Lora-Italic.ttf` | The Lora Project Authors | https://github.com/cyrealtype/Lora-Cyrillic |
| `OpenSans-Regular.ttf` | The Open Sans Project Authors | https://github.com/googlefonts/opensans |
| `Poppins-SemiBold.ttf` | The Poppins Project Authors | https://github.com/google/fonts/tree/main/ofl/poppins |

They live here, inside the dompdf chroot (`config/dompdf.php`), so the certificate never loads
files from elsewhere. `public/portal/fonts/lora-400-italic.ttf` is the same Lora file for the
HTML preview in the member area.
