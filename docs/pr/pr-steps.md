# PR у KEENEY — кроки (шпаргалка)

Репо:
- Головне (GitHub): `D:\WORK\OSPanel6\home\properx-portal.local`
- KEENEY-клон (Azure DevOps): `D:\WORK\Avedsoft\Kevin\Portal\keeneycs-shn-portal`
- Azure PR: https://dev.azure.com/keeneycs/keeneycs-hipaa/_git/keeneycs-shn-portal/pullrequests

## 0. Підготовка (CCode)
Промпт:
```
Prepare the KEENEY PR for everything since the last merged PR: full tests + Pint; nothing local-only staged (.env, docs/wordpress/backup/, docs/agency/doc/, mu-plugins, scratchpad); flags with safe production defaults; PR description under 4000 characters in docs/pr/<name>.md (title, short bullets, migrations in run order, env with prod values, routes/limits, scheduler, deploy steps); list of files to commit + commit message for the main repo and a short one (≤60 chars) for KEENEY. Reply in Ukrainian.
```

## 1. Головне репо (GitHub Desktop → properx-portal)
1. Changes: перевір, що НЕМАЄ `.env`, `backup/`, `docs/agency/doc/`, `docs/agency/screens/`, `.jpg/.png` макетів.
2. Summary = повідомлення коміту від CCode → **Commit to main** → **Push origin**.

## 2. KEENEY-клон (GitHub Desktop → keeneycs-shn-portal)
1. Current branch → **main**.
2. **Fetch origin** → **Pull origin** (нагорі в History має бути останній "Merged PR …").
3. **ПЕРЕВІР History: чи є PR НЕ від нас і НЕ "Laravel runtime base"** (напр. Kevin: composer.lock, Livewire, .trivyignore тощо). Якщо є — ДО sync перенести цю зміну в головне репо, інакше sync її відкотить. Промпт:
   ```
   KEENEY main has commits not from us since our last merged PR: <list>. Show what they changed (git show in the KEENEY clone), check whether scripts/sync-to-keeney.ps1 would overwrite those files, and bring the same changes into our main repo (tests after). Tell me what to commit. Reply in Ukrainian.
   ```
   "Laravel runtime base" від Build Service чіпає тільки `site.json` — sync його не копіює, це ок.
4. Current branch → **New branch** → назва `feature/<коротко>` → від **main**.

## 3. Sync
```
powershell -ExecutionPolicy Bypass -File D:\WORK\OSPanel6\home\properx-portal.local\scripts\sync-to-keeney.ps1
```
В кінці має бути успішна перевірка на сліди.

## 4. Коміт у KEENEY
1. Changes: **`site.json` НЕ має бути** (його оновлює Build Service). Якщо є — не комітити, розібратися.
   - Також не має бути відкату змін Кевіна (напр. `composer.lock` зі старими версіями).
   - Фільтр GitHub Desktop шукає розкидані літери, тож "trivy"/"CLAUDE" можуть показати звичайні файли — це нормально.
2. **Clear filters** → Summary = короткий опис (≤60 символів) → **Commit to feature/…** → **Publish branch**.

## 5. Azure DevOps
1. Repos → Pull requests → **New pull request**: `feature/…` → `main`.
2. Title = перший рядок `docs/pr/<name>.md` (без `#`).
3. Description = решта файлу. **Ліміт 4000 символів** — якщо не влазить, скоротити.
4. **Create** → дочекатися build/Trivy. Gate B через базовий образ або DOMPurify в Nova — відома річ, пишемо в коментар. Gate A (FIXABLE HIGH/CRITICAL) — просимо в Кевіна таблицю Trivy або CCode відтворює `trivy fs` локально.

## 6. Після PR
- Короткий звіт Кевіну + окремо інструкція з блоком env (нові змінні, prod-значення, що лишається OFF).
- Плагін WordPress у PR НЕ йде (sync виключає `integrations/`) — ставиться на сайт окремо: Plugins → Upload → `dist\properx-portal-<ver>.zip` → Replace current with uploaded.

## Якщо Azure не пускає (Authentication failed)
- PAT: Organization **keeneycs**, Scopes → **Code → Read & write** (НЕ Work Items — вони поруч у списку). Username будь-який, Password = токен.
- Або без PAT: у терміналі в KEENEY-клоні `git fetch` → вхід Microsoft у браузері (scheverda@keeney.io).

## Ніколи
- Секрети, ключі Stripe, `.env`, токени — не в чат, не в пошту, не на скріни.
- Жодних слідів AI в KEENEY (sync перевіряє, але дивись очима).
- Живі налаштування Stripe і properx.org — тільки планові зміни.
