# Workflow роботи з watch-підписками (equeue)

Цей документ описує **повний життєвий цикл watch-підписок** — користувацьких "стежень" за конкретною комбінацією послуги і дат у системі електронної черги (Munich consulate equeue). Користувач створює watch, система періодично перевіряє чи з'явилися вільні слоти, і коли з'являються — надсилає нотифікацію в Telegram.

API для watches побудовано на **API Platform** і виставляє стандартні REST-операції (GET, POST, PATCH, DELETE). Кожен watch належить конкретному користувачу (`User`). Доступ до чужих watch заборонений на рівні **security voter**, а не на рівні фільтру в запиті — це означає, що чужий ID повертає `403 Forbidden`, а не `404 Not Found`.

---

## Загальна картина

```
┌─────────┐                              ┌──────────┐                ┌──────────┐
│Користувач│                             │   API    │                │  Worker  │
└────┬────┘                              └────┬─────┘                └────┬─────┘
     │                                        │                           │
     │ 1. POST /equeue_watches                │                           │
     │    {serviceCode, dateFrom, dateTo}     │                           │
     │───────────────────────────────────────▶│                           │
     │                                        │                           │
     │ 2. 201 Created + watch                 │                           │
     │◀───────────────────────────────────────│                           │
     │                                        │                           │
     │                                        │   періодично (кожні 5хв)  │
     │                                        │◀──────────────────────────│
     │                                        │   - тягне HTML equeue     │
     │                                        │   - парсить слоти         │
     │                                        │   - матчить з watches     │
     │                                        │   - при збігу → Telegram  │
     │                                        │                           │
     │ 3. GET /equeue_watches                 │                           │
     │───────────────────────────────────────▶│                           │
     │                                        │                           │
     │ 4. список тільки моїх watches          │                           │
     │◀───────────────────────────────────────│                           │
     │                                        │                           │
     │ 5. PATCH /equeue_watches/{id}          │                           │
     │    {active: false}    (приховую)       │                           │
     │───────────────────────────────────────▶│                           │
     │                                        │                           │
     │ 6. 200 OK                              │                           │
     │◀───────────────────────────────────────│                           │
     │                                        │                           │
     │ 7. DELETE /equeue_watches/{id}         │                           │
     │───────────────────────────────────────▶│                           │
     │                                        │                           │
     │ 8. 204 No Content                      │                           │
     │◀───────────────────────────────────────│                           │
```

**Модель `EqueueWatch`:**
- `id` — int, автогенерований
- `user` — ManyToOne до `User` (виставляється сервером, **не** із запиту)
- `serviceCode` — string, код послуги (наприклад, `visa_appointment`)
- `dateFrom`, `dateTo` — date, діапазон дат, які цікавлять користувача
- `active` — bool, чи активний watch (можна тимчасово відключити без видалення)
- `createdAt`, `updatedAt` — datetime, технічні поля

**Ключові інваріанти безпеки:**
1. Користувач **не може** бачити чужі watch (ні через list, ні через get-by-id)
2. Користувач **не може** створити watch для іншого користувача (поле `user` ігнорується якщо передане в тілі)
3. Користувач **не може** редагувати чи видалити чужий watch — це повертає `403`, **не** `404`, щоб не давати інформацію про існування ID

---

## ADDED Requirements

### Requirement: Створення watch-підписки
Аутентифікований користувач МАЄ змогу створити watch-підписку через `POST /api/v1/equeue_watches`. Watch автоматично прив'язується до того користувача, чий JWT передано.

#### Scenario: Успішне створення watch
- **GIVEN** користувач `Alice` залогінений (має валідний access token)
- **WHEN** клієнт надсилає `POST /api/v1/equeue_watches` з заголовком `Authorization: Bearer <alice_token>` і тілом:
  ```json
  {
    "serviceCode": "visa_appointment",
    "dateFrom": "2026-06-01",
    "dateTo": "2026-07-01"
  }
  ```
- **THEN** сервер відповідає `HTTP 201 Created` з тілом:
  ```json
  {
    "@id": "/api/v1/equeue_watches/42",
    "id": 42,
    "serviceCode": "visa_appointment",
    "dateFrom": "2026-06-01",
    "dateTo": "2026-07-01",
    "active": true,
    "createdAt": "2026-05-15T10:30:00+00:00",
    "updatedAt": "2026-05-15T10:30:00+00:00"
  }
  ```
- **AND** у БД створено рядок `equeue_watch` з `user_id = alice.id`
- **AND** `active` за замовчуванням `true` — watch одразу починає працювати
- **AND** у відповіді **немає** поля `user` (внутрішня деталь, не показується)

#### Scenario: Спроба створити watch від імені іншого користувача
- **GIVEN** користувач `Alice` залогінений
- **WHEN** клієнт надсилає `POST /api/v1/equeue_watches` з тілом, у якому додано підроблене поле:
  ```json
  {
    "user": "/api/v1/users/2",
    "serviceCode": "visa_appointment",
    "dateFrom": "2026-06-01",
    "dateTo": "2026-07-01"
  }
  ```
