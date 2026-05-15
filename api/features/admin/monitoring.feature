Feature: Admin monitoring toggle

  Scenario: Unauthenticated request is rejected
    Given I am not authenticated
    When I send a "GET" request to "/api/v1/admin/monitoring"
    Then the response status code should be 401

  Scenario: Regular user cannot read monitoring status
    Given I am authenticated as a regular user
    When I send a "GET" request to "/api/v1/admin/monitoring"
    Then the response status code should be 403

  Scenario: Admin can read monitoring status
    Given I am authenticated as admin
    When I send a "GET" request to "/api/v1/admin/monitoring"
    Then the response status code should be 200
    And the response should be in JSON
    And the JSON node "enabled" should exist

  Scenario: Admin can disable monitoring
    Given I am authenticated as admin
    When I send a "PATCH" request to "/api/v1/admin/monitoring" with body:
      """
      {"enabled": false}
      """
    Then the response status code should be 200
    And the JSON node "enabled" should be equal to "false"

  Scenario: Admin can re-enable monitoring
    Given I am authenticated as admin
    When I send a "PATCH" request to "/api/v1/admin/monitoring" with body:
      """
      {"enabled": true}
      """
    Then the response status code should be 200
    And the JSON node "enabled" should be equal to "true"

  Scenario: Regular user cannot toggle monitoring
    Given I am authenticated as a regular user
    When I send a "PATCH" request to "/api/v1/admin/monitoring" with body:
      """
      {"enabled": false}
      """
    Then the response status code should be 403

  Scenario: PATCH with missing field returns 400
    Given I am authenticated as admin
    When I send a "PATCH" request to "/api/v1/admin/monitoring" with body:
      """
      {}
      """
    Then the response status code should be 400

  Scenario: PATCH with non-boolean enabled returns 400
    Given I am authenticated as admin
    When I send a "PATCH" request to "/api/v1/admin/monitoring" with body:
      """
      {"enabled": "yes"}
      """
    Then the response status code should be 400
