# Аудит сайту properx.org (локальна копія)

Дата: 2026-11-03. Джерело: `D:\WORK\OSPanel6\home\properx-org.local`, база `properx_org`. Під час аудиту на сайті нічого не змінювалось. Персональних даних і ключів тут немає.

Висновок: **блокера для з'єднання немає.** Stripe на копії в режимі Test. Подія «оплачено» для форми 1 — `gform_post_subscription_started`.

## 1. Форми

### Форма 1 «Membership Application Form» (сторінки `/apply` і `/apply-test`)

| ID | Тип | Назва | Обов. | Примітка |
|---|---|---|---|---|
| 5 | name | Name | так | видимі 5.3 First, 5.6 Last |
| 7 | email | Email address | так | з підтвердженням (7.2) |
| 8 | phone | Phone Number | так | |
| 9 | text | Business/Agency Name | ні | |
| 11 | address | Address | так | тип US: 11.1, 11.3, 11.4 (повна назва штату), 11.5; геолокація прихована |
| 14 | text | NPN | так | фільтр теми: 1–10 цифр |
| 15 | date | Insurance license expiration date | так | datepicker, формат mdy |
| 16 | address | Resident License State | **ні** | видиме лише 16.4 |
| 17 | checkbox | License Type(s) Held | **ні** | Life, Health, Property & Casualty, Annuity, Variable |
| 28 | product | Select Your Membership Plan | так | один варіант: Individual ($199 закреслено, $169/рік), значення `individual` |
| 29 | radio | Payment Method | так | Credit Card (3%), Debit Card, ACH Direct Debit |
| 30 | product (calculation) | Credit Card Surcharge (3%) | ні | показ, якщо 29 = Credit Card; формула `{28} * 0.03` |
| 31 | html | ACH Payment Notice | – | показ, якщо 29 = ACH |
| 19 | stripe_creditcard | Payment Information | ні | Stripe Elements, кілька способів оплати |
| 24 | consent | заборона повторної заявки 24 міс. | так | |
| 32 | consent | внески не повертаються, автопродовження | так | **та сама назва, що й у поля 24** |
| 25 | consent | Terms of Service і Privacy Policy | так | |
| 27 | select | How did you hear about ProPerx Alliance? | ні | |

HTML-блоки 4, 10, 12, 18, 21, 22 — лише заголовки секцій.

**Confirmations:** типова («заявка подана, розгляд 3–5 робочих днів»); Timeout при `payment_status = Processing`.

**Notifications** (обидві на подію `form_submission`, тобто до підтвердження оплати):

| Назва | Кому | Тема | Суть |
|---|---|---|---|
| New Membership Request Staff Notification | фіксована адреса адміністрації | New Membership Application Received + ім'я + entry ID | дані заявника, посилання на запис |
| User Pending Confirmation | заявник (поле 7) | Your Membership Application is Under Review | «оплату отримано, заявка на розгляді» |

**Фіди:**

| Фід | Тип | Параметри |
|---|---|---|
| #2 Stripe «Alliance Membership Subscription Feed» | підписка | сума `form_total`, цикл 1 рік, без setup fee і trial; customer: email (7), name (5), description (28); billing: поле 11 |
| #1 User Registration «Create User from From» | створення | роль `pending_member`, логін і email = поле 7, активація вимкнена; запускається за умови `payment_status` Active/Paid/Processing/Pending; Stripe-фід відкладає цей фід до оплати |

Фідів Webhooks або CRM немає.

### Форма 2 «Membership Interest Form»

Поля 5, 7, 8, 9, 11, 14, 15, 16, 17, 27 такі самі, як у формі 1, але без оплати й згод. Confirmation: «Your submission has been received». Notification: staff, подія `form_submission`. Фідів немає. **Не вбудована в жодну опубліковану сторінку.** Для з'єднання не потрібна: там немає оплати.

## 2. Як людина стає Active Member