- **THEN** сервер **ігнорує** поле `user` і створює watch для `Alice` (на основі JWT)
- **AND** повертає `HTTP 201` зі звичайним тілом (без `user` у відповіді)

#### Scenario: Створення watch без авторизації
- **WHEN** клієнт надсилає `POST /api/v1/equeue_watches` без заголовка `Authorization`
- **THEN** сервер відповідає `HTTP 401`
- **AND** жоден запис у БД не створюється

---

### Requirement: Валідація вхідних даних watch
Сервер МАЄ валідувати всі поля тіла запиту перед збереженням і повертати читабельні повідомлення про помилки у форматі API Platform.

#### Scenario: Відсутнє обов'язкове поле `serviceCode`
- **GIVEN** користувач залогінений
- **WHEN** клієнт надсилає `POST /api/v1/equeue_watches` з тілом без `serviceCode`:
  ```json
  { "dateFrom": "2026-06-01", "dateTo": "2026-07-01" }
  ```
- **THEN** сервер відповідає `HTTP 422 Unprocessable Entity` з тілом:
  ```json
  {
    "violations": [
      {
        "propertyPath": "serviceCode",
        "message": "This value should not be blank."
      }
    ]
  }
  ```

#### Scenario: Невалідний формат дати
- **WHEN** клієнт надсилає тіло з `"dateFrom": "не-дата"`
- **THEN** сервер відповідає `HTTP 400` (помилка десеріалізації)
- **AND** тіло містить опис проблеми з полем `dateFrom`

#### Scenario: dateFrom пізніше за dateTo
- **WHEN** клієнт надсилає тіло, де `dateFrom = "2026-07-01"` і `dateTo = "2026-06-01"`
- **THEN** сервер відповідає `HTTP 422` з тілом:
  ```json
  {
    "violations": [
      {
        "propertyPath": "dateTo",
        "message": "dateTo must be greater than or equal to dateFrom."
      }
    ]
  }
  ```

#### Scenario: Невідомий serviceCode
- **WHEN** клієнт надсилає `"serviceCode": "невідомий_код"` (не зі списку дозволених)
- **THEN** сервер відповідає `HTTP 422` з тілом, що пояснює які значення дозволені

---

### Requirement: Перегляд власних watch-підписок
Аутентифікований користувач МАЄ змогу отримати список тільки **своїх** watch через `GET /api/v1/equeue_watches`, з можливістю фільтрації і пагінації.

#### Scenario: Список повертає тільки watch поточного користувача
- **GIVEN** користувач `Alice` має 3 watch у БД
- **AND** користувач `Bob` має 5 watch у БД
- **WHEN** клієнт надсилає `GET /api/v1/equeue_watches` з токеном `Alice`
- **THEN** сервер відповідає `HTTP 200` з тілом, що містить рівно 3 watch
- **AND** жоден із watch `Bob` не присутній у відповіді
- **AND** фільтрація відбувається **на рівні Doctrine extension** (а не у відповіді) — тобто SQL-запит уже містить `WHERE user_id = alice.id`

#### Scenario: Список з пагінацією
- **GIVEN** користувач має 50 watch
- **WHEN** клієнт надсилає `GET /api/v1/equeue_watches?page=2&itemsPerPage=20`
- **THEN** сервер повертає `HTTP 200` з 20 watch (елементи 21–40)
- **AND** тіло містить `hydra:totalItems: 50` і навігаційні URL `hydra:view`

#### Scenario: Фільтр за активністю
- **WHEN** клієнт надсилає `GET /api/v1/equeue_watches?active=true`
- **THEN** сервер повертає тільки активні watch користувача
- **AND** деактивовані watch не включаються

#### Scenario: Список без авторизації
- **WHEN** клієнт надсилає `GET /api/v1/equeue_watches` без JWT
- **THEN** сервер відповідає `HTTP 401`

---

### Requirement: Отримання одного watch за ID
Аутентифікований користувач МАЄ змогу отримати деталі одного власного watch через `GET /api/v1/equeue_watches/{id}`.

#### Scenario: Отримання власного watch
- **GIVEN** користувач `Alice` має watch `id=42`
- **WHEN** клієнт надсилає `GET /api/v1/equeue_watches/42` з токеном `Alice`
- **THEN** сервер відповідає `HTTP 200` з повним об'єктом watch

#### Scenario: Watch не існує в БД
- **GIVEN** користувач залогінений
- **WHEN** клієнт надсилає `GET /api/v1/equeue_watches/99999` (ID не існує)
- **THEN** сервер відповідає `HTTP 404`

