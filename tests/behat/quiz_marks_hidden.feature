@report @report_feedback_tracker @rft_quiz_marks
Feature: In the student's report ensure quiz grades are shown in accordance with the quiz activity's review options

  Background:
    Given the following "custom field categories" exist:
      | name | component   | area   | itemid |
      | CLC  | core_course | course | 0      |
    And the following "custom fields" exist:
      | name        | shortname   | category | type |
      | Course Year | course_year | CLC      | text |
    And the following "courses" exist:
      | fullname | shortname | customfield_course_year |
      | Course 1 | C1        | ##now##%Y##             |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Student   | 1        | student1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
    And the following "question categories" exist:
      | contextlevel | reference | name           |
      | Course       | C1        | Test questions |
    And the following "questions" exist:
      | questioncategory | qtype     | name | questiontext    |
      | Test questions   | truefalse | TF1  | First question  |

  Scenario: A student should see their grade if the quiz review options allow marks to be seen
    Given the following "activities" exist:
      | activity | name   | course | marksduring | marksimmediately | marksopen | Formative or summative?                        |
      | quiz     | Quiz 1 | C1     | 1           | 1                | 1         | Formative - does not contribute to course mark |
    And quiz "Quiz 1" contains the following questions:
      | question | page | maxmark |
      | TF1      | 1    | 2.00    |
    And user "student1" has attempted "Quiz 1" with responses:
      | slot | response |
      |   1  | True     |
    And I am on the "Course 1" "course" page logged in as "student1"
    And I follow "Profile" in the user menu
    And I follow "Feedback tracker"
    Then I should see "Feedback tracker"
    And I should see "Quiz 1"
    And I should see "100/100"

  Scenario: A student should not see their grade if the quiz review options do not allow marks to be seen
    Given the following "activities" exist:
      | activity | name   | course | marksduring | marksimmediately | marksopen | Formative or summative?                        |
      | quiz     | Quiz 1 | C1     | 0           | 0                | 0         | Formative - does not contribute to course mark |
    And quiz "Quiz 1" contains the following questions:
      | question | page | maxmark |
      | TF1      | 1    | 2.00    |
    And user "student1" has attempted "Quiz 1" with responses:
      | slot | response |
      |   1  | True     |
    And I am on the "Course 1" "course" page logged in as "student1"
    And I follow "Profile" in the user menu
    And I follow "Feedback tracker"
    Then I should see "Feedback tracker"
    And I should see "Quiz 1"
    But I should see "Not released"
