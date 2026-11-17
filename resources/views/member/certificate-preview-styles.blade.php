{{--
    The live preview's look (docs/design/Certificate Generate.jpg), in Figma's pixels: the mockup shows the 659 × 615
    sheet at 0.78. public/portal/certificate.js scales the sheet to its column and draws it into the PNG at twice
    this size. The downloaded PDF has its own design: pdf.certificate-styles.
--}}
.cert * { box-sizing: content-box; margin: 0; padding: 0; }
.cert [hidden] { display: none; }
.cert { position: absolute; top: 0; left: 0; width: 659px; height: 615px; overflow: hidden; background: #fff; color: #2a3340; font-family: 'PPX Open Sans', sans-serif; font-weight: normal; font-style: normal; text-align: left; }
.cert-frame { position: absolute; top: 15px; left: 14px; width: 626px; height: 580px; border: 2.5px solid #0f2f4f; }
.cert-frame-gold { position: absolute; top: 20px; left: 19px; width: 619px; height: 573px; border: 1px solid #f0c846; background: linear-gradient(180deg, #fff 0%, #fcfbf6 100%); }
.cert-line { position: absolute; left: 0; width: 659px; text-align: center; }
.cert-logo-line { top: 71px; }
.cert-logo { width: 100px; height: 21.3px; }
.cert-title { top: 123px; color: #14508c; font-family: 'PPX Poppins', sans-serif; font-size: 12px; letter-spacing: 2.64px; line-height: 18px; }
.cert-rule { position: absolute; top: 155px; left: 303.5px; width: 52px; height: 0; border-top: 2px solid #f0c846; }
.cert-lead { top: 183px; color: #5f7185; font-size: 13px; line-height: 18px; }
.cert-name { top: 212px; color: #0a2d4b; font-family: 'PPX Lora', serif; font-style: italic; line-height: 42px; white-space: nowrap; }
.cert-text { position: absolute; top: 266px; left: 99.5px; width: 460px; color: #2a3340; font-size: 13px; line-height: 22px; text-align: center; }
.cert-fields-line { top: 339px; }
.cert .cert-fields { margin: 0 auto; border-collapse: collapse; }
.cert .cert-fields td { padding: 0 24px; text-align: center; white-space: nowrap; }
.cert-label { color: #9aa7b4; font-size: 10.5px; letter-spacing: .525px; line-height: 16px; }
.cert-value { color: #1c2b3a; font-family: 'PPX Poppins', sans-serif; font-size: 14px; line-height: 24px; }
.cert-sign { position: absolute; top: 426px; width: 182px; text-align: center; }
.cert-sign-left { left: 65px; }
.cert-sign-right { left: 410px; }
.cert-sign-name { height: 32px; color: #0a2d4b; font-family: 'PPX Lora', serif; font-size: 22px; font-style: italic; line-height: 32px; white-space: nowrap; }
.cert-sign-image { height: 32px; }
.cert .cert-sign-rule { height: 0; margin: 2px 0 8px; border-top: 1px solid #e6ddc4; }
.cert-sign-title { color: #9aa7b4; font-size: 10.5px; line-height: 14px; }
.cert-seal { position: absolute; top: 409px; left: 292.5px; box-sizing: border-box; width: 74px; height: 74px; border: 2px solid #f0c846; border-radius: 28.86px; background: #fffdf6; }
.cert-seal-content { position: absolute; top: 20.5px; left: 12px; width: 46px; height: 29px; }
.cert-hr { position: absolute; top: 513px; left: 68px; width: 523px; height: 0; border-top: 1px solid #e2e8f0; }
.cert-footer { top: 531px; color: #9aa7b4; font-size: 10.5px; line-height: 19px; }
.cert-footer-id { white-space: nowrap; }
.cert .cert-footer-icon { width: 19px; height: 19px; margin-right: 5px; vertical-align: -5px; }
{{-- With the QR at the right of the bottom line, the text centres in the space left of it. --}}
.cert--qr .cert-footer { left: 68px; width: 475px; }
.cert-qr { position: absolute; top: 520px; left: 551px; width: 40px; height: 40px; }
.cert-qr-image { display: block; width: 40px; height: 40px; }
