# ProPerx Member Portal

Портал самообслуговування учасника ProPerx Alliance (професійна асоціація ліцензованих страхових агентів).
Повне ТЗ: [docs/Portal_uchasnyka_Vymohy_dlia_rozrobnyka.docx](docs/Portal_uchasnyka_Vymohy_dlia_rozrobnyka.docx) (Ред. 2, KEENEY).
Цільовий хостинг: Azure AKS (HIPAA-рівень, обліковий запис SHN). Секрети в проді лежать в Azure Key Vault.

## Стек

- **Laravel 13**, PHP 8.4, MySQL.
- **Laravel Nova 5.11**: адмінка для співробітників і кабінет учасника (`/nova`). Встановлюється й оновлюється вручну власником проєкту, агент її не ставить.
- **Laravel Fortify** (іде з Nova): вхід, скидання пароля, TOTP 2FA з кодами відновлення.
- **spatie/laravel-permission**: ролі та дозволи (трейт `HasRoles` на `App\Models\User`).
- **barryvdh/laravel-dompdf**: PDF-сертифікат членства.
- **Postmark**: транзакційна пошта (у локальному середовищі `MAIL_MAILER=log`).
- **Azure Blob Storage**: сховище файлів. Поки що використовується локальний приватний диск через фасад `Storage`. Код пишемо лише через `Storage::disk(...)`, щоб перехід на Azure зводився до зміни конфігурації.

## Ролі (ТЗ, розділ 3.7)

| Роль | Ключові права |
|---|---|
| Executive Director | Повний доступ. Єдина роль, що створює та змінює акаунти співробітників. Підписант сертифікатів. Не відкликається через UI. |
| Membership Coordinator | CRUD учасників, перевірка документів ліцензій, повідомлення учасникам. Без бібліотеки, податкових документів і керування співробітниками. |
| Compliance Specialist | CRUD бібліотеки, завантаження податкових документів з прив'язкою до учасника, перегляд учасників лише для читання. |
| Member | Тільки власні профіль, документи, сертифікат і налаштування; бібліотека лише для читання. |
| Службовий API-акаунт | Не людина, без вебвходу. Автентифікація API-ключем (ротація щокварталу), мінімальні права під кожне завдання (вебхуки Stripe, заплановані завдання). |

Ролі Auditor/Board Member і Treasurer сюди не входять: їх додадуть пізніше.

## Основні функції (ТЗ, розділ 2)

