@report @report_feedback_tracker
Feature: As an admin I want to be able to hide a grade item from the report, I want to be able to set a grade item
  as summative and I will be able to set a manual feedback due date.
  As an admin
  Go to course administration -> Reports -> Feedback tracker report

  Background:
    Given the following "courses" exist:
      | fullname | shortname  | category  | groupmode |
      | Course 1 | C1         | 0         | 1         |
    And the following "users" exist:
      | username | firstname  | lastname  | email                 |
      | teacher1 | teacher    | 1         | teacher1@example.com  |
      | student1 | Student    | 1         | student1@example.com  |
    And the following "course enrolments" exist:
      | user      | course  | role            |
      | teacher1  | C1      | editingteacher  |
      | student1  | C1      | student         |
    And I log in as "admin"
    And I add a assign activity to course "Course 1" section "2" and I fill the form with:
      | Assignment name | Test assignment               |
      | Description     | Test assignment description   |
      | Maximum grade   | 100                       |
    And I add a quiz activity to course "Course 1" section "3" and I fill the form with:
      | Name            | Test quiz               |
      | Description     | Test quiz description   |
      | Grade to pass   | 8                       |
#    And I press "Save and return to course"
#    And I click on "Save and return to course" "button"

  @javascript
  Scenario: For an admin the selector should be available in course feedback report report page
    Given I am on the "Course 1" "course" page logged in as "admin"
    When I navigate to "Reports" in current page administration
    And I click on "Feedback tracker report" "link"
    Then "Report" "field" should exist in the "tertiary-navigation" "region"
    And I should see "Feedback tracker report" in the "tertiary-navigation" "region"
    And I should see "Test quiz"
    And I should not see "Hide from report"
    And I turn editing mode on
    Then I should see "Feedback tracker report" in the "tertiary-navigation" "region"
    And I should see "Test assignment"
    And I should see "Test quiz"
    And I should see "Hide from report"
    When I click on ".hiding_checkbox:nth-child(1)" "css_element"
    And I turn editing mode off
    Then I should see "Feedback tracker report" in the "tertiary-navigation" "region"
    And I should not see "Test assignment"
    And I should see "Test quiz"
    And I turn editing mode on
    Then I should see "Test assignment"
    When I click on ".hiding_checkbox:nth-child(1)" "css_element"
    And I turn editing mode off
    And I should see "Test assignment"
    And I should see "Test quiz"
