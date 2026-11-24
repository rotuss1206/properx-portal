# Member emails on properx.org

## Approval email: DRAFT, waiting for the client's approval

Sent by the ProPerX Portal plugin when a Pending Member becomes an Active Member. It replaces two emails sent today: the KEENEY plugin's "Welcome to ProPerx Alliance — Set Your Password" and the theme's "Your Membership Application Has Been Approved!".

It goes out only while Forms > Settings > ProPerX Portal > **Approval email** is on. That switch stays off until the client approves this text. While it is off, the two old emails go out as before.

**From:** ProPerX Alliance

**Subject:** Welcome to ProPerX Alliance — Your Membership Is Approved

**Body** (HTML with the ProPerX Alliance logo, and the same words as plain text):

> Hi {first name},
>
> Your ProPerX Alliance membership application has been reviewed and approved. Here is what to do next.
>
> **1. Set your password and sign in**
> Once you are signed in, the 2027 plans page shows the "How to enroll" section.
> [Set your password] (a personal link)
> If this link has expired, use "Lost your password?" on the sign-in page with the email address you applied with.
>
> **2. See the 2027 health plans**
> [2027 Health Plan Options](https://properx.org/2027-health-plan-options/)
>
> **3. Choose a plan and enroll**
> Compare the five plans and open the full summary for any you're considering. Find your monthly rate by your age and who you're covering. Then complete the enrollment form; have your plan choice and the details for anyone you're covering ready.
> [Open the 2027 enrollment form](https://my-benefits.net/2027-group-health-coverage-enrollment-form/)
> Enrollment is subject to final plan approval and ProPerX Alliance membership requirements.
>
> **4. Questions?**
> Call us at +1 (888) 388-1720.

### Where the words come from

| Part | Source |
|---|---|
| Item 3 and the small print | The members' block "How to enroll" on the plans page (page 919), lightly joined into one paragraph |
| Enrollment form link | The same block |
| Phone | The same block's "Need help?" line |
| Greeting | The member's first name from the application; "Hi there," if there is none |
| "Lost your password?" sentence | The KEENEY plugin's current email |

### The name of the section

Signed-in Active Members see "How to enroll" on the plans page, after the page's headings were changed ([changes.md](changes.md), item 7). Change the page first, then install this version of the plugin: until then Active Members still see the old heading, "Ready to enroll?".

## Other emails

| Email | When | Change |
|---|---|---|
| User Pending Confirmation ("Your Membership Application is Under Review") | Was on form submission, before payment was confirmed; then on the Subscription Created event (`changes.md`, item 3) | **Inactive** with plugin 2.3.0 ("Apply /apply form changes"): the agent welcome replaces it. Text unchanged |
| New Membership Request Staff Notification | On the Subscription Created event (plugin 2.3.0): paid applications only; ACH once the bank has cleared it | Text unchanged |
| Your ProPerX Alliance member portal account | Sent by the portal, not WordPress, only with `INTAKE_SEND_INVITE=true` | Keep off until the portal is announced to members; otherwise a new member gets it right after paying, before approval |
| Agent welcome ("Welcome to ProPerX Alliance! Your Next Steps") | Once per "Just me" application, to the address on it, right after the payment: when Stripe says the first payment succeeded (card) or is processing (ACH), else when the subscription starts; never for a declined card (plugin 2.3.0, event "ProPerX: application paid or bank payment started", added by "Apply /apply form changes") | The client's text from the go-live requirements. A Gravity Forms notification of the form, so the client edits it in Forms > Membership Application Form > Settings > Notifications > Agent welcome. Sender: the same as "User Pending Confirmation". The two links come from Forms > Settings > ProPerX Portal > "Welcome email links" (merge tags `{properx_health_enrollment_url}`, `{properx_provider_search_url}`); a section whose link is empty is left out. |

### Personal data in the emails (to confirm with the client)

| Email | Sent by | Personal data |
|---|---|---|
| Agent welcome | properx.org | First name in the greeting ("Hi [First Name],"), the client's template |
| Agency owner welcome | the portal | First name in the greeting, the client's template |
| User Pending Confirmation, New Membership Request Staff Notification | properx.org | Unchanged by this work (the staff email lists the application's fields) |
| Receipt and account setup, invitations, reminders | the portal | None: no names, NPN, license or agency data (amounts and dates on the receipt only) |
| License document rejected (`LicenseDocumentDecision`) | the portal | Staff's reason for the rejection, as before |

The license copy uploaded on the application is never attached to or linked from any email: the plugin takes
it out of the entry before the emails go.
