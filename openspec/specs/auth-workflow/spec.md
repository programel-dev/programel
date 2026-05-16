# Workflow автентифікації

Цей документ описує **повний життєвий цикл автентифікації** в API: як користувач входить у систему, як отримує токени, як ними користується, що відбувається коли токен протерміновується, і як його оновити без повторного логіну.

API не має сесій. Усе спілкування — stateless через JWT. Кожен запит до захищеного ендпоінту обов'язково містить заголовок `Authorization: Bearer <access_token>`. Сервер не пам'ятає клієнта між запитами — джерелом істини є сам токен (підписаний приватним ключем сервера).

---

## Загальна картина

```
┌──────────┐                                    ┌──────────┐
│  Клієнт  │                                    │   API    │
└────┬─────┘                                    └────┬─────┘
     │                                               │
     │ 1. POST /api/v1/auth/login (email, password)  │
     │──────────────────────────────────────────────▶│
     │                                               │
     │ 2. { token, refresh_token }                   │
     │◀──────────────────────────────────────────────│
     │                                               │
     │ 3. GET /api/v1/equeue_watches                 │
     │    Authorization: Bearer <token>              │
     │──────────────────────────────────────────────▶│
     │                                               │
     │ 4. 200 OK + дані                              │
     │◀──────────────────────────────────────────────│
     │                                               │
     │     ...через якийсь час access token          │
     │     протерміновується (TTL ~1 година)...      │
     │                                               │
     │ 5. GET /api/v1/equeue_watches                 │
     │    Authorization: Bearer <expired_token>      │
     │──────────────────────────────────────────────▶│
     │                                               │
     │ 6. 401 { code: "token_expired" }              │
     │◀──────────────────────────────────────────────│
     │                                               │
     │ 7. POST /api/v1/auth/refresh                  │
     │    { refresh_token: "..." }                   │
     │──────────────────────────────────────────────▶│
     │                                               │
     │ 8. { token, refresh_token } (нова пара)       │
     │◀──────────────────────────────────────────────│
     │                                               │
     │ 9. Повторюємо запит з новим токеном           │
     │──────────────────────────────────────────────▶│
     │                                               │
     │ 10. 200 OK + дані                             │
     │◀──────────────────────────────────────────────│
```

**Дві ролі токенів:**
- **Access token (JWT)** — короткоживучий (TTL 1 година). Підписаний, містить `userId` і `roles`. Сервер декодує його швидко, без звернення до БД. Передається в кожному запиті.
- **Refresh token** — довгоживучий (TTL 30 днів). Непрозорий (opaque) рядок, зберігається в БД. Використовується лише для отримання нового access token. **Одноразовий** — після використання анулюється і видається нова пара.

**Чому два токени:**
- Access token короткий, щоб у разі витоку зловмисник мав доступ ненадовго.
- Refresh token не передається в кожному запиті — менший ризик витоку. Зберігається тільки в `HttpOnly` cookie (для веб-фронтенду).

---

## ADDED Requirements

### Requirement: Логін за email і паролем
Система МАЄ дозволяти користувачу обмінювати свої credentials (email + пароль) на пару токенів через єдиний ендпоінт `/api/v1/auth/login`.

#### Scenario: Успішний логін повертає пару токенів
- **GIVEN** у БД існує користувач з email `user@example.com` і встановленим паролем `correct-password`
- **WHEN** клієнт надсилає `POST /api/v1/auth/login` з тілом:
  ```json
  {
    "email": "user@example.com",
    "password": "correct-password"
  }
  ```
- **THEN** сервер відповідає `HTTP 200` з тілом:
  ```json
  {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "refresh_token": "def50200a8f3..."
  }
  ```
- **AND** `token` — це підписаний JWT з payload `{userId, roles, iat, exp}`, де `exp` — поточний час + 3600 секунд
- **AND** `refresh_token` — непрозорий рядок, який збережено в таблиці `refresh_tokens` з прив'язкою до користувача і TTL 30 днів
- **AND** користувач від цього моменту вважається залогіненим

#### Scenario: Невалідні credentials відхиляються однаково
- **WHEN** клієнт надсилає `POST /api/v1/auth/login` з:
  - неіснуючим email, АБО
  - правильним email але невірним паролем
- **THEN** сервер відповідає `HTTP 401` з тілом:
  ```json
  { "code": 401, "message": "Invalid credentials." }
  ```
- **AND** відповідь **не розрізняє** ці випадки (не каже "user not found" окремо від "wrong password") — це захищає від енумерації акаунтів
- **AND** access token і refresh token **НЕ** видаються

