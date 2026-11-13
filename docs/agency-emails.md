# Agency emails: drafts for approval

Emails the portal sends after an agency enrolls and pays (Agency spec 7). The wording below is a
draft: please confirm it or send corrections. Once approved, the text is changed only in the classes named
under each email.

## How these emails are sent

- **Sender and look.** Every email comes from the portal's sender address, *ProPerX Alliance*. It uses the
  portal's email layout: the ProPerX Alliance logo embedded in the message (no remote images), a white card
  and a footer.
- **What they never contain.** No subject line contains personal data. No body names anyone, and none
  contains an NPN, license data or agency identifiers. The welcome email is the only one with figures: the
  amounts paid and the renewal date.
- **Links.** Each person's link is single-use and expires after **14 days**. The portal stores only a
  fingerprint (hash) of the link, never the link itself. A reminder sends a new link for the same invitation,
  with the same expiry date. When staff resend an invitation, every earlier link stops working.
- **Reminders.** People who have not set up their account get a reminder **3 days** and **7 days** after the
  invitation. The day-7 reminder is also copied to the principal. Each reminder is sent once.

---

## 1. Welcome and receipt (to the principal)

**When:** as soon as the agency is enrolled. This happens after a card payment, or once a bank payment clears.
**Class:** `App\Notifications\AgencyWelcome`

**Subject:** Welcome to ProPerX Alliance: your agency is enrolled

> **Welcome to ProPerX Alliance**
>
> Thank you: your agency is enrolled and your payment has been received.
>
> | Receipt | |
> |:--|--:|
> | Membership | Agency, Founding rate |
> | People | 3 |
> | Membership dues for the year | $477 |
> | Credit card surcharge *(credit cards only)* | $13.83 |
> | **Total paid** | **$490.83** |
> | Renews on | November 11, 2027 |
>
> Your membership renews automatically each year; we will email you 14, 7 and 1 day before.
>
> Everyone else on your list gets their own email invitation to set up their member account. We remind those
> who have not done so after 3 and 7 days, and copy you on the second reminder.
>
> Your own account is ready too. Choose your password, then turn on two-factor sign-in with an authenticator app.
>
> **[Set up my account]**
>
> This link works once and expires on November 25, 2026. After that, use "Forgot your password?" on the
> sign-in page.
>
> Regards,
> ProPerX Alliance

The amounts above are an example. For a partner code, the Membership row says "Partner rate".

## 2. Invitation (to each other person on the list)

**When:** as soon as the agency is enrolled, and again whenever staff use "Resend invitation".
**Class:** `App\Notifications\AgencyInvitationEmail`

**Subject:** Your agency has enrolled you in ProPerX Alliance

> **Hello,**
>
> Your agency has enrolled you as a member of ProPerX Alliance, and your membership is paid for.
>
> Set up your account in the member portal: choose a password, turn on two-factor sign-in with an
> authenticator app, then check your profile and accept the terms.
>
> **[Set up my account]**
>
> This link works once and expires on November 25, 2026. If it has expired, open it anyway and ask for a new
> invitation.
>
> If you were not expecting this email, you can ignore it.
>
> Regards,
> ProPerX Alliance

## 3. Reminder (day 3 and day 7)

**When:** 3 and 7 days after the invitation was sent, while the person has not set up their account. This
includes the principal's own invitation. The reminder carries a new link with the same expiry date.
**Class:** `App\Notifications\AgencyInvitationEmail` (reminder)

**Subject:** Reminder: set up your ProPerX Alliance member account

> **Hello,**
>
> Your agency enrolled you as a member of ProPerX Alliance, and your member account is still waiting to be
> set up.
>
> **[Set up my account]**
>
> This link works once and expires on November 25, 2026. If it has expired, open it anyway and ask for a new
> invitation.
>
> If you were not expecting this email, you can ignore it.
>
> Regards,
> ProPerX Alliance

## 4. Copy of the day-7 reminder (to the principal)

**When:** with the day-7 reminders. The principal gets one email per agency for each daily run.
**Class:** `App\Notifications\Member\AgencyInvitationNotice` (not accepted)

**Subject:** Invitations not yet accepted

> **Hello,**
>
> 2 people on your agency's list have not set up their member accounts yet. We sent each of them a second
> reminder today.
>
> You will see who under the bell in the member portal. If an email address is wrong or someone needs a new
> link, contact ProPerX.
>
> **[Open the member portal]**
>
> Regards,
> ProPerX Alliance

The email names nobody. The names appear in the portal's notifications panel, for example
"Not yet set up: Marcus Reyes, Priya Nair".

## 5. A new invitation was requested (to the principal)

