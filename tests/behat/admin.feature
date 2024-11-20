@report @report_feedback_tracker @rft_admin
Feature: As an admin I want to be able to hide a grade item from the report, I want to be able to set a grade item
  as summative and I will be able to set a manual feedback due date.
  As an admin
  Go to course administration -> Reports -> Feedback tracker

  Background:
    Given the following custom field exists for feedback tracker:
      | category  | CLC |
      | shortname | course_year |
      | name      | Course Year |
      | type      | text        |
    And the following "courses" exist:
      | fullname | shortname | format | customfield_course_year |
      | Course 1 | C1        | topics | ##now##%Y##             |
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
    And I click on "Feedback tracker" "link"
    Then "Report" "field" should exist in the "tertiary-navigation" "region"
    And I should see "Feedback tracker" in the "tertiary-navigation" "region"
    And I should see "Test quiz"
    And I should not see "Hidden from report"

    And I click on the "Edit" button in the "Test quiz" module
    Then I should see "Edit Test quiz"

    When I click on "Hidden from student report" "checkbox"
    And I press "Save"
    Then I should see "Hidden from report"

    And I log out

    # Check that the student cannot see the hidden item as well.
    When I am on the "Course 1" "course" page logged in as "student1"
    And I follow "Profile" in the user menu
    And I follow "Feedback tracker"
    Then I should see "Feedback tracker"
    And I should not see "Test quiz"
    And I should see "Test assignment"
    And I log out

    # Make item visible again.
    When I am on the "Course 1" "course" page logged in as "admin"
    When I navigate to "Reports" in current page administration
    And I click on "Feedback tracker" "link"
    Then I should see "Test quiz"
    And I should see "Hidden from report"
    And I click on the "Edit" button in the "Test quiz" module
    Then I should see "Edit Test quiz"

    When I click on "Hidden from student report" "checkbox"
    And I press "Save"
    Then I should not see "Hidden from report"

    And I log out

    # Check that the student can see the revealed item again as well.
    When I am on the "Course 1" "course" page logged in as "student1"
    And I follow "Profile" in the user menu
    And I follow "Feedback tracker"
    Then I should see "Feedback tracker"
    And I should see "Test assignment"
    And I should see "Test quiz"

  @javascript
  Scenario: As a course admin I can add additional information.
    Given I am on the "Course 1" "course" page logged in as "admin"

    When I navigate to "Reports" in current page administration
    And I click on "Feedback tracker" "link"

    And I click on the "Edit" button in the "Test quiz" module
    Then I should see "Edit Test quiz"
    And I click on "#behat-additional-details" "css_element"
    Then I should see "Contact"

    When I set the following fields to these values:
      | Method                  | Method test                   |
      | Contact                 | Contact test                  |
      | Additional information  | Addtitional information test  |

    And I press "Save"

    Then I should see "Method test"
    And I should see "Contact test"
    And I should see "Addtitional information test"

    And I log out

    # Check that a student can see the additional information.
    When I am on the "Course 1" "course" page logged in as "student1"
    And I follow "Profile" in the user menu
    And I follow "Feedback tracker"
    Then I should see "Method test"
    And I should see "Contact test"
    And I should see "Addtitional information test"