#### Scenario: Watch належить іншому користувачу
- **GIVEN** користувач `Alice` залогінений
- **AND** у БД існує watch `id=42`, що належить `Bob`
- **WHEN** клієнт надсилає `GET /api/v1/equeue_watches/42` з токеном `Alice`
- **THEN** сервер відповідає `HTTP 403 Forbidden` (НЕ 404)
- **AND** тіло містить:
  ```json
  { "code": 403, "message": "Access Denied." }
  ```
- **AND** `Alice` не отримує жодної інформації про вміст чужого watch

> **Чому 403, а не 404:** на перший погляд `404` для чужого watch здається більш безпечним (приховує існування ID). Але в системі, де ID послідовні (auto-increment), користувач і так може вгадати, що ID існує. `403` чесніше і простіше реалізувати через security voter.

---

### Requirement: Оновлення watch (часткове редагування)
Аутентифікований користувач МАЄ змогу редагувати власний watch через `PATCH /api/v1/equeue_watches/{id}`. Найчастіший use case — деактивація без видалення.

#### Scenario: Деактивація watch
- **GIVEN** користувач `Alice` має активний watch `id=42`
- **WHEN** клієнт надсилає `PATCH /api/v1/equeue_watches/42` з заголовком `Content-Type: application/merge-patch+json` і тілом:
  ```json
  { "active": false }
  ```
- **THEN** сервер відповідає `HTTP 200` з оновленим об'єктом, де `active: false`
- **AND** у БД полю `active` встановлено `false`
- **AND** worker більше не враховує цей watch при матчингу слотів

#### Scenario: Реактивація watch
- **GIVEN** watch `id=42` був раніше деактивований
- **WHEN** клієнт надсилає `PATCH /api/v1/equeue_watches/42` з тілом `{"active": true}`
- **THEN** сервер відповідає `HTTP 200` з `active: true`
- **AND** worker знову починає враховувати цей watch

#### Scenario: Зміна діапазону дат
- **WHEN** клієнт надсилає `PATCH /api/v1/equeue_watches/42` з тілом:
  ```json
  { "dateFrom": "2026-08-01", "dateTo": "2026-09-01" }
  ```
- **THEN** сервер оновлює тільки ці поля
- **AND** інші поля (`serviceCode`, `active`) залишаються незмінними

#### Scenario: Спроба редагувати чужий watch
- **GIVEN** користувач `Alice` залогінений
- **AND** у БД існує watch `id=42`, що належить `Bob`
- **WHEN** клієнт надсилає `PATCH /api/v1/equeue_watches/42` з токеном `Alice`
- **THEN** сервер відповідає `HTTP 403`
- **AND** жодних змін у БД не відбувається

#### Scenario: Спроба змінити власника через PATCH
- **WHEN** клієнт надсилає `PATCH /api/v1/equeue_watches/42` з тілом, що містить `"user": "/api/v1/users/2"`
- **THEN** сервер **ігнорує** це поле — `user` незмінний після створення
- **AND** повертає `HTTP 200` з оновленими лише дозволеними полями

---

### Requirement: Видалення watch
Аутентифікований користувач МАЄ змогу повністю видалити власний watch через `DELETE /api/v1/equeue_watches/{id}`.

#### Scenario: Успішне видалення
- **GIVEN** користувач `Alice` має watch `id=42`
- **WHEN** клієнт надсилає `DELETE /api/v1/equeue_watches/42` з токеном `Alice`
- **THEN** сервер відповідає `HTTP 204 No Content` (тіла немає)
- **AND** watch видалено з БД (hard delete)
- **AND** наступний `GET /api/v1/equeue_watches/42` повертає `HTTP 404`

#### Scenario: Видалення вже видаленого watch
- **GIVEN** watch `id=42` вже видалено
- **WHEN** клієнт повторно надсилає `DELETE /api/v1/equeue_watches/42`
- **THEN** сервер відповідає `HTTP 404`

#### Scenario: Спроба видалити чужий watch
- **GIVEN** користувач `Alice` залогінений
- **AND** у БД існує watch `id=42`, що належить `Bob`
- **WHEN** клієнт надсилає `DELETE /api/v1/equeue_watches/42` з токеном `Alice`
- **THEN** сервер відповідає `HTTP 403`
- **AND** watch `Bob` залишається у БД

#### Scenario: Видалення без авторизації
- **WHEN** клієнт надсилає `DELETE /api/v1/equeue_watches/42` без JWT
- **THEN** сервер відповідає `HTTP 401`

---

### Requirement: Каскадне видалення нотифікацій разом з watch
При видаленні watch система МАЄ автоматично видалити всі пов'язані `EqueueNotification` записи (історію дедуплікації нотифікацій), щоб не залишати "сирітські" дані.

#### Scenario: Каскадне видалення notifications
- **GIVEN** watch `id=42` має 15 пов'язаних `equeue_notification` записів у БД
- **WHEN** клієнт надсилає `DELETE /api/v1/equeue_watches/42`
- **THEN** сервер видаляє watch і всі 15 notifications в одній транзакції
- **AND** відповідає `HTTP 204`
