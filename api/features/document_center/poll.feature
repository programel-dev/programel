Feature: PollDocumentCenter handler stores HTML and notifies on state transition

  Scenario: HTML response without alert is saved to raw_html
    Given the fetcher will return an HTML response without alert
    When the poll document center handler runs
    Then the raw html table should have 1 rows
    And the snapshot table should have 1 rows
    And the snapshot parser version should be "cloudflare-bypass-v1"
    And the raw html row alertPresent should be "false"
    And the snapshot payload alertPresent should be "false"

  Scenario: HTML response with alert is saved to raw_html
    Given the fetcher will return an HTML response with alert
    When the poll document center handler runs
    Then the raw html table should have 1 rows
    And the raw html row alertPresent should be "true"
    And the snapshot payload alertPresent should be "true"

  Scenario: HTTP error saves only a snapshot with http_error status
    Given the fetcher will return an HTTP error with status 503
    When the poll document center handler runs
    Then the raw html table should have 0 rows
    And the snapshot table should have 1 rows
    And the snapshot status should be "http_error"

  Scenario: State transition occupied to free triggers broadcast
    Given the previous snapshot had alertPresent "true"
    And the fetcher will return an HTML response without alert
    When the poll document center handler runs
    Then a broadcast slots available message should be dispatched

  Scenario: First poll with free slots does not broadcast
    Given the fetcher will return an HTML response without alert
    When the poll document center handler runs
    Then no broadcast slots available message should be dispatched

  Scenario: Consecutive free polls do not broadcast again
    Given the previous snapshot had alertPresent "false"
    And the fetcher will return an HTML response without alert
    When the poll document center handler runs
    Then no broadcast slots available message should be dispatched

  Scenario: Old raw_html rows are deleted before insert (rolling retention)
    Given there is a raw html row older than 8 hours
    And the fetcher will return an HTML response without alert
    When the poll document center handler runs
    Then the raw html table should have 1 rows

  Scenario: Slot scraping enabled persists slot row and broadcast carries slot data
    Given slot scraping is enabled
    And the previous snapshot had alertPresent "true"
    And the fetcher will return an HTML response without alert
    And the slot scraper will return slots for date "20.05.2026"
    When the poll document center handler runs
    Then the slot table should have 1 rows
    And a broadcast slots available message should be dispatched
    And the broadcast message should carry slot data

  Scenario: Slot scraping disabled does not call Playwright and broadcast has no slot data
    Given the previous snapshot had alertPresent "true"
    And the fetcher will return an HTML response without alert
    When the poll document center handler runs
    Then the slot table should have 0 rows
    And a broadcast slots available message should be dispatched