**When:** someone opens an expired, used or replaced link and presses "Ask for a new invitation". This is
sent at most once a day per link. ProPerX staff are notified in the admin panel at the same time, because
only staff can resend an invitation for now.
**Class:** `App\Notifications\Member\AgencyInvitationNotice` (requested)

**Subject:** A new ProPerX Alliance invitation was requested

> **Hello,**
>
> Someone on your agency's list opened an invitation link that had expired or had already been used, and
> asked for a new invitation.
>
> ProPerX staff have been told and will send a new link. You will see who asked under the bell in the member
> portal.
>
> **[Open the member portal]**
>
> Regards,
> ProPerX Alliance

## 6. New agency enrolled (to ProPerX staff)

**When:** as soon as an agency is enrolled. Staff with access to agencies always see this notice in the admin
panel. The email goes out only when an address is set (`AGENCY_STAFF_NOTIFICATION_EMAIL`; empty for now).
**Class:** `App\Notifications\AgencyEnrolledStaff`

**Subject:** New agency enrolled

> **Hello,**
>
> A new agency has enrolled and paid (agency #12): Agency, 3 people.
>
> Their invitations have been sent. Licensed producers are waiting for their NPN and state to be checked.
>
> **[Open the agency]**
>
> Regards,
> ProPerX Alliance

## 7. A license needs attention (to the principal)

**When:** ProPerX staff check a person's NPN and resident state in NIPR or the state registry and mark
it "Needs attention" (Agency spec 8.3). Staff must write what to fix. The person sees the same reason in
the member portal (dashboard and bell); if the person is the principal, they get email 8 instead.
**Class:** `App\Notifications\Member\LicenseNeedsAttention` (agency)

**Subject:** Action needed: a license on your agency's list

> **Hello,**
>
> ProPerX Alliance checked the NPN and resident state of one person on your agency's list and could not
> verify them.
>
> What to fix: The NPN does not match the resident state in NIPR.
>
> You will see who under the bell in the member portal. The person can correct their NPN or resident
> state in their profile, and we will check it again.
>
> **[Open the member portal]**
>
> Regards,
> ProPerX Alliance Membership Team

The email names nobody; the bell shows "Marcus Reyes: license needs attention" with the reason. The
"What to fix" line is staff's text, as typed in the admin panel.

## 8. Your license needs attention (to an individual member, or a principal about their own license)

**When:** as email 7, for a member who is not in an agency, or for the principal's own license.
**Class:** `App\Notifications\Member\LicenseNeedsAttention` (own)

**Subject:** Action needed: your license could not be verified

> **Hello,**
>
> ProPerX Alliance checked your NPN and resident state and could not verify them.
>
> What to fix: The NPN does not match the resident state in NIPR.
>
> Please correct them in your profile, and we will check them again.
>
> **[Update my profile]**
>
> Regards,
> ProPerX Alliance Membership Team

When the person corrects their NPN or state, the license goes back to "Pending review" in the staff queue.

## 9. Renewal reminder (to the principal, or to an individual member)

**When:** 14, 7 and 1 day before the membership renews (the days are a setting). Sent to an agency's
principal about the agency, and to an individual member who pays through Stripe about their own
membership. The same reminder appears in the member portal's notifications. Not sent if the membership is
set to end instead of renewing.
**Class:** `App\Notifications\Member\RenewalReminder`

**Subject:** Your agency's ProPerX Alliance membership renews in 14 days
*(individual member: "Your ProPerX Alliance membership renews in 14 days"; one day before: "… renews tomorrow")*

> **Hello,**
>
> Your agency's membership renews automatically in 14 days. Here is what will be charged:
>
> | Renewal | |
> |:--|--:|
> | Renews on | March 15, 2027 |
> | People | 3 |
> | Tier | Agency |
> | Amount | $477.00 a year |
> | Payment method | Visa ending 4242 |
>
> To change the payment method or see your invoices, open Billing in the member portal.
>
> **[Review billing]**
>
> Regards,
> ProPerX Alliance

An individual member's reminder has no People or Tier rows. The welcome email and the enrollment's
confirmation screen say: "we will email you 14, 7 and 1 day before".

## 10. Renewal payment overdue (to the principal, or to an individual member)

**When:** after a renewal payment fails, 7 days and 1 day before access ends (the grace period is 14
days; both are settings). Also in the portal's notifications. Until then everyone keeps full access and
the portal shows a banner with the date.
**Class:** `App\Notifications\Member\PaymentOverdueReminder`

**Subject:** Reminder: your ProPerX Alliance renewal payment is overdue

> **Hello,**
>
> We could not collect your agency's renewal payment. Everyone keeps access until March 29, 2027.
>
> Please pay before then, or the membership expires and your people lose access to their certificates,
> the library and documents.
>
> **[Pay now]**
>
> Regards,
> ProPerX Alliance

## 11. Your membership has expired (to the principal, or to an individual member)

**When:** the grace period after a failed renewal is over (at once if the grace period is set to 0), or
the subscription has ended. Sent once. When ProPerX staff cancel an agency's membership "now", the
principal gets the cancellation email instead of this one.
**Class:** `App\Notifications\Member\MembershipExpired`

**Subject:** Your membership has expired

> **Hello,**
>
> Your agency's ProPerX Alliance membership has expired, so your people's memberships are inactive.
>
> Everyone can still sign in, but certificates, the compliance library and documents are closed. Each
> person has been told.
>
> Renew to restore access: as soon as the payment goes through, everything opens again.
>
> **[Renew my membership]**
>
> Regards,
> ProPerX Alliance

For an individual member: "Your ProPerX Alliance membership has expired. You can still sign in, but your
certificate, the compliance library and documents are closed."

## 12. Your agency's membership has ended (to each person of the agency)

**When:** with email 11, to everyone on the agency's list except the principal. Sent once.
**Class:** `App\Notifications\Member\AgencyMembershipEnded`

**Subject:** Your agency's ProPerX Alliance membership has ended

> **Hello,**
>
> Your agency's ProPerX Alliance membership has ended, so your membership is inactive.
>
> You can still sign in, but your certificate, the compliance library and documents are closed. If your
> agency renews, your access returns automatically.
>
> Questions about it? Please ask your agency or contact ProPerX.
>
> **[Open the member portal]**
>
> Regards,
> ProPerX Alliance

---

## 13. People added (receipt to the principal)

**When:** right after the principal adds people on "My agency" and the prorated payment goes through. Sent once.
**Class:** `App\Notifications\AgencyPeopleAdded`

**Subject:** People added to your agency's ProPerX Alliance membership

> **Hello,**
>
> 2 people have been added to your agency, and the payment has been received. Each of them gets an email
> invitation to set up their account.
>
> | Receipt | |
> |:--|--:|
> | People added | 2 |
> | *Stripe's proration lines, e.g. "Remaining time on 5 × ProPerX Agency Membership after Nov 13, 2026"* | $397.50 |
> | *"Unused time on 3 × ProPerX Agency Membership after Nov 13, 2026"* | −$238.50 |
> | Prorated dues until May 13, 2027 | $159.00 |
> | Credit card surcharge *(credit cards only)* | $4.61 |
> | **Total paid** | **$163.61** |
> | Paid with | Visa credit card ending 4242 |
>
> *(Only when the agency moved up a tier:)* Your agency has moved up to Agency Plus (10–49): everyone's rate is
> now $149 a year, prorated above for the rest of the year.
>
> **[Open my agency]**
>
> Regards,
> ProPerX Alliance

---

## 14. Your membership through your agency is ending (to a person the principal removed)

**When:** when the principal removes someone; the person keeps full access until the agency's renewal date.
**Class:** `App\Notifications\Member\AgencyRemovalScheduled` (also in the bell)

**Subject:** Your ProPerX Alliance membership through your agency is ending

> **Hello,**
>
> Your agency has removed you from its ProPerX Alliance membership. Your membership through the agency ends on
> May 13, 2027; until then everything stays as it is.
>
> To stay a member after that, you can apply for an individual membership.
>
> **[Apply as an individual member]** *(INDIVIDUAL_MEMBERSHIP_URL)*
>
> Regards,
> ProPerX Alliance

---

## 15. Your agency's membership will not renew (to everyone still on the list)

**When:** when removals leave fewer than two people for the next year. Sent once, to each person who stays
(the principal included). No credits or refunds.
**Class:** `App\Notifications\Member\AgencyEndingAtRenewal` (also in the bell)

**Subject:** Your agency's ProPerX Alliance membership will not renew

> **Hello,**
>
> An agency membership needs at least two people. After the changes on your agency's list, your agency's
> membership ends on May 13, 2027 and will not renew.
>
> Until then everything stays as it is. To stay a member after that, you can apply for an individual membership.
>
> **[Apply as an individual member]**
>
> Regards,
> ProPerX Alliance

---

## Questions for ProPerX

1. Is the day-7 copy to the principal enough, or should staff hear of people who have not accepted as well?
2. The terms accepted on the last step are the Terms of Service and the Privacy Policy on properx.org. Should
   agency members accept a different or additional document, for example a member code of conduct?
3. Which address should receive "New agency enrolled" by email, if any?
