@report @report_feedback_tracker @rft_customdates
Feature: As an admin I want to be able to set a custom dates to a grade item

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
      | username | firstname | lastname | email                |
      | teacher1 | teacher   | 1        | teacher1@example.com |
      | student1 | Student   | 1        | student1@example.com |
    And the following "course enrolments" exist:
      | user      | course | role           |
      | teacher1  | C1     | editingteacher |
      | student1  | C1     | student        |
    And I log in as "admin"
    And I add a assign activity to course "Course 1" section "2" and I fill the form with:
      | Assignment name         | Test assignment                                |
      | Formative or summative? | Formative - does not contribute to course mark |
      | Maximum grade           | 100                                            |
    And I add a quiz activity to course "Course 1" section "3" and I fill the form with:
      | Name                    | Test quiz                                         |
      | Formative or summative? | Summative - counts towards the final module mark  |
      | Grade to pass           | 8                                                 |

  @javascript
  Scenario: Adding custom feedback due date
    Given I am on the "Course 1" "course" page logged in as "admin"
    When I navigate to "Reports" in current page administration
    And I click on "Feedback tracker" "link"
    Then "Report" "field" should exist in the "tertiary-navigation" "region"
    And I should see "Feedback tracker" in the "tertiary-navigation" "region"
    And I should see "Test quiz"

    When I click on the "Edit" button in the "Test quiz" module
    Then I should see "Formative - does not contribute to course mark"
    And I should not see "Please give a reason for the change in feedback due date"
    When I click on "Add custom feedback due date" "checkbox"
    Then I should see "Please give a reason for the change in feedback due date"

    # Saving w/o a reason should not work.
    And I click on "Save" "button" in the "Edit Test quiz" "dialogue"
    Then I should not see "Set manually"
    And I should see "Please give a reason for the change in feedback due date"

    # Saving w/o a date entry should not work.
    When I set the field "Please give a reason for the change in feedback due date." to "A test reason"
    And I click on "Save" "button" in the "Edit Test quiz" "dialogue"
    Then I should not see "Set manually"
    And I should see "Please give a reason for the change in feedback due date"

    # Saving with a reason and a date should work.
    When I set the field "New feedback due date" to "2024-12-24"
    And I click on "Save" "button" in the "Edit Test quiz" "dialogue"
    Then I should see "Set manually"

    # Custom feedback due data should be maintained in editing form.
    When I click on the "Edit" button in the "Test quiz" module
    Then I should see "Formative - does not contribute to course mark"
    And I should see "Please give a reason for the change in feedback due date."
    Then the field "Please give a reason for the change in feedback due date." matches value "A test reason"

    # When I hide the custom feedback due data and show it again w/o saving the data should be preserved.
    When I click on "Add custom feedback due date" "checkbox"
    Then I should not see "Please give a reason for the change in feedback due date"
    When I click on "Add custom feedback due date" "checkbox"
    Then I should see "Please give a reason for the change in feedback due date"
    Then the field "Please give a reason for the change in feedback due date." matches value "A test reason"

    # When I hide the custom feedback due data and save the data is removed.
    When I click on "Add custom feedback due date" "checkbox"
    Then I should not see "Please give a reason for the change in feedback due date"

    And I click on "Save" "button" in the "Edit Test quiz" "dialogue"
    Then I should not see "Set manually"

  @javascript
  Scenario: Adding custom feedback date
    Given I am on the "Course 1" "course" page logged in as "admin"
    When I navigate to "Reports" in current page administration
    And I click on "Feedback tracker" "link"
    Then "Report" "field" should exist in the "tertiary-navigation" "region"
    And I should see "Feedback tracker" in the "tertiary-navigation" "region"
    And I should see "Test quiz"

    When I click on the "Edit" button in the "Test quiz" module
    And I click on "Add custom feedback released date" "checkbox"
    And I set the field "New feedback released date" to "##now - 2 days##"
    And I click on "Save" "button" in the "Edit Test quiz" "dialogue"
    And I log out

    # Student should see "Released" badge
    When I am on the "Course 1" "course" page logged in as "student1"
    And I follow "Profile" in the user menu
    And I follow "Feedback tracker"
    Then I should see "Feedback tracker"
    And I follow "All"
    And I should see "Test quiz"
    And I should see "Released"