- Вхід за email і паролем, скидання пароля, опційна TOTP MFA з кодами відновлення (обов'язкова для співробітників).
- Профіль учасника: контакти, бізнес, NPN, ліцензія (номер, штат, тип, дата закінчення).
- Документи ліцензії: PDF/JPG/PNG до 10 МБ, приватне шифроване зберігання, видача лише через серверний ендпоінт з перевіркою ролі та власника.
- Щоденне завдання за статусами ліцензій (active / expiring / expired) і нагадування за 60, 30 і 7 днів з урахуванням налаштувань сповіщень. Кожен надісланий або пропущений лист пишеться в журнал нагадувань.
- PDF-сертифікат членства з контрольним номером для перевірки. Кеш 24 години, перегенерація лише при зміні даних.
- Сховище податкових документів, бібліотека комплаєнсу (категорії, пошук), налаштування сповіщень за категоріями.
- Журнал аудиту: вхід, вихід, пароль, MFA, профіль, завантаження, скачування та видалення документів, зміна ролі; з IP і user agent.

## Поза обсягом робіт

SSO із зовнішніми провайдерами, обмін повідомленнями між учасниками, події та календар, облік CE-кредитів, сторонні API перевірки ліцензій, нативні мобільні застосунки.

## Правила розробки

- **Міграції з індексами**: на всіх зовнішніх ключах (`foreignId()->constrained()`) і на полях, за якими фільтруємо чи сортуємо (статуси, дати закінчення, `member_id + created_at` у журналах).
- **Eloquent-зв'язки** (`hasOne`, `hasMany`, `belongsTo` тощо) замість ручних join і сирих запитів. Уникаємо N+1 за допомогою `with()`.
- **Без хардкоду секретів**: ключі, паролі, токени та URL сервісів беремо лише з `.env` через `config/*.php`. У коді `config()`, а не `env()`. Нові змінні додаємо також в `.env.example` з порожнім значенням.
- Авторизація через Policies і дозволи spatie. Доступ до файлів перевіряється на сервері для кожного запиту, публічних URL немає.
- Чутливі поля зберігаємо зашифрованими. MFA-секрет і коди відновлення: колонки Fortify `two_factor_*` (Fortify шифрує коди відновлення, а не хешує, як просить ТЗ; це відоме відхилення).

## Доступ і Nova

- Gate `viewNova` ([NovaServiceProvider](app/Providers/NovaServiceProvider.php)) діє в усіх середовищах, включно з local. До Nova пускає чотири ролі; API Service не пускає.
- Права описані в [RolesAndPermissionsSeeder](database/seeders/RolesAndPermissionsSeeder.php). Policies в `app/Policies` перевіряють лише права spatie (`checkPermissionTo`), а не назви ролей.
- Меню Nova будується автоматично з `viewAny` у policies, окреме меню для кожної ролі не потрібне.
- Учасник бачить лише свої записи: `indexQuery` через `Resource::ownRecordsUnless()` плюс `view` у policy.
- Ресурси `Staff` і `Member` обидва працюють з моделлю `User`: `viewAny` і `create` для них розведені в трейті `AuthorizesUserResource`.
- Middleware [RequireTwoFactorForStaff](app/Http/Middleware/RequireTwoFactorForStaff.php): співробітник без підтвердженої 2FA потрапляє лише на `/nova/user-security`. Для учасників 2FA опційна. `NOVA_REQUIRE_STAFF_2FA=false` вимикає вимогу, але діє лише при `APP_ENV=local`.
- Файли лежать на приватному диску `config('portal.documents_disk')` (поки `local`). Видаються лише через download-ендпоінт Nova з перевіркою policy, кожне скачування фіксується в журналі (`App\Services\DocumentDownloader`).
- Журнал аудиту: `AuditLog::record($action, $resource)`. Вхід і вихід пишуть слухачі в `app/Listeners`, вони ж оновлюють `last_login_at`.
- Тестові акаунти з сідера (пароль `password`): `director@`, `coordinator@`, `compliance@`, `member01@` … `member30@properx.test`. Сідер створює файли-заглушки для документів (`DemoFilesSeeder`).
- Тести: базовий `Tests\TestCase` викликає `Nova::flushState()`, бо Nova кешує дані запиту в статичних властивостях.

## Сертифікати й нагадування

- [CertificateGeneratorService](app/Services/Certificates/CertificateGeneratorService.php): PDF із шаблону `resources/views/pdf/certificate.blade.php`.
  - Кеш на 24 год на диску `portal.certificates.disk`; ім'я файлу — хеш даних, надрукованих на сертифікаті.
  - `MemberProfileObserver` скидає кеш, коли змінюються ці дані.
  - Для простроченої ліцензії або неповного профілю кидає `CertificateUnavailableException` з повідомленням, яке можна показати користувачу.
  - Після зміни шаблону збільшити `TEMPLATE_VERSION`.
- Скачування сертифіката лише через маршрут `certificates.download` (policy `downloadCertificate`). Публічна перевірка: `/verify-certificate/{code}`, ліміт 10 запитів на хвилину з IP; показує тільки ім'я й дати.
- «Дійсний з» = дата створення акаунта учасника, «дійсний до» = `license_expires_at` (окремих дат членства в моделі немає).
- `app:check-license-expirations` запускається щодня о 02:00 за `PORTAL_SCHEDULE_TIMEZONE`.
  - Оновлює статуси ліцензій і надсилає нагадування за 60/30/7 днів.
  - Дублі відсікає за `reminder_logs` (`user_id + reminder_type + license_expires_at`).
  - Учасникам, які вимкнули сповіщення, лист не надсилає: у журнал іде запис `skipped`.
  - При помилках сповіщає Executive Director.
- Пошта: `MAIL_MAILER=log` локально; для проду `postmark` з `POSTMARK_API_KEY`.

## Локальне середовище (Windows, OSPanel 6)

`php`, `composer` і `git` не додані в PATH, тому викликаємо їх за повним шляхом:

- PHP: `D:\WORK\OSPanel6\modules\PHP-8.4\php.exe`
- Composer: `<php> D:\WORK\OSPanel6\data\PHP-8.4\default\composer\composer.phar`
- Git (із GitHub Desktop, шлях змінюється при оновленні): `C:\Users\PC\AppData\Local\GitHubDesktop\app-*\resources\app\git\cmd\git.exe`
- Сайт: `https://properx-portal.local` (Apache + PHP-8.4, корінь `public`).
- Репозиторій: `https://github.com/rotuss1206/properx-portal`, гілка `main`.
- Тести: `<php> vendor\bin\phpunit`.
- Черга (`QUEUE_CONNECTION=database`) локально не обробляється сама: `<php> artisan queue:work`. Планувальник: `<php> artisan schedule:work`.
- PowerShell 5.1 читає файли як ANSI: не правити PHP-файли з кирилицею через `Get-Content`/`Set-Content`, лише через Edit.