Вручну. Після оплати User Registration створює WP-користувача з роллю `pending_member`. Далі співробітник у Users натискає «Approve & Activate» (плагін `properx-membership-roles`, автор KEENEY), і роль стає `active_member`. Автоматики немає. Ролі: `pending_member`, `active_member` (плюс `compliance_officer`, `membership_coordinator`, `executive_director`).

## 3. Лист Approved / Welcome

Є, і **спрацьовує двічі** при зміні ролі на `active_member`:
- плагін: «Welcome to ProPerx Alliance — Set Your Password» (HTML, посилання на пароль WordPress);
- тема (`functions.php`, хук `set_user_role`): «Your Membership Application Has Been Approved!» (текст, без наступних кроків).

Портал ці листи не надсилає, а посилання веде на WordPress, не на портал.

## 4. Сторінки

| Сторінка | Що на ній | Умови Elementor |
|---|---|---|
| `/apply` (ID 26) | shortcode форми 1 | немає |
| `/apply-test` (ID 876) | shortcode форми 1, **опублікована й публічна** | немає |
| `/2027-health-plan-options/` (ID 919) | форм немає; два HTML-блоки | один для всіх, крім `active_member` (заклик вступити), другий лише для `active_member` |
| `/no-access` (ID 916) | `[dlm_no_access]` | – |

На копії permalink-и вимкнені, тому `/apply` тут відкривається як `/?page_id=26`.

## 5. Плагіни

Активні: All-in-One WP Migration 7.113, Classic Editor 1.7.0, Download Monitor 5.3.6 + PRO 1.1.4, Elementor 4.3.6, Elementor Pro 4.3.3, GP Address Autocomplete 1.2.30, GP Better User Activation 1.2.13, Gravity SMTP 2.3.4, ProPerx Membership Roles 1.0.0, Readlyte 1.0.0, Site Kit 1.191.0, Web Accessibility 4.1.4, WP Dummy Content Generator 4.0.0, WP Extra File Types 0.5.2, Yoast SEO 28.8.

Gravity Forms 3.2.1 і add-ons: Stripe 7.0.0, User Registration 5.5.2, Webhooks 1.7.0, Geolocation 1.5.3.

**Action Scheduler немає**, тому конектор використовує WP-Cron. WordPress 7.1.4, PHP 8.4.

## 6. CRM, розсилка, Plan Viewer

CRM або розсилка не підключені: ключі Mailchimp і MailerLite в Elementor порожні, фідів немає. Пошта йде через Gravity SMTP. Поняття «Plan Viewer» на сайті немає: нуль збігів у налаштуваннях, сторінках і даних Elementor.

## 7. Кастомний код

- **CSS бейджа/ціни:** Customizer (пост 383): `.membership-plan .gchoice .price`; також `style.css` дочірньої теми `.apply-form .gfield_radio .gchoice .price`.
- **mu-plugins:** до цієї роботи не було.
- **`hello-elementor-child/functions.php`:** створює ролі; лист про активацію; валідація NPN (1–10 цифр); `hide_founding_member_plan` (ховає Founding після 2026-09-01; такого варіанта вже немає, код не діє); `validate_stripe_card_surcharge` (обнуляє націнку для Debit/ACH).

## 8. Яка подія означає «оплачено»

Форма 1 — **підписка** (фід `subscription`, транзакції типу 2, `transaction_id` = `sub_...`). Тому `gform_post_payment_completed` (разові платежі) тут не спрацює.

Використовуємо **`gform_post_subscription_started`**:
- картка: викликається під час обробки підписки, запис стає `Active`;
- ACH: запис спершу `Processing`; коли Stripe підтверджує оплату (`invoice.payment_succeeded`), `add_subscription_payment` викликає той самий `start_subscription`. Отже, людина потрапляє в портал лише після реального зарахування;
- щорічні продовження подію не викликають (перевірка на вже активну підписку).

