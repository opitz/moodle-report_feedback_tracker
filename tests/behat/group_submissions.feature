@report @report_feedback_tracker @rft_group_submissions
Feature: Course admin sees correct missing grades count for assignment group submissions
  In order to monitor marking progress accurately
  As a course admin
  I need group submissions to report missing grades correctly

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
      | teacher1 | Teacher   | 1        | teacher1@example.com |
      | student1 | Student   | 1        | student1@example.com |
      | student2 | Student   | 2        | student2@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following "groups" exist:
      | name    | course | idnumber |
      | Group A | C1     | GA       |
      | Group B | C1     | GB       |
    And the following "group members" exist:
      | user     | group |
      | student1 | GA    |
      | student2 | GB    |
    And the following "activity" exists:
      | activity                            | assign          |
      | name                                | Team assignment |
      | course                              | C1              |
      | assignsubmission_onlinetext_enabled | 1               |
      | assignfeedback_comments_enabled     | 0               |
      | submissiondrafts                    | 0               |
      | teamsubmission                      | 1               |
      | requireallteammemberssubmit         | 0               |
      | assessment_type                     | 1               |

  @javascript
  Scenario: One graded group and one ungraded group should show 1 missing grade
    Given the following team submissions exist for assignment "Team assignment":
      | group   |
      | Group A |
      | Group B |
    And the following "grade grades" exist:
      | gradeitem        | user     | grade |
      | Team assignment  | student1 | 75    |

    When I am on the "Course 1" "course" page logged in as "admin"
    And I navigate to "Reports" in current page administration
    And I click on "Feedback tracker" "link"
    Then I should see "Team assignment"
    And I should see "1 require marking"
    And I should not see "2 require marking"

  @javascript
  Scenario: Both graded groups should show 100 percent grading progress
    Given the following team submissions exist for assignment "Team assignment":
      | group   |
      | Group A |
      | Group B |
    And the following "grade grades" exist:
      | gradeitem        | user     | grade |
      | Team assignment  | student1 | 75    |
      | Team assignment  | student2 | 80    |

    When I am on the "Course 1" "course" page logged in as "admin"
    And I navigate to "Reports" in current page administration
    And I click on "Feedback tracker" "link"
    Then I should see "Team assignment"
    And I should not see "1 require marking"
    And I should not see "2 require marking"
    And I should see "100%"
