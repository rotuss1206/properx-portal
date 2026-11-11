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

---

## Questions for ProPerX

1. Is the day-7 copy to the principal enough, or should staff hear of people who have not accepted as well?
2. The terms accepted on the last step are the Terms of Service and the Privacy Policy on properx.org. Should
   agency members accept a different or additional document, for example a member code of conduct?
3. Which address should receive "New agency enrolled" by email, if any?
