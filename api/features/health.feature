Feature: Health check endpoint
    In order to monitor application health
    As an operator
    I need to check the health endpoint

    Scenario: Health endpoint returns OK
        When I send a "GET" request to "/api/health"
        Then the response status code should be 200
        And the response should be in JSON
        And the JSON node "status" should be equal to "ok"
        And the JSON node "services.database" should be equal to "connected"
