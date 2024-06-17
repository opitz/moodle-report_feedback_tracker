@report @report_feedback_tracker
Feature: In a course administration page, navigate through report page, test for feedback tracker report page
  In order to navigate through report page
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
    And I add a quiz activity to course "Course 1" section "3" and I fill the form with:
      | Name          | Test quiz               |
      | Description   | Test quiz description   |
      | Grade to pass | 8                       |
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
    And I am on "Course 1" course homepage with editing mode on
    When I navigate to "Reports" in current page administration
    And I click on "Feedback tracker report" "link"
    Then I should see "Feedback tracker report" in the "tertiary-navigation" "region"
    And I should see "Test quiz"
    And I should see "Hide from report"
    And I log out

  @javascript
  Scenario: For a teacher the selector should be available in course feedback report report page
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    When I navigate to "Reports" in current page administration
    And I click on "Feedback tracker report" "link"
    Then "Report" "field" should exist in the "tertiary-navigation" "region"
    And I should see "Feedback tracker report" in the "tertiary-navigation" "region"
    And I should see "Test quiz"

  @javascript
  Scenario: For a student the feedback tracker report should be available in the profile.
    Given I am on the "Course 1" "course" page logged in as "student1"
    And I follow "Profile" in the user menu
    And I follow "Feedback tracker"
    Then I should see "Feedback tracker report"
    And I should see "Feedback due date"
    And I should see "Test quiz"
