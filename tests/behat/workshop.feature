@report @report_feedback_tracker @rft_workshop
Feature: As a student I want to see badges for my workshop assessments.

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
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | One       | Student  | student1@example.com |
      | student2 | Two       | Student  | student2@example.com |

    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following "activities" exist:
      | activity | name       | course | submissiontypetext |
      | workshop | Workshop 1 | C1     | 2                  |

    And the following config values are set as admin:
      | supportworkshop  | 1     | report_feedback_tracker |

    And I am on the "Workshop 1" "workshop activity" page logged in as teacher1
    And I edit assessment form in workshop "Workshop 1" as:
      | id_description__idx_0_editor | Aspect1 |
    And I change phase in workshop "Workshop 1" to "Submission phase"

  Scenario: Student submitted and assessed
    # Create workshop submissions.
    Given I am on the "Workshop 1" "workshop activity" page logged in as student1
    And I add a submission in workshop "Workshop 1" as:
      | Title              | Submission 1         |
      | Submission content | Submission 1 content |
    And I am on the "Workshop 1" "workshop activity" page logged in as student2
    And I add a submission in workshop "Workshop 1" as:
      | Title              | Submission 2         |
      | Submission content | Submission 2 content |
    And I am on the "Workshop 1" "workshop activity" page logged in as teacher1
    And I change phase in workshop "Workshop 1" to "Assessment phase"
    # Allocate and assess submissions.
    And I allocate submissions in workshop "Workshop 1" as:
      | Participant | Reviewer    |
      | One Student | Two Student |
      | Two Student | One Student |
    And I am on the "Workshop 1" "workshop activity" page logged in as student2
    And I assess submission "One" in workshop "Workshop 1" as:
      | grade__idx_0            | 8 / 10            |
      | peercomment__idx_0      | Great job!        |

    # Check Feedback Tracker student report
    And I follow "Profile" in the user menu
    And I follow "Feedback tracker"
    And I follow "All"
    Then I should see "Submitted"
    And I should see "All assessed"

  Scenario: Student submitted only
    # Create workshop submissions.
    Given I am on the "Workshop 1" "workshop activity" page logged in as student1
    And I add a submission in workshop "Workshop 1" as:
      | Title              | Submission 1         |
      | Submission content | Submission 1 content |
    And I am on the "Workshop 1" "workshop activity" page logged in as teacher1
    And I change phase in workshop "Workshop 1" to "Assessment phase"
    # Allocate and assess submissions.
    And I allocate submissions in workshop "Workshop 1" as:
      | Participant | Reviewer    |
      | One Student | Two Student |

    # Check Feedback Tracker student report
    When I am on the "Course 1" "course" page logged in as "student1"
    And I follow "Profile" in the user menu
    And I follow "Feedback tracker"
    And I follow "All"
    Then I should see "Submitted"
    But I should not see "All assessed"

  Scenario: Student assessed but did not submit
    # Create workshop submissions.
    Given I am on the "Workshop 1" "workshop activity" page logged in as student1
    And I add a submission in workshop "Workshop 1" as:
      | Title              | Submission 1         |
      | Submission content | Submission 1 content |
    And I am on the "Workshop 1" "workshop activity" page logged in as teacher1
    And I change phase in workshop "Workshop 1" to "Assessment phase"
    # Allocate and assess submissions.
    And I allocate submissions in workshop "Workshop 1" as:
      | Participant | Reviewer    |
      | One Student | Two Student |
    And I am on the "Workshop 1" "workshop activity" page logged in as student2
    And I assess submission "One" in workshop "Workshop 1" as:
      | grade__idx_0            | 8 / 10            |
      | peercomment__idx_0      | Great job!        |

    # Check Feedback Tracker student report
    And I follow "Profile" in the user menu
    And I follow "Feedback tracker"
    And I follow "All"
    Then I should see "All assessed"
    But I should not see "Submitted"
