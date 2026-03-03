@report @report_feedback_tracker @rft_guest_access
Feature: Guest access to Feedback tracker pages requires login
  In order to prevent guest users from triggering tracker internals
  As a site maintainer
  I need unauthenticated access to redirect to login

  Scenario: Logged out user visiting course report is redirected to login
    Given I log in as "admin"
    And I log out
    When I am on "/report/feedback_tracker/index.php?id=1"
    Then I should see "Log in"
    And I should not see "Exception - Class"
    And no feedback tracker report viewed event should exist for guest

  Scenario: Logged out user visiting student report is redirected to login
    Given I log in as "admin"
    And I log out
    When I am on "/report/feedback_tracker/student.php?id=1"
    Then I should see "Log in"
    And I should not see "Exception - Class"
    And no feedback tracker report viewed event should exist for guest