Для ACH у Gravity Forms має працювати вебхук Stripe. Конектор додатково слухає `gform_post_payment_completed` на випадок, якщо форму колись переведуть на разовий платіж, і надсилає лише записи зі статусом `Active` або `Paid`.

Stripe customer ID лежить у мета запису `stripe_customer_id`, ID підписки — у `transaction_id`.

## 9. Поле форми → поле порталу

| Форма 1 | Портал | Примітка |
|---|---|---|
| 7 | `users.email` | ключ зіставлення |
| 5.3, 5.6 | `member_profiles.first_name`, `last_name`, `users.name` | |
| 8 | `phone` | шифрується |
| 9 | `firm_name` | |
| 11.1, 11.3, 11.4, 11.5 | `address` | у порталі один текстовий шифрований рядок |
| 14 | `npn` | шифрується, blind index |
| 15 | `license_expires_at` | mdy → ISO |
| 16.4 | `license_state` | повна назва → двобуквений код (`UsStates`) |
| 17.x | `license_type` | у формі кілька значень, у порталі один рядок: зберігаємо через кому |
| meta `stripe_customer_id` | `stripe_customer_id` | для звірки з вебхуком |
| `transaction_id` | `stripe_subscription_id` | **нове поле в порталі** |
| entry ID | `intake_submissions` | ідемпотентність |

**У формі немає, а в порталі є:** `license_number` (сертифікат його вимагає; якщо він обов'язковий, сертифікат для нових учасників буде недоступний до заповнення).

**У формі є, а в порталі немає (не передаємо):** 27 «How did you hear», згоди 24/32/25, план 28, сума і спосіб оплати. Дані картки й файли не передаються взагалі.

`paid_through_at` і `payment_status` ставить лише вебхук Stripe порталу.

## 10. Проблеми на сайті

**Ціни й оплата**
1. Націнка 3% залежить від того, що людина сама обрала в радіокнопці, а не від типу картки: можна вибрати Debit і заплатити кредитною без націнки. У специфікації агентств націнка 2,9% і лише для `card.funding = credit`.
2. `recurringAmount = form_total`: націнка входить у щорічний платіж.
3. Радіокнопка Payment Method не пов'язана зі способом оплати у Stripe Elements.
4. Фід Stripe має застарілий `api_mode = live`. На копії він не діє: режим фіда використовується лише за власного Stripe Connect у фіда, а його немає. На копії Live-ключів немає взагалі.
5. На копії порожній `webhooks_enabled` у налаштуваннях Stripe (signing secret для Test заданий). Варто перевірити на проді: без вебхука ACH не підтвердиться.

**Обов'язкові поля**
6. Resident License State (16) і License Type (17) необов'язкові, хоча потрібні для перевірки ліцензії. Портал приймає заявку без них.
7. У формі немає номера ліцензії (див. розділ 9).
8. Згоди 24 і 32 мають однакову назву: у записах і листах їх не розрізнити.

**Листи й статуси**
9. «User Pending Confirmation» пише «оплату отримано» і надсилається на `form_submission`, тобто до підтвердження оплати, зокрема для ACH і невдалих платежів.
10. При активації людина отримує два листи (розділ 3). Посилання веде на пароль WordPress, а не на портал.
11. User Registration запускається і для `Processing`/`Pending`. На запису 16 є помилка «User already exists» для повторної заявки.
12. Мета `properx_membership_tier` і `properx_application_date` не прив'язані у фіді: колонки в Users порожні.
13. У формі 2 лист посилається на поле 28, якого там немає.

**Процес (рішення за клієнтом)**
14. Сайт обіцяє розгляд 3–5 днів, а портал створює учасника одразу після оплати (зі статусом ліцензії `pending_review`). Якщо заявку відхилять у WordPress, запис у порталі лишиться: зворотного зв'язку немає.

**Прибирання**
15. `/apply-test` публічна. WP Dummy Content Generator активний на проді. Код Founding не діє. Форма 2 ніде не показана. У формі 1 є 6 записів із платіжними ID, 5 із них у кошику.
