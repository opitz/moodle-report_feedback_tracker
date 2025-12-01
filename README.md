# Feedback Tracker #

This plugin provides information about activity feedback for marker and students.


## User Documentation
- [Digital Education Team Blog](https://blogs.ucl.ac.uk/digital-education/2024/09/10/feedback-tracker-report/)
- [Teaching & Learning](https://www.ucl.ac.uk/teaching-learning/news/2024/nov/guidance-available-new-feedback-tracker-report)

## Getting Started
1) To be able to calculate the feedback due date correctly the plugin needs to know the closure days for Easter and Christmas for the current and at least the next academic year to be entered in the plugin settings. 
2) By default, Moodle assignments, Turnitin modules and Quizzes are supported, while support for Coursework, Lessons and Workshops may be enabled in the settings.

## Features

- For students the Feedback Tracker provides a one-stop overview of all assessments. It shows the status of submissions and feedback. Students can go from the FT directly to a particular assessment.
- Marker will have a FT on course level which allows to set a feedback due date and add additional information for each assessment in the course.
- Automatic calculation of feedback due dates which may be overridden manually.
- Optional, there is a site wide FT providing information about all assessments a marker is involved in.
- Scheduled export data as Excel file which can be used in Power BI etc. for reports.
- Activities supported: Moodle Assignment, Quiz, LTI, Lesson, Coursework, Workshop and Turnitin Assignment V2 (only with 1 part - most common).
- Works with the [My Feedback block](https://github.com/ucl-isd/moodle-block_my_feedback) to provide feedback information to upcoming activities to students and markers.

## Requirements

- Requires local_assess_type which provides an assessment type (Formative, Summative) for specified activities.
- Requires Turnitin Moodle Direct v2.
- Optional requires block_portico_enrolments in order to be able to enable the FT site report in the settings.
- Requires a course custom field "course_year" which holds the academic year (e.g. "2025") for the course.
- 

## Roadmap
- One key area is to bring in data from LTIs into all of the reports
- 