#### Scenario: Логін без обов'язкових полів
- **WHEN** клієнт надсилає `POST /api/v1/auth/login` з порожнім тілом або без поля `password`
- **THEN** сервер відповідає `HTTP 400` з описом яких саме полів бракує

#### Scenario: Rate limit на ендпоінт логіну
- **GIVEN** з IP-адреси `203.0.113.5` уже надіслано 5 запитів до `/api/v1/auth/login` за останні 60 секунд
- **WHEN** з тієї ж IP надсилається 6-й запит до `/api/v1/auth/login` (незалежно від того, чи правильні credentials)
- **THEN** сервер відповідає `HTTP 429 Too Many Requests`
- **AND** відповідь містить заголовок `Retry-After: <секунд_до_розблокування>`
- **AND** ліміт скидається через 60 секунд від часу першого запиту у вікні

---

### Requirement: Використання access token для доступу до захищених ресурсів
Система МАЄ приймати валідний JWT у заголовку `Authorization: Bearer <token>` для всіх запитів до `/api/v1/*` крім публічних ендпоінтів (`/api/v1/auth/login`, `/api/v1/auth/refresh`).

#### Scenario: Запит з валідним токеном проходить
- **GIVEN** клієнт має access token, отриманий не більше години тому
- **WHEN** клієнт надсилає будь-який запит до `/api/v1/*` із заголовком `Authorization: Bearer <token>`
- **THEN** сервер декодує JWT, перевіряє підпис і термін дії, витягує `userId` із payload
- **AND** обробляє запит в контексті цього користувача
- **AND** повертає відповідну відповідь (200, 201, 404 тощо — залежно від ендпоінту, але **не** 401)

#### Scenario: Запит без заголовка авторизації
- **WHEN** клієнт надсилає запит до захищеного ендпоінту (наприклад `GET /api/v1/equeue_watches`) **без** заголовка `Authorization`
- **THEN** сервер відповідає `HTTP 401` з тілом:
  ```json
  { "code": 401, "message": "JWT Token not found" }
  ```

#### Scenario: Запит з малформованим токеном
- **WHEN** клієнт надсилає запит з заголовком `Authorization: Bearer не-валідний-токен`
- **THEN** сервер відповідає `HTTP 401` з тілом:
  ```json
  { "code": 401, "message": "Invalid JWT Token" }
  ```

#### Scenario: Запит з протермінованим токеном
- **GIVEN** access token, у якого поле `exp` у JWT payload вже в минулому (наприклад, токен видано 2 години тому, TTL 1 година)
- **WHEN** клієнт надсилає запит з цим токеном
- **THEN** сервер відповідає `HTTP 401` з тілом:
  ```json
  { "code": 401, "message": "Expired JWT Token" }
  ```
- **AND** клієнт використовує цей конкретний `message` як сигнал, що треба зробити refresh (а не повний relogin)

---

### Requirement: Оновлення access token через refresh token
Система МАЄ дозволяти обміняти валідний refresh token на нову пару `(token, refresh_token)` без повторного введення credentials. Кожен refresh token — **одноразовий**: після використання анулюється.

#### Scenario: Успішний refresh видає нову пару токенів
- **GIVEN** клієнт зберіг `refresh_token` отриманий під час логіну
- **AND** refresh token не протерміновано і не використано раніше
- **WHEN** клієнт надсилає `POST /api/v1/auth/refresh` з тілом:
  ```json
  { "refresh_token": "def50200a8f3..." }
  ```
- **THEN** сервер відповідає `HTTP 200` з тілом:
  ```json
  {
    "token": "<новий access JWT>",
    "refresh_token": "<новий refresh token>"
  }
  ```
- **AND** старий refresh token позначається як використаний у БД і більше **не приймається**
- **AND** новий refresh token має свій власний TTL 30 днів (не успадковує час життя старого)

#### Scenario: Повторне використання вже-використаного refresh token
- **GIVEN** refresh token, який уже був успішно обміняний на нову пару
- **WHEN** клієнт надсилає `POST /api/v1/auth/refresh` з цим же токеном вдруге
- **THEN** сервер відповідає `HTTP 401`
- **AND** (опційно) сервер може анулювати **всі** refresh tokens користувача — це сигнал, що токен міг бути викрадений

#### Scenario: Refresh з протермінованим токеном
- **GIVEN** refresh token, виданий понад 30 днів тому
- **WHEN** клієнт надсилає `POST /api/v1/auth/refresh` з цим токеном
- **THEN** сервер відповідає `HTTP 401`
- **AND** клієнт мусить виконати повний логін через `/api/v1/auth/login`

