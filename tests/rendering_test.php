<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace filter_contenttranslator;

use local_contenttranslator\budget;
use local_contenttranslator\cache_helper;
use local_contenttranslator\config;
use local_contenttranslator\engine\engine_manager;
use local_contenttranslator\hook\register_engines;
use local_contenttranslator\item_manager;
use local_contenttranslator\scripted_engine;
use local_contenttranslator\source\registry;
use local_contenttranslator\translation_manager;
use local_contenttranslator\translator;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/contenttranslator/tests/fixtures/scripted_engine.php');

/**
 * Render-time behaviour of the filter beyond the basic lookup (REN-02/03/04/05/06/10/11/12, WB-10).
 *
 * @package    filter_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \filter_contenttranslator\text_filter
 * @covers     \local_contenttranslator\api
 */
final class rendering_test extends \advanced_testcase {
    /** @var scripted_engine Engine that must never be called during rendering */
    private scripted_engine $engine;

    /**
     * Common setup: German target, block badges, pseudo engine for the translations themselves.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('targetlangs', 'de', 'local_contenttranslator');
        set_config('engine', 'pseudo', 'local_contenttranslator');
        set_config('budgetchars', 1000000, 'local_contenttranslator');
        set_config('enableauto', 0, 'local_contenttranslator');
        set_config('lang_de_badge', 'block', 'local_contenttranslator');
        $this->engine = new scripted_engine('renderspy');
        $this->redirectHook(register_engines::class, fn(register_engines $hook) => $hook->add_engine($this->engine));
        registry::reset();
        engine_manager::reset();
        cache_helper::purge();
        text_filter::reset();
    }

    /**
     * Clean session state.
     */
    protected function tearDown(): void {
        global $SESSION;
        unset($SESSION->forcelang, $SESSION->filter_contenttranslator_original);
        text_filter::reset();
        parent::tearDown();
    }

