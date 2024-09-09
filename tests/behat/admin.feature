@report @report_feedback_tracker @rft_admin
Feature: As an admin I want to be able to hide a grade item from the report, I want to be able to set a grade item
  as summative and I will be able to set a manual feedback due date.
  As an admin
  Go to course administration -> Reports -> Feedback Tracker

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
      | Assignment name           | Test assignment                                 |
      | Formative or summative?   | Formative - does not contribute to course mark  |
      | Description               | Test assignment description                     |
      | Maximum grade             | 100                                             |
    And I add a quiz activity to course "Course 1" section "3" and I fill the form with:
      | Name                      | Test quiz                                       |
      | Formative or summative?   | Formative - does not contribute to course mark  |
      | Description               | Test quiz description                           |
      | Grade to pass             | 8                                               |

  @javascript
  Scenario: A course admin or teacher should be able to hide and reveal an item from the report
    Given I am on the "Course 1" "course" page logged in as "admin"
    When I navigate to "Reports" in current page administration
    And I click on "Feedback Tracker" "link"
    Then "Report" "field" should exist in the "tertiary-navigation" "region"
    And I should see "Feedback Tracker" in the "tertiary-navigation" "region"
    And I should see "Test quiz"
    And I should not see "Hide from report"
    And I turn editing mode on
    Then I should see "Feedback Tracker" in the "tertiary-navigation" "region"
    And I should see "Test assignment"
    And I should see "Test quiz"
    And I should see "Hide from report"
    # Hide item from report.
    When I click on ".hiding_checkbox:nth-child(1)" "css_element"
    And I turn editing mode off
    Then I should see "Feedback Tracker" in the "tertiary-navigation" "region"
    And I should not see "Test assignment"
    And I should see "Test quiz"
    And I log out
    # Check that the student cannot see the hidden item as well.
    When I am on the "Course 1" "course" page logged in as "student1"
    And I follow "Profile" in the user menu
    And I follow "Feedback Tracker"
    Then I should see "Feedback Tracker"
    And I should not see "Test assignment"
    And I should see "Test quiz"
    And I log out
    # Make item visible again.
    When I am on the "Course 1" "course" page logged in as "admin"
    When I navigate to "Reports" in current page administration
    And I click on "Feedback Tracker" "link"
    And I turn editing mode on
    Then I should see "Test assignment"
    When I click on ".hiding_checkbox:nth-child(1)" "css_element"
    And I turn editing mode off
    And I should see "Test assignment"
    And I should see "Test quiz"
    And I log out
    # Check that the student can see the revealed item again as well.
    When I am on the "Course 1" "course" page logged in as "student1"
    And I follow "Profile" in the user menu
    And I follow "Feedback Tracker"
    Then I should see "Feedback Tracker"
    And I should see "Test assignment"
    And I should see "Test quiz"

  @javascript
  Scenario: As a course admin I can add additional information.
    Given I am on the "Course 1" "course" page logged in as "admin"
    When I navigate to "Reports" in current page administration
    And I click on "Feedback Tracker" "link"
    And I turn editing mode on
    When I click on ".fa-pencil:nth-child(2)" "css_element"
    Then I should see "Additional information"
    When I set the following fields to these values:
      | generalfeedback | Some general feedback |
      | gfurl           | https://www.ucl.ac.uk  |
    And I press "Save changes"
    Then I should see "Some general feedback"
    And I should see "https://www.ucl.ac.uk"
    And I log out
    # Check that a student can see the general feedback.
    When I am on the "Course 1" "course" page logged in as "student1"
    And I follow "Profile" in the user menu
    And I follow "Feedback Tracker"
    Then I should see "Feedback Tracker"
    And I should see "Some general feedback"
    And I should see "https://www.ucl.ac.uk"

  @javascript
  Scenario: As a course admin I can use filter to narrow down information.
    Given I am on the "Course 1" "course" page logged in as "admin"
    When I navigate to "Reports" in current page administration
    And I click on "Feedback Tracker" "link"
    Then I should see "Test assignment"
    And I should see "Test quiz"
    When I select "Quiz" from the "filtertype" dropdown
    Then I should not see "Test assignment" in the "#feedback_table" "css_element"
    And I should see "Test quiz" in the "#feedback_table" "css_element"
