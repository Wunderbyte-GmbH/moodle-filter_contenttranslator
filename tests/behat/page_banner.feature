@filter @filter_contenttranslator @local_contenttranslator @javascript
Feature: Machine translation banner and "show original" toggle
  In order to know when I am reading a machine translation and to check the original wording
  As a learner who uses Moodle in another language
  I need a page banner that labels machine translations and lets me switch to the original text

  # The German learner sees the German strings of filter_contenttranslator.
  Background:
    Given the content translator test language "de" is installed
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "users" exist:
      | username  | firstname | lastname | lang |
      | learnerde | Lena      | German   | de   |
      | learneren | Liam      | English  | en   |
    And the following "course enrolments" exist:
      | user      | course | role    |
      | learnerde | C1     | student |
      | learneren | C1     | student |
    And the following "activities" exist:
      | activity | course | name     | content                 |
      | page     | C1     | Page one | <p>Body of page one</p> |
    And the following config values are set as admin:
      | targetlangs | de      | local_contenttranslator |
      | engine      | pseudo  | local_contenttranslator |
      | budgetchars | 1000000 | local_contenttranslator |
      | enableauto  | 0       | local_contenttranslator |
    And the "contenttranslator" filter is "on"
    And the content translator has translated course "C1" into "de"

  Scenario: The banner labels machine translations and the original can be shown and remembered
    Given I log in as "learnerde"
    When I am on the "Page one" "page activity" page
    Then I should see "[de] Body of page one"
    And I should see "Teile dieser Seite wurden automatisch übersetzt."
    When I click on "Original anzeigen" "link" in the ".filter-contenttranslator-banner" "css_element"
    Then I should see "Body of page one"
    And I should not see "[de] Body of page one"
    And I should see "Sie sehen den Originaltext."
    # The choice is remembered for the session.
    When I am on the "Page one" "page activity" page
    Then I should not see "[de] Body of page one"
    And "Übersetzung anzeigen" "link" should exist in the ".filter-contenttranslator-banner" "css_element"
    When I click on "Übersetzung anzeigen" "link" in the ".filter-contenttranslator-banner" "css_element"
    Then I should see "[de] Body of page one"
    And I should see "Teile dieser Seite wurden automatisch übersetzt."

  Scenario: Reviewed translations carry no machine label but keep the toggle
    Given the content translator translations of course "C1" are reviewed
    And I log in as "learnerde"
    When I am on the "Page one" "page activity" page
    Then I should see "[de] Body of page one"
    And I should not see "Teile dieser Seite wurden automatisch übersetzt."
    And "Original anzeigen" "link" should exist in the ".filter-contenttranslator-banner" "css_element"

  Scenario: Users in the source language get no banner
    Given I log in as "learneren"
    When I am on the "Page one" "page activity" page
    Then I should see "Body of page one"
    And I should not see "[de] Body of page one"
    And ".filter-contenttranslator-banner" "css_element" should not exist

  Scenario: Block badge instead of the banner, toggle switched off
    Given the following config values are set as admin:
      | lang_de_badge      | block | local_contenttranslator |
      | showoriginaltoggle | 0     | local_contenttranslator |
    And I log in as "learnerde"
    When I am on the "Page one" "page activity" page
    Then I should see "[de] Body of page one"
    And I should see "Maschinell übersetzt" in the ".ct-translated" "css_element"
    And ".filter-contenttranslator-banner" "css_element" should not exist
