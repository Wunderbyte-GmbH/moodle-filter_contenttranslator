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
use local_contenttranslator\engine\engine_manager;
use local_contenttranslator\item_manager;
use local_contenttranslator\source\registry;
use local_contenttranslator\translation_manager;
use local_contenttranslator\translator;

/**
 * Tests for the render filter.
 *
 * @package    filter_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \filter_contenttranslator\text_filter
 */
final class text_filter_test extends \advanced_testcase {
    /**
     * Filter output for translated, untranslated and source language users.
     */
    public function test_filter(): void {
        global $SESSION, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('targetlangs', 'de', 'local_contenttranslator');
        set_config('engine', 'pseudo', 'local_contenttranslator');
        set_config('budgetchars', 1000000, 'local_contenttranslator');
        set_config('lang_de_badge', 'block', 'local_contenttranslator');
        registry::reset();
        engine_manager::reset();
        cache_helper::purge();
        text_filter::reset();

        $course = $this->getDataGenerator()->create_course(['fullname' => 'Filtered course']);
        $page = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'name' => 'Page name', 'content' => '<p>Body text</p>']
        );
        item_manager::sync_course((int)$course->id);
        $context = \context_module::instance($page->cmid);
        $item = item_manager::find('mod_page', 'page', 'content', (int)$page->id);
        $nameitem = item_manager::find('mod_page', 'page', 'name', (int)$page->id);
        translator::translate_item($item, 'de', budget::TRIGGER_BULK, (int)$USER->id);
        translator::translate_item($nameitem, 'de', budget::TRIGGER_BULK, (int)$USER->id);

        $filter = new text_filter($context, []);

        // English user: untouched.
        $SESSION->forcelang = 'en';
        $this->assertSame('<p>Body text</p>', $filter->filter('<p>Body text</p>'));

        // German user: translation, wrapped with lang attribute and block badge.
        $SESSION->forcelang = 'de';
        $out = $filter->filter('<p>Body text</p>');
        $this->assertStringContainsString('[de]', $out);
        $this->assertStringContainsString('lang="de"', $out);
        $this->assertStringContainsString('ct-badge', $out);
        $this->assertSame('[de] Page name', $filter->filter_stage_string('Page name', []));
        // Unknown text stays.
        $this->assertSame('<p>Nobody knows</p>', $filter->filter('<p>Nobody knows</p>'));

        // French user: source shown, marked with the source language.
        $SESSION->forcelang = 'fr';
        $out = $filter->filter('<p>Body text</p>');
        $this->assertStringContainsString('lang="en"', $out);
        $this->assertStringContainsString('Body text', $out);
        $this->assertSame('Page name', $filter->filter_stage_string('Page name', []));

        // Show original toggle.
        $SESSION->forcelang = 'de';
        $SESSION->filter_contenttranslator_original = true;
        text_filter::reset();
        $this->assertStringNotContainsString('[de]', $filter->filter('<p>Body text</p>'));
        $this->assertSame('Page name', $filter->filter_stage_string('Page name', []));
        unset($SESSION->filter_contenttranslator_original);
        text_filter::reset();

        // Reviewed-only visibility hides machine output; review makes it visible again.
        set_config('lang_de_visibility', 'reviewed', 'local_contenttranslator');
        $this->assertSame('Page name', $filter->filter_stage_string('Page name', []));
        translation_manager::mark_reviewed(translation_manager::get_for_item((int)$nameitem->id, 'de'), (int)$USER->id);
        $this->assertSame('[de] Page name', $filter->filter_stage_string('Page name', []));

        // Deleting the translation invalidates the cache.
        translation_manager::delete(translation_manager::get_for_item((int)$nameitem->id, 'de'), (int)$USER->id);
        $this->assertSame('Page name', $filter->filter_stage_string('Page name', []));
        unset($SESSION->forcelang);
    }
}
