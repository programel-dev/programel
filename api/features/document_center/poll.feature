Feature: PollDocumentCenter handler stores responses in the correct table

  Scenario: Playwright response is saved to slot and raw_html tables
    Given the fetcher will return a Playwright JSON response with 2 slots
    When the poll document center handler runs
    Then the slot table should have 1 rows
    And the raw html table should have 1 rows
    And the snapshot table should have 1 rows
    And the snapshot parser version should be "playwright-slot-v1"
    And the slot row should have 2 slots
    And the raw html row alertPresent should be "false"
    And the snapshot payload alertPresent should be "false"

  Scenario: Playwright response with empty slots marks alert as present
    Given the fetcher will return a Playwright JSON response with 0 slots
    When the poll document center handler runs
    Then the slot table should have 1 rows
    And the slot row should have 0 slots
    And the raw html table should have 1 rows
    And the raw html row alertPresent should be "true"
    And the snapshot payload alertPresent should be "true"

  Scenario: HTML response without alert is saved to raw_html table only
    Given the fetcher will return an HTML response without alert
    When the poll document center handler runs
    Then the slot table should have 0 rows
    And the raw html table should have 1 rows
    And the snapshot table should have 1 rows
    And the snapshot parser version should be "cloudflare-bypass-v1"
    And the raw html row alertPresent should be "false"
    And the snapshot payload alertPresent should be "false"

  Scenario: HTML response with alert is saved to raw_html table
    Given the fetcher will return an HTML response with alert
    When the poll document center handler runs
    Then the slot table should have 0 rows
    And the raw html table should have 1 rows
    And the raw html row alertPresent should be "true"
    And the snapshot payload alertPresent should be "true"

  Scenario: HTTP error saves only a snapshot with http_error status
    Given the fetcher will return an HTTP error with status 503
    When the poll document center handler runs
    Then the slot table should have 0 rows
    And the raw html table should have 0 rows
    And the snapshot table should have 1 rows
    And the snapshot status should be "http_error"

  Scenario: Old slot and raw_html rows are deleted before Playwright insert (rolling retention)
    Given there is a slot row older than 8 hours
    And there is a raw html row older than 8 hours
    And the fetcher will return a Playwright JSON response with 1 slots
    When the poll document center handler runs
    Then the slot table should have 1 rows
    And the raw html table should have 1 rows

  Scenario: Old raw_html rows are deleted before FlareSolverr insert (rolling retention)
    Given there is a raw html row older than 8 hours
    And the fetcher will return an HTML response without alert
    When the poll document center handler runs
    Then the raw html table should have 1 rows