    /**
     * Page with translated content; returns [course, page, item, filter].
     *
     * @param string $content
     * @return array
     */
    private function translated_page(string $content = '<p>Body text</p>'): array {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'content' => $content]);
        item_manager::sync_course((int)$course->id);
        $item = item_manager::find('mod_page', 'page', 'content', (int)$page->id);
        translator::translate_item($item, 'de', budget::TRIGGER_BULK, 2);
        $filter = new text_filter(\context_module::instance($page->cmid), []);
        return [$course, $page, $item, $filter];
    }

    /**
     * Render as a user with the given language.
     *
     * @param text_filter $filter
     * @param string $text
     * @param string $lang
     * @return string
     */
    private function render(text_filter $filter, string $text, string $lang): string {
        global $SESSION;
        $SESSION->forcelang = $lang;
        return $filter->filter($text);
    }

    /**
     * Stale translations are shown with their state until updated, or hidden per language (REN-05).
     */
    public function test_stale_translation(): void {
        global $DB;
        [$course, $page, $item, $filter] = $this->translated_page();
        $DB->set_field('page', 'content', '<p>New body text</p>', ['id' => $page->id]);
        item_manager::sync_course((int)$course->id);
        $this->assertSame(translation_manager::STATUS_STALE, translation_manager::get_for_item((int)$item->id, 'de')->status);

        $out = $this->render($filter, '<p>New body text</p>', 'de');
        $this->assertStringContainsString('[de] Body text', $out, 'Previous translation stays visible');
        $this->assertStringContainsString('ct-stale', $out);

        set_config('lang_de_showstale', 0, 'local_contenttranslator');
        cache_helper::purge();
        $out = $this->render($filter, '<p>New body text</p>', 'de');
        $this->assertStringNotContainsString('[de]', $out);
        $this->assertStringContainsString('New body text', $out);
    }

    /**
     * Content with {mlang} is left to the multilang filters unless configured otherwise (REN-06).
     */
    public function test_multilang_content(): void {
        $mlang = '<p>{mlang en}Hello{mlang}{mlang de}Hallo{mlang}</p>';
        [, , , $filter] = $this->translated_page($mlang);
        $this->assertSame($mlang, $this->render($filter, $mlang, 'de'));

        // When translated anyway, {mlang} blocks are protected (ENG-12). Content that is nothing but {mlang}
        // has no text of its own: it reaches the page unchanged and is not marked as translated.
        set_config('skipmultilang', 0, 'local_contenttranslator');
        $out = $this->render($filter, $mlang, 'de');
        $this->assertStringNotContainsString('ct-translated', $out);
        $this->assertStringContainsString($mlang, $out);

        // Text around the blocks is translated, the blocks themselves stay intact.
        $mixed = '<p>Greeting: {mlang en}Hello{mlang}{mlang de}Hallo{mlang}</p>';
        [, , , $filter] = $this->translated_page($mixed);
        $out = $this->render($filter, $mixed, 'de');
        $this->assertStringContainsString('ct-translated', $out);
        $this->assertStringContainsString('{mlang en}Hello{mlang}{mlang de}Hallo{mlang}', $out);
    }

    /**
     * A regional language falls back to its parent language (REN-02).
     */
    public function test_parent_language_fallback(): void {
        global $CFG;
        $langdir = $CFG->dataroot . '/lang/de_du';
        check_dir_exists($langdir);
        file_put_contents($langdir . '/langconfig.php', "<?php\n\$string['thislanguage'] = 'Deutsch (du)';\n"
            . "\$string['parentlanguage'] = 'de';\n");
        get_string_manager()->reset_caches(true);
        try {
            [, , , $filter] = $this->translated_page();
            $out = $this->render($filter, '<p>Body text</p>', 'de_du');
            $this->assertStringContainsString('[de] Body text', $out);
            $this->assertStringContainsString('lang="de"', $out);
        } finally {
            remove_dir($langdir);
            get_string_manager()->reset_caches(true);
        }
    }

    /**
     * Badge per text block only for machine output, never when switched off or reviewed (REN-10);
     * lang attributes can be switched off (REN-12).
     */
    public function test_badge_and_lang_attributes(): void {
        [, , $item, $filter] = $this->translated_page();
        $this->assertStringContainsString('ct-badge', $this->render($filter, '<p>Body text</p>', 'de'));

        set_config('lang_de_badge', 'off', 'local_contenttranslator');
        $this->assertStringNotContainsString('ct-badge', $this->render($filter, '<p>Body text</p>', 'de'));

        set_config('lang_de_badge', 'block', 'local_contenttranslator');
        translation_manager::mark_reviewed(translation_manager::get_for_item((int)$item->id, 'de'), 2);
        $out = $this->render($filter, '<p>Body text</p>', 'de');
        $this->assertStringContainsString('[de]', $out);
        $this->assertStringNotContainsString('ct-badge', $out, 'Reviewed text carries no machine label');

        $this->assertStringContainsString('lang="en"', $this->render($filter, '<p>Body text</p>', 'fr'));
        set_config('langattributes', 0, 'local_contenttranslator');
        $this->assertSame('<p>Body text</p>', $this->render($filter, '<p>Body text</p>', 'fr'));
    }

    /**
     * "Show original" is switched by the ctoriginal URL parameter and remembered in the session (REN-11).
     */
    public function test_show_original_parameter(): void {
        global $PAGE, $SESSION;
        [, $page, , $filter] = $this->translated_page();
        $context = \context_module::instance($page->cmid);

        $_GET['ctoriginal'] = 1;
        text_filter::reset();
        $filter->setup($PAGE, $context);
        unset($_GET['ctoriginal']);
        $this->assertTrue($SESSION->filter_contenttranslator_original);
        $this->assertStringNotContainsString('[de]', $this->render($filter, '<p>Body text</p>', 'de'));

        text_filter::reset();
        $filter->setup($PAGE, $context);
        $this->assertStringNotContainsString('[de]', $this->render($filter, '<p>Body text</p>', 'de'), 'Remembered');

        $_GET['ctoriginal'] = 0;
        text_filter::reset();
        $filter->setup($PAGE, $context);
        unset($_GET['ctoriginal']);
        $this->assertStringContainsString('[de]', $this->render($filter, '<p>Body text</p>', 'de'));

        set_config('showoriginaltoggle', 0, 'local_contenttranslator');
        $_GET['ctoriginal'] = 1;
        text_filter::reset();
        $filter->setup($PAGE, $context);
        unset($_GET['ctoriginal']);
        $this->assertStringContainsString('[de]', $this->render($filter, '<p>Body text</p>', 'de'), 'Toggle disabled');
    }

    /**
     * The "show original" toggle is only offered when real content was translated (filter()); a course or
     * activity name matching alone (filter_stage_string()) must not raise it, or it would appear on pages
     * with nothing translatable of their own, such as the site administration (GH-2387 follow-up).
     */
    public function test_banner_only_for_content(): void {
        global $SESSION;
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id, 'name' => 'Page name', 'content' => '<p>Body text</p>',
        ]);
        item_manager::sync_course((int)$course->id);
        $contentitem = item_manager::find('mod_page', 'page', 'content', (int)$page->id);
        $nameitem = item_manager::find('mod_page', 'page', 'name', (int)$page->id);
        translator::translate_item($contentitem, 'de', budget::TRIGGER_BULK, 2);
        translator::translate_item($nameitem, 'de', budget::TRIGGER_BULK, 2);
        $filter = new text_filter(\context_module::instance($page->cmid), []);

        text_filter::reset();
        $this->render($filter, '<p>Body text</p>', 'de');
        $this->assertSame(1, text_filter::banner_request_count(), 'Translated content requests the banner');

        text_filter::reset();
        $SESSION->forcelang = 'de';
        $filter->filter_stage_string('Page name', []);
        $this->assertSame(0, text_filter::banner_request_count(), 'A translated name alone does not');
    }

    /**
     * Without the capability, a user cannot switch to the source text even via the ctoriginal URL parameter
     * (GH-2387 follow-up): the button is not just hidden, the action itself is blocked.
     */
    public function test_showoriginal_requires_capability(): void {
        global $PAGE, $SESSION;
        [, $page, , $filter] = $this->translated_page();
        $context = \context_module::instance($page->cmid);
        $this->setUser($this->getDataGenerator()->create_user());

        $_GET['ctoriginal'] = 1;
        text_filter::reset();
        $filter->setup($PAGE, $context);
        unset($_GET['ctoriginal']);

        $this->assertTrue(empty($SESSION->filter_contenttranslator_original), 'No capability, no toggle via URL');
        $this->assertStringContainsString('[de]', $this->render($filter, '<p>Body text</p>', 'de'));
    }

    /**
     * Guests and visitors see translations too (WB-10).
     */
    public function test_guest(): void {
        [, , , $filter] = $this->translated_page();
        $this->setGuestUser();
        $this->assertStringContainsString('[de] Body text', $this->render($filter, '<p>Body text</p>', 'de'));
    }

    /**
     * Per-course visibility override: reviewed-only in one course does not affect another (REN-04, AUTO-02).
     */
    public function test_course_visibility_override(): void {
        [$course1, , $item1, $filter1] = $this->translated_page('<p>First course text</p>');
        [, , , $filter2] = $this->translated_page('<p>Second course text</p>');
        config::save_override('course', (int)$course1->id, ['visibility' => config::VISIBILITY_REVIEWED]);
        cache_helper::purge();

        $this->assertStringNotContainsString('[de]', $this->render($filter1, '<p>First course text</p>', 'de'));
        $this->assertStringContainsString('[de]', $this->render($filter2, '<p>Second course text</p>', 'de'));
        translation_manager::mark_reviewed(translation_manager::get_for_item((int)$item1->id, 'de'), 2);
        $this->assertStringContainsString('[de]', $this->render($filter1, '<p>First course text</p>', 'de'));
    }

    /**
     * Rendering never calls an engine, even for known text without translation (REN-03).
     */
    public function test_rendering_never_calls_engines(): void {
        set_config('engine', 'renderspy', 'local_contenttranslator');
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'content' => '<p>Untranslated</p>']);
        item_manager::sync_course((int)$course->id);
        $filter = new text_filter(\context_module::instance($page->cmid), []);

        $out = $this->render($filter, '<p>Untranslated</p>', 'de');
        $this->assertStringContainsString('Untranslated', $out);
        $this->assertSame('Untranslated page', $filter->filter_stage_string('Untranslated page', []));
        $this->assertCount(0, $this->engine->calls);
    }
}