#### Scenario: Refresh з невідомим/підробленим токеном
- **WHEN** клієнт надсилає `POST /api/v1/auth/refresh` з рядком, якого немає в таблиці `refresh_tokens`
- **THEN** сервер відповідає `HTTP 401`

---

### Requirement: Прозорий retry після refresh
Клієнт МАЄ автоматично оновлювати access token при отриманні `401 Expired JWT Token` і повторювати оригінальний запит, **без участі користувача**.

#### Scenario: Автоматичний refresh-and-retry
- **GIVEN** клієнт надсилає `GET /api/v1/equeue_watches` із access token
- **AND** сервер відповідає `401 { "message": "Expired JWT Token" }`
- **WHEN** клієнт виявляє цей конкретний код помилки
- **THEN** клієнт автоматично:
  1. Бере свій збережений `refresh_token`
  2. Надсилає `POST /api/v1/auth/refresh`
  3. Зберігає нову пару токенів (стара пара затирається)
  4. **Повторює** оригінальний запит `GET /api/v1/equeue_watches` з новим access token
- **AND** користувач **не бачить** проміжного стану — для нього запит просто пройшов

#### Scenario: Refresh теж не вдався — потрібен повний logout
- **GIVEN** клієнт отримав `401 Expired JWT Token` на запит до захищеного ендпоінту
- **WHEN** клієнт намагається зробити refresh, але refresh теж повертає `401`
- **THEN** клієнт очищує всі збережені токени (cookies, localStorage тощо)
- **AND** редіректить користувача на сторінку логіну
- **AND** показує повідомлення на кшталт "Сесія завершена, увійдіть знову"

#### Scenario: Конкурентні запити під час refresh
- **GIVEN** клієнт зробив 3 паралельних запити до API, кожен з яких отримав `401 Expired JWT Token`
- **WHEN** клієнт виявляє це
- **THEN** клієнт виконує **тільки один** refresh-запит (через мютекс або синхронізовану чергу)
- **AND** дочікується завершення цього refresh
- **AND** повторює всі 3 оригінальні запити з новим access token
- **AND** жодний refresh token не використовується двічі

---

### Requirement: Зберігання токенів у Next.js фронтенді
Next.js фронтенд МАЄ зберігати токени в `HttpOnly` cookies — недоступних для JavaScript — щоб мінімізувати ризик XSS.

#### Scenario: Server Action логіну виставляє httpOnly cookies
- **GIVEN** користувач сабмітить форму логіну на фронтенді
- **WHEN** Server Action отримує credentials і викликає `/api/v1/auth/login` через server-to-server fetch
- **AND** API повертає `{ token, refresh_token }`
- **THEN** Server Action виставляє через `cookies().set()`:
  - cookie `access_token` з атрибутами `HttpOnly; Secure; SameSite=Strict; Path=/; Max-Age=3600`
  - cookie `refresh_token` з атрибутами `HttpOnly; Secure; SameSite=Strict; Path=/; Max-Age=2592000`
- **AND** клієнтський JavaScript **не може** прочитати ці cookies (`document.cookie` їх не містить)

#### Scenario: Подальші Server Action-запити автоматично використовують cookies
- **GIVEN** користувач залогінений (cookies виставлені)
- **WHEN** користувач викликає будь-яку Server Action, яка робить запит до API
- **THEN** Server Action читає `cookies().get('access_token')` на стороні Next.js сервера
- **AND** додає його як `Authorization: Bearer <token>` у запит до API
- **AND** клієнт ніколи не маніпулює токеном напряму

#### Scenario: Server Action виявляє протермінований токен і робить refresh
- **GIVEN** Server Action виконує запит до API і отримує `401 Expired JWT Token`
- **WHEN** Server Action виявляє цей стан
- **THEN** Server Action:
  1. Читає `refresh_token` з cookies
  2. Викликає `/api/v1/auth/refresh`
  3. Записує нову пару токенів через `cookies().set()` з тими ж атрибутами
  4. Повторює оригінальний запит до API
- **AND** користувач не бачить помилки

#### Scenario: Logout очищує cookies
- **WHEN** користувач натискає "Вийти" — викликається відповідна Server Action
- **THEN** Server Action видаляє обидві cookies через `cookies().delete('access_token')` і `cookies().delete('refresh_token')`
- **AND** (опційно) надсилає запит до API на анулювання refresh_token у БД
- **AND** редіректить на головну/публічну сторінку
