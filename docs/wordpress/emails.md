# Member emails on properx.org

## Approval email: DRAFT, waiting for the client's approval

Sent by the ProPerX Portal plugin when a Pending Member becomes an Active Member. It replaces two emails sent today: the KEENEY plugin's "Welcome to ProPerx Alliance — Set Your Password" and the theme's "Your Membership Application Has Been Approved!".

It goes out only while Forms > Settings > ProPerX Portal > **Approval email** is on. That switch stays off until the client approves this text. While it is off, the two old emails go out as before.

**From:** ProPerX Alliance

**Subject:** Welcome to ProPerX Alliance — Your Membership Is Approved

**Body** (HTML with the ProPerX Alliance logo, and the same words as plain text):

> Your ProPerX Alliance membership application has been reviewed and approved. Here is what to do next.
>
> **1. Set your password and sign in**
> Once you are signed in, the 2027 plans page shows the "Ready to enroll?" section.
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
> Call us at (440) 776-7379.

### Where the words come from

| Part | Source |
|---|---|
| Item 3 and the small print | The members' block "Ready to enroll?" on the plans page (page 919), lightly joined into one paragraph |
| Enrollment form link | The same block |
| Phone | The /apply page: "Call us at (440) 776-7379" |
| "Lost your password?" sentence | The KEENEY plugin's current email |

### Questions for the client

1. **The name of the section.** Signed-in Active Members see "Ready to enroll?" on the plans page. "How to enroll" is the version for everyone else, and it has no enrollment form link. The email therefore says "Ready to enroll?". If the client prefers "How to enroll", the page's heading has to change too.
2. **The phone number.** The email uses the /apply page's number, (440) 776-7379. The plans page itself offers +1 (888) 388-1720 for enrollment help. Which one should members see?
3. **No greeting by name.** The email starts without a name, because emails from the portal project carry no personal data. The old emails said "Hi <first name>". Is that acceptable?

## Other emails

| Email | When | Change |
|---|---|---|
| User Pending Confirmation ("Your Membership Application is Under Review") | Was on form submission, before payment was confirmed. Now on the Subscription Created event | Text unchanged. See `changes.md`, item 3 |
| Your ProPerX Alliance member portal account | Sent by the portal, not WordPress, only with `INTAKE_SEND_INVITE=true` | Keep off until the portal is announced to members; otherwise a new member gets it right after paying, before approval |
