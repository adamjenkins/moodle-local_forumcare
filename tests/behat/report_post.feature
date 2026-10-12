@local @local_forumcare @javascript
Feature: Report a forum post
  In order to flag a problem post to the moderators without mistaking it for a reply
  As a student
  I need a clearly marked "Report this post" link and report dialog

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | student2 | Jordan    | Blake    |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
      | student2 | C1     | student |
    And the following "activities" exist:
      | activity | name       | course | idnumber |
      | forum    | Care forum | C1     | forum1   |
    And the following "local_forumcare > forums" exist:
      | forum  |
      | forum1 |
    And the following "local_forumcare > reasons" exist:
      | name               |
      | Offensive language |
    And the following "mod_forum > discussions" exist:
      | user     | forum  | name      | message                                |
      | student2 | forum1 | Rude post | Some people in this group are useless. |

  Scenario: The report link comes first and the dialog says it is a report, not a reply
    Given I am on the "Care forum" "forum activity" page logged in as student1
    And I follow "Rude post"
    Then "Report this post" "link" should appear before "Permalink" "link"
    And "Report this post" "link" should appear before "Reply" "link"
    When I click on "Report this post" "link"
    Then I should see "You are reporting this post" in the ".modal-dialog" "css_element"
    And I should see "It is not a reply and will not appear in the forum" in the ".modal-dialog" "css_element"
    And I set the field "Reason for reporting" to "Offensive language"
    And I set the field "Additional details (optional)" to "A personal attack on the group"
    And I click on "Report post" "button" in the ".modal-footer" "css_element"
    And I should see "Your report has been submitted"
    And I reload the page
    And I should see "Post reported"
    And "Report this post" "link" should not exist

  Scenario: A student cannot report their own post
    Given the following "mod_forum > posts" exist:
      | user     | parentsubject | subject       | message                  |
      | student1 | Rude post     | Re: Rude post | That is not fair at all. |
    And I am on the "Care forum" "forum activity" page logged in as student2
    When I follow "Rude post"
    # The link on student1's reply shows the links have been injected before the own post is checked.
    Then "Report this post" "link" should exist in the ".forumpost:not(.firstpost)" "css_element"
    And "Report this post" "link" should not exist in the ".forumpost.firstpost" "css_element"
