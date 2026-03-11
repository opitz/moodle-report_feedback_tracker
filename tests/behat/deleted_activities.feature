@report @report_feedback_tracker @rft_deleted_activities
Feature: Deleted course modules should not show in feedback tracker reports
  As a course admin
  In order to avoid stale report entries
  I need deleted activities to be excluded from course and site reports

  Background:
    Given the following "custom field categories" exist:
      | name | component   | area   | itemid |
      | CLC  | core_course | course | 0      |
    And the following "custom fields" exist:
      | name        | shortname   | category | type |
      | Course Year | course_year | CLC      | text |
    And the following "courses" exist:
      | fullname | shortname | format | customfield_course_year |
      | Course 1 | C1        | topics | ##now##%Y##             |
    And the following "users" exist:
      | username    | firstname | lastname | email                    |
      | courseadmin | Course    | Admin    | courseadmin@example.com  |
    And the following "course enrolments" exist:
      | user        | course | role           |
      | courseadmin | C1     | editingteacher |
    And the following config values are set as admin:
      | sitereport | 1 | report_feedback_tracker |
    And I log in as "admin"
    And I add a assign activity to course "Course 1" section "2" and I fill the form with:
      | Assignment name         | Test assignment                               |
      | Formative or summative? | Summative - contributes to the overall mark   |
      | Description             | Test assignment description                    |
      | Maximum grade           | 100                                            |
    And I add a quiz activity to course "Course 1" section "3" and I fill the form with:
      | Name                    | Test quiz                                      |
      | Formative or summative? | Summative - contributes to the overall mark    |
      | Description             | Test quiz description                          |
      | Grade to pass           | 8                                              |

  @javascript
  Scenario: A deleted activity should no longer show in course and site reports for course admins
    Given I am on the "Course 1" "course" page logged in as "courseadmin"
    And I delete "Test quiz" activity

    When I navigate to "Reports" in current page administration
    And I click on "Feedback tracker" "link"
    Then I should see "Course report"
    And I should see "Test assignment"
    And I should not see "Test quiz"

    When I am on "/report/feedback_tracker/site.php?year=##now##%Y##&term=4" page
    Then I should see "Feedback tracker site report"
    And I should see "Course 1"
    And I should see "Test assignment"
    And I should not see "Test quiz"
