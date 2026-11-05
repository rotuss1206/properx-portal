{{--
    The certificate's look (docs/design/Certificate.jpg), shared by the PDF (dompdf) and the HTML preview
    (member area), so both show the same page: US Letter portrait, 612 × 792 pt, everything placed in
    points. The font families are declared by each side: from the dompdf chroot, or from public/portal/fonts.
    dompdf places text differently: pdf/certificate.blade.php corrects that, and only that.
--}}
.cert * { box-sizing: content-box; margin: 0; padding: 0; }
.cert { position: absolute; top: 0; left: 0; width: 612pt; height: 791pt; overflow: hidden; background: #fff; color: #2f3a4a; font-family: 'PPX Open Sans', sans-serif; font-weight: normal; font-style: normal; text-align: left; }
.cert-frame { position: absolute; top: 25.5pt; left: 25.5pt; width: 556.5pt; height: 735.5pt; border: 2.25pt solid #0f2f4f; }
.cert-frame-gold { position: absolute; top: 33pt; left: 33pt; width: 544.5pt; height: 723.5pt; border: 0.75pt solid #c9a23a; }
.cert-corner { position: absolute; top: 39pt; width: 23.25pt; height: 23.25pt; border-top: 2.25pt solid #c9a23a; }
.cert-corner-left { left: 39pt; border-left: 2.25pt solid #c9a23a; }
.cert-corner-right { left: 547.5pt; border-right: 2.25pt solid #c9a23a; }
.cert-line { position: absolute; left: 0; width: 612pt; text-align: center; }
.cert-logo-line { top: 82pt; }
.cert-logo { width: 132pt; height: 28pt; }
.cert-title { top: 144pt; color: #164f8f; font-family: 'PPX Poppins', sans-serif; font-size: 11.25pt; letter-spacing: 2.6pt; line-height: 15pt; }
.cert-rule { position: absolute; top: 178pt; width: 45pt; height: 0; border-top: 0.75pt solid #c9d2dc; }
.cert-rule-left { left: 247.5pt; }
.cert-rule-right { left: 319.5pt; }
.cert-diamond-line { top: 174pt; }
.cert .cert-diamond { display: block; width: 8pt; height: 8pt; margin: 0 auto; }
.cert-lead { top: 212pt; color: #5e6b7c; font-size: 12pt; line-height: 16pt; }
.cert-name { top: 236pt; color: #0f2f4f; font-family: 'PPX Lora', serif; font-style: italic; line-height: 60pt; white-space: nowrap; }
.cert-name-rule { position: absolute; top: 300pt; left: 178.5pt; width: 255pt; height: 0; border-top: 0.75pt solid #e2e8f0; }
.cert-text { position: absolute; top: 318pt; left: 96pt; width: 420pt; color: #2f3a4a; font-size: 12pt; line-height: 22.5pt; text-align: center; }
.cert-strong { color: #0f2f4f; }
.cert-fields-line { top: 413pt; }
.cert .cert-fields { margin: 0 auto; border-collapse: collapse; }
.cert .cert-fields td { padding: 0 24pt; text-align: center; white-space: nowrap; }
.cert-label { color: #98a3b0; font-size: 8pt; letter-spacing: 0.5pt; line-height: 12pt; }
.cert-value { color: #0f2f4f; font-family: 'PPX Poppins', sans-serif; font-size: 12.5pt; line-height: 20pt; }
.cert-sign { position: absolute; top: 620pt; width: 170pt; text-align: center; }
.cert-sign-left { left: 82pt; }
.cert-sign-right { left: 360pt; }
.cert-sign-name { height: 30pt; color: #0f2f4f; font-family: 'PPX Lora', serif; font-size: 21pt; font-style: italic; line-height: 30pt; white-space: nowrap; }
.cert-sign-image { height: 30pt; }
.cert .cert-sign-rule { height: 0; margin: 2pt 18pt 4pt; border-top: 0.75pt solid #c9d2dc; }
.cert-sign-title { color: #7a8696; font-size: 8pt; line-height: 11pt; }
.cert-seal { position: absolute; top: 591pt; left: 267pt; width: 73.5pt; height: 73.5pt; border: 2.25pt solid #c9a23a; border-radius: 39pt; background: #fbf6e6; text-align: center; }
.cert-seal-icon { position: absolute; top: 19pt; left: 27.75pt; width: 18pt; height: 18pt; }
.cert-seal-text { position: absolute; top: 42pt; left: 0; width: 73.5pt; color: #c9a23a; font-family: 'PPX Poppins', sans-serif; font-size: 5.25pt; letter-spacing: 0.6pt; line-height: 8pt; text-align: center; }
.cert-qr { position: absolute; top: 592pt; left: 407.5pt; width: 75pt; text-align: center; }
.cert-qr-centered { top: 490pt; left: 268.5pt; }
.cert-qr-image { width: 62pt; height: 62pt; }
.cert-qr-text { color: #7a8696; font-size: 7.5pt; line-height: 10pt; }
.cert-footer { top: 705pt; color: #98a3b0; font-size: 8.75pt; line-height: 13pt; }
.cert .cert-footer-icon { width: 10pt; height: 10pt; margin-right: 4pt; vertical-align: -1.5pt; }
